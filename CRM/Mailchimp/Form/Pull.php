<?php
/**
 */

class CRM_Mailchimp_Form_Pull extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-pull';
  const END_URL    = 'civicrm/mailchimp/pull';
  const END_PARAMS = 'state=done';
  const BATCH_COUNT = 10;

  function preProcess() {
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');

      $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only=TRUE);
      if (!$groups) {
        return;
      }

      $output_stats = array();
      foreach ($groups as $group_id => $details) {
        $list_stats = $stats[$details['list_id']];
        $output_stats[] = array(
          'name' => $details['civigroup_title'],
          'stats' => $list_stats,
        );
      }
      $this->assign('stats', $output_stats);
    }
  }

  public function buildQuickForm() {
    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Import'),
      ),
    );
    // Add the Buttons.
    $this->addButtons($buttons);
  }

  public function postProcess() {
    $setting_url = CRM_Utils_System::url('civicrm/mailchimp/settings', 'reset=1',  TRUE, NULL, FALSE, TRUE);
    $runner = self::getRunner();
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to pull. Make sure mailchimp settings are configured in the <a href='.$setting_url.'>setting page</a>.'));
    }
  }

  static function getRunner() {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // reset pull stats.
    CRM_Core_BAO_Setting::setItem(Array(), CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
    $stats = array();

    // We need to process one list at a time.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only=TRUE);
    if (!$groups) {
      // Nothing to do.
      return FALSE;
    }
    // Each list is a task.
    $listCount = 1;
    foreach ($groups as $group_id => $details) {
      $stats[$details['list_id']] = array(
        'mc_count' => 0,
        'c_count' => 0,
        'in_sync' => 0,
        'added' => 0,
        'removed' => 0,
      ) ;

      $identifier = "List " . $listCount++ . " " . $details['civigroup_title'];

      $task  = new CRM_Queue_Task(
        array ('CRM_Mailchimp_Form_Pull', 'syncPullList'),
        array($details['list_id'], $identifier),
        "Preparing queue for $identifier"
      );

      // Add the Task to the Queue
      $queue->createItem($task);
    }
    // Setup the Runner
    $runner = new CRM_Queue_Runner(array(
      'title' => ts('Import From Mailchimp'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
    ));
    static::updatePullStats($stats);
    return $runner;
  }

  static function syncPullList(CRM_Queue_TaskContext $ctx, $listID, $identifier) {
    // Add the Mailchimp collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPullCollectMailchimp'),
      array($listID),
      "$identifier: Fetching data from Mailchimp (can take a mo)"
    ));

    // Add the CiviCRM collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPullCollectCiviCRM'),
      array($listID),
      "$identifier: Fetching data from CiviCRM"
    ));

    // Remaining people need something updating.
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPullUpdates'),
      array($listID),
      "$identifier: Updating contacts in CiviCRM"
    ));

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Mailchimp data into temporary working table.
   */
  static function syncPullCollectMailchimp(CRM_Queue_TaskContext $ctx, $listID) {

    // Shared process.
    $count = CRM_Mailchimp_Form_Sync::syncCollectMailchimp($listID);
    static::updatePullStats(array( $listID => array('mc_count'=>$count)));

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  static function syncPullCollectCiviCRM(CRM_Queue_TaskContext $ctx, $listID) {

    // Shared process.
    $stats[$listID]['c_count'] =  CRM_Mailchimp_Form_Sync::syncCollectCiviCRM($listID);

    // Remove identicals
    $stats[$listID]['in_sync'] = CRM_Mailchimp_Form_Sync::syncIdentical();

    static::updatePullStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * New contacts from Mailchimp need bringing into CiviCRM.
   */
  static function syncPullUpdates(CRM_Queue_TaskContext $ctx, $listID) {
    // Prepare the groups that we need to update

    // We need to know what groupings we have maps to.
    // We only care about CiviCRM groups that are mapped to this MC List:
    $membership_group_id = FALSE;
    $updatable_grouping_groups = array();
    foreach (CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID) as $groupID=>$details) {
      if (!$details['grouping_id']) {
        $membership_group_id = $groupID;
      }
      else {
        // if $details['is_mc_update_grouping'] @todo
        $updatable_grouping_groups[$groupID] = $details;
      }
    }

    // all Mailchimp table
    $dao = CRM_Core_DAO::executeQuery( "SELECT m.*, c.groupings c_groupings
      FROM tmp_mailchimp_push_m m
      LEFT JOIN tmp_mailchimp_push_c c ON m.email = c.email
      ;");

    // Loop the $dao object creating/finding contacts in CiviCRM.
    while ($dao->fetch()) {
      $params = array(
        'FNAME' => $dao->first_name,
        'LNAME' => $dao->last_name,
        'EMAIL' => $dao->email,
      );
      // Update/create contact.
      $contact_id = CRM_Mailchimp_Utils::updateContactDetails($params);

      // Ensure the contact is in the membership group.
      if (!$dao->c_groupings) {
        // This contact was not found in the CiviCRM table.
        // Therefore they are not in the membership group.
        // (actually they could have an email problem as well, but that's OK).
        // Add them into the membership group.
        $groupContact[$membership_group_id][] = $contact_id;
        $civi_groupings = array();
        $stats[$listID]['added']++;
      }
      else {
        // unpack the group membership from CiviCRM.
        $civi_groupings = unserialize($dao->c_groupings);
      }
      // unpack the group membership reported by MC
      $mc_groupings = unserialize($dao->groupings);

      // Now sort out the grouping_groups for those we are supposed to allow updates for
      foreach ($updatable_grouping_groups as $groupID=>$details) {
        // Should this person be in this grouping:group according to MC?
        if (!empty($mc_groupings[ $details['grouping_id'] ][ $details['group_id'] ])) {
          // They should be in this group.
          if (empty($civi_groupings[ $details['grouping_id'] ][ $details['group_id'] ])) {
            // But they're not! Plan to add them in.
            $groupContact[$groupID][] = $contact_id;
          }
        }
        else {
          // They should NOT be in this group.
          if (!empty($civi_groupings[ $details['grouping_id'] ][ $details['group_id'] ])) {
            // But they ARE. Plan to remove them.
            $groupContactRemoves[$groupID][] = $contact_id;
          }
        }
      }
    }


    // And now, what if a contact is not in the Mailchimp list? We must remove them from the membership group.
    $dao = CRM_Core_DAO::executeQuery( "SELECT c.contact_id
      FROM tmp_mailchimp_push_c c
      WHERE NOT EXISTS (
        SELECT m.email FROM tmp_mailchimp_push_m m WHERE m.email=c.email
      );");
    // Loop the $dao object creating/finding contacts in CiviCRM.
    while ($dao->fetch()) {
      $groupContactRemoves[$membership_group_id][] =$dao->contact_id;
      $stats[$listID]['removed']++;
    }

    if ($groupContact) {
      // We have some contacts to add into groups...
      foreach($groupContact as $groupID => $contactIDs ) {
        CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $groupID, 'Admin', 'Added');
      }
    }

    if ($groupContactRemoves) {
      // We have some contacts to add into groups...
      foreach($groupContactRemoves as $groupID => $contactIDs ) {
        CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactIDs, $groupID, 'Admin', 'Added');
      }
    }

    // Finally, finish up by removing the two temporary tables
    CRM_Core_DAO::executeQuery("DROP TABLE tmp_mailchimp_push_m;");
    CRM_Core_DAO::executeQuery("DROP TABLE tmp_mailchimp_push_c;");

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Update the pull stats setting.
   */
  static function updatePullStats($updates) {
    $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
    foreach ($updates as $listId=>$settings) {
      foreach ($settings as $key=>$val) {
        $stats[$listId][$key] = $val;
      }
    }
    CRM_Core_BAO_Setting::setItem($stats, CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
  }

  // old ....

  /** 
   */
  static function syncLists(CRM_Queue_TaskContext $ctx, $listid) {

    $apikey         = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    $contactColumns = array();
    $contacts       = array();
    $dc             = 'us'.substr($apikey, -1);
    $url            = 'http://'.$dc.'.api.mailchimp.com/export/1.0/list?apikey='.$apikey.'&id='.$listid;
    $json           = file_get_contents($url);
    $temp           = explode("\n", $json);
    array_pop($temp);
    foreach($temp as $key => $value){
      if($key == 0){
        $contactColumns = json_decode($value,TRUE);
        continue;
      }
      $data = json_decode($value,TRUE);
      $contacts[$listid][] = array_combine($contactColumns, $data);
    }

    $lists            = array();
    $listmembercount  = array();
    $listmembercount  = civicrm_api('Mailchimp' , 'getmembercount' , array('version' => 3));
    $lists            = civicrm_api('Mailchimp' , 'getlists' , array('version' => 3));
    $mcGroups         = civicrm_api('Mailchimp' , 'getgroupid' , array('version' => 3,'id' => $listid));
    // get member count
    $count  = $listmembercount['values'][$listid];

    // Set the Number of Rounds
    $rounds = ceil($count/self::BATCH_COUNT);

    // Setup a Task in the Queue
    $i = 0;
    while ($i < $rounds) {
      $start   = $i * self::BATCH_COUNT;
      $contactsarray  = array_slice($contacts[$listid], $start, self::BATCH_COUNT, TRUE);
      $counter = ($rounds > 1) ? ($start + self::BATCH_COUNT) : $count;
      $task    = new CRM_Queue_Task(
        array('CRM_Mailchimp_Form_Pull', 'syncContacts'),
        array($listid, array($contactsarray), array($mcGroups)),
        "Pulling '{$lists['values'][$listid]}' - Contacts {$counter} of {$count}"
      );

      // Add the Task to the Queu
      $ctx->queue->createItem($task);
      $i++;
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Import name, email and groupings data from Mailchimp.
   *
   * All Mailchimp lists must have a grouping field set up;
   * All groupings set up in Mailchimp should have a coresponding mapped group in CiviCRM.
   *
   * @todo this is an add-only operation; if someone is REMOVED from a group at MC end,
   * this sync function will NOT remove them from the CiviCRM group.
   */
  static function syncContacts(CRM_Queue_TaskContext $ctx, $listid, $contactsarray, $mcGroups) {
    file_put_contents('/tmp/sos-mc' .time() , print_r(array('listid' => $listid, 'contactsarray' => $contactsarray,'mcGroups'=>$mcGroups),1));

    $groupContact   = array();
    $mcGroups       = array_shift($mcGroups);
    $contactsarray  = array_shift($contactsarray);

    if(empty($mcGroups)) {
      // Groupings are required.
      return FALSE;
    }

    // Create an index of [mcGroupingName][mcGroupName] = civiGroupId.
    $problems = array();
    foreach ($mcGroups['values'] as $_) {
      $civiGroupId = CRM_Mailchimp_Utils::getGroupIdForMailchimp($listid, $_['groupingid'] , $_['groupid']);
      $mcGroupings[$_['groupingname']][$_['groupname']] = $civiGroupId;
      if (!$civiGroupId) {
        $problems[] = "$_[groupingname]:$_[groupname] does not have a mapped group in CiviCRM.";
      }
    }
    if ($problems) {
      CRM_Core_Session::setStatus(implode("<br />\n",$problems), ts("Mailchimp/CiviCRM groups mismatch"));
    }

    // Loop the contacts from Mailchimp.
    foreach($contactsarray as $key => $contact) {

      // Update basic info in CiviCRM and get the CiviCRM ContactId.
      $updateParams = array(
        'EMAIL' =>  $contact['Email Address'],
        'FNAME' =>  $contact['First Name'],
        'LNAME' =>  $contact['Last Name'],
      );
      $contactID    = CRM_Mailchimp_Utils::updateContactDetails($updateParams);
      if(empty($contactID)) {
        // We were unable to create/update a contact in CiviCRM.
        // (Should not happen, but just in case. If it were to happen, should
        // include a status date as for added/updated.)
        continue;
      }
      if(!empty($updateParams)) {
        if($updateParams['status']['Added'] == 1) {
          $setting  = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
          CRM_Core_BAO_Setting::setItem(array('Added' => (1 + $setting['Added']), 'Updated' => $setting['Updated']),
            CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats'
          );
        }
        if($updateParams['status']['Updated'] == 1) {
          $setting  = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
          CRM_Core_BAO_Setting::setItem(array('Updated' => (1 + $setting['Updated']), 'Added' => $setting['Added']),
            CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
        }
      }

      // Loop the defined MC groupings.
      foreach ($mcGroupings as $mcGroupingName=>$mcGroupVals) {

        // Does the MC contact have any groups in this grouping?
        if (!empty($contact[$mcGroupingName])) {

          // This contact has at least one value in this grouping.
          // These come comma separated.
          foreach (explode(',', $contact[$mcGroupingName]) as $groupName) {
            $groupName = trim($groupName);

            if (!empty($mcGroupVals[$groupName])) {
              // We have a CiviCRM group mapped to this grouping:groupname
              // Add this contact to the list of which contacts should be in which CiviCRM groups.
              $groupContact[$mcGroupVals[$groupName]][]  = $contactID;
            }
          }
        }
      }
    }

    // Add all the contacts to the CiviCRM groups.
    foreach($groupContact as $groupID => $contactIDs ){
      CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $groupID, 'Admin', 'Added');
    }

    return CRM_Queue_Task::TASK_SUCCESS;
  }
}
