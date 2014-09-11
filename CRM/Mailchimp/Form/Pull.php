<?php

class CRM_Mailchimp_Form_Pull extends CRM_Core_Form {
  
  const QUEUE_NAME = 'mc-pull';
  const END_URL    = 'civicrm/mailchimp/pull';
  const END_PARAMS = 'state=done';
  const BATCH_COUNT = 10;
  
  function preProcess() {
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $stats = array();
      $listmembercount  = civicrm_api('Mailchimp' , 'getmembercount' , array('version' => 3));
      $total= array_sum($listmembercount['values']);
      $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
      $ignored = $total - array_sum($stats);
      $stats['Total'] = $total;
      $stats['Ignored'] = $ignored;
      $this->assign('stats', $stats);
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
     
    $lists  = array();
    $lists  = civicrm_api('Mailchimp' , 'getlists' , array('version' => 3));

    foreach ($lists['values'] as $listid => $listname) {
      $task = new CRM_Queue_Task(
        array('CRM_Mailchimp_Form_Pull', 'syncLists'),
        array($listid),
        "Preparing queue for '{$listname}'"
      );
        
      // Add the Task to the Queu
      $queue->createItem($task);
    }

    if (!empty($lists['values'])) {
      // Setup the Runner
      $runner = new CRM_Queue_Runner(array(
        'title' => ts('Import From Mailchimp'),
        'queue' => $queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
        'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
      ));
      $query = "UPDATE civicrm_setting SET value = NULL WHERE name = 'pull_stats'"; 
      CRM_Core_DAO::executeQuery($query);
      return $runner;
    }
    return FALSE;
  }
  
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
    foreach ($mcGroups['values'] as $_) {
      $mcGrouping[$_['groupingname']][$_['groupname']] = CRM_Mailchimp_Utils::getGroupIdForMailchimp($listid, $_['groupingid'] , $_['groupid']);
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
