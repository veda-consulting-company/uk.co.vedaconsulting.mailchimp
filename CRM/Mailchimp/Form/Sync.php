<?php

class CRM_Mailchimp_Form_Sync extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-sync';
  const END_URL    = 'civicrm/mailchimp/sync';
  const END_PARAMS = 'state=done';
  const BATCH_COUNT = 10;
  
  /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() {
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $stats = CRM_Mailchimp_BAO_MCSync::getSyncStats();
      $this->assign('stats', $stats);
    }
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Sync Contacts'),
      ),
    );

    // Add the Buttons.
    $this->addButtons($buttons);
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    $runner = self::getRunner();
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to sync. Make sure mailchimp settings are configured for the groups with enough members.'));
    }
  }

  static function getRunner() {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // We need to process one list at a time.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync();
    if (!$groups) {
      // Nothing to do.
      return FALSE;
    }
    // Make an array mapping unique list_ids to arrays of groupIDs using that list
    $lists = array();
    foreach ($groups as $groupID => $groupVals) {
      $lists[$groupVals['list_id']][$groupID] = $groupVals;
    }

    // Each list is a task.
    $listCount = 1;
    foreach ($lists as $list_id => $groupIDs) {

      $identifier = "List " . $listCount++ . " (id $list_id)";

      $task  = new CRM_Queue_Task(
        array ('CRM_Mailchimp_Form_Sync', 'syncPushList'),
        array($list_id, $groups, $identifier),
        "Preparing queue for $identifier"
      );

      // Add the Task to the Queue
      $queue->createItem($task);
    }

    // Setup the Runner
    $runner = new CRM_Queue_Runner(array(
      'title' => ts('Mailchimp Sync: CiviCRM to Mailchimp'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
    ));

    // reset sync table
    CRM_Mailchimp_BAO_MCSync::resetTable();

    return $runner;
  }

  /**
   * Set up (sub)queue for syncing a Mailchimp List.
   */
  static function syncPushList(CRM_Queue_TaskContext $ctx, $listID, $groups, $identifier) {

    // Split the work into parts:
    // @todo 'force' method not implemented here.

    // Add the Mailchimp collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushCollectMailchimp'),
      array($listID, $groups, $listDelta),
      "$identifier: Fetching data from Mailchimp (can take a mo)"
    ));

    // Add the CiviCRM collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushCollectCiviCRM'),
      array($listID, $groups, $listDelta),
      "$identifier: Fetching data from CiviCRM"
    ));


    // Add the removals task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushRemove'),
      array($listID, $groups, $listDelta),
      "$identifier: Removing those who should no longer be subscribed"
    ));

    // Add the removals task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushAdd'),
      array($listID, $groups, $listDelta),
      "$identifier: Adding new subscribers and updating existing data changes"
    ));

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Mailchimp data into temporary working table.
   */
  static function syncPushCollectMailchimp(CRM_Queue_TaskContext $ctx, $listID, $groups, $identifier) {
    // Create a temporary table.
    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.

    $dao = CRM_Core_DAO::executeQuery(
      "CREATE TABLE tmp_mailchimp_push_m (
        email VARCHAR(200),
        hash CHAR(32),
        PRIMARY KEY (email, hash));");
    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_m VALUES(?, ?)');

    // we need to know the grouping and respective group names since these are identifiable in the export data.
    $groupings = array();
    foreach (CRM_Mailchimp_Utils::getMCInterestGroupings($listID) as $groupingID=>$grouping) {
      // get names from groups
      $groups = array();
      foreach ($grouping['groups'] as $group) {
        $groups[] = $group['name'];
      }
      $groupings[$grouping['name']] = $groups;
    }

    // Prepare to access Mailchimp export API
    // Code structure from http://apidocs.mailchimp.com/export/1.0/list.func.php
    // Example  (spacing added)
    //  ["Email Address"  , "First Name" , "Last Name"  , "CiviCRM"          , "MEMBER_RATING" , "OPTIN_TIME" , "OPTIN_IP" , "CONFIRM_TIME"        , "CONFIRM_IP"  , "LATITUDE" , "LONGITUDE" , "GMTOFF" , "DSTOFF" , "TIMEZONE" , "CC" , "REGION" , "LAST_CHANGED"        , "LEID"      , "EUID"       , "NOTES"]
    //  ["f2@example.com" , "Fred"       , "Flintstone" , "general, special" , 2               , ""           , null       , "2014-09-11 19:57:53" , "212.x.x.x"   , null       , null        , null     , null     , null       , null , null     , "2014-09-11 20:02:26" , "180020969" , "884d72639d" , null]
    $apiKey   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    // The datacentre is usually appended to the apiKey after a hyphen.
    $dataCentre = 'us1'; // default.
    if (preg_match('/-(.+)$/', $apiKey, $matches)) {
      $dataCentre = $matches[1];
    }
    $url = "https://$dataCentre.api.mailchimp.com/export/1.0/list?apikey=$apiKey&id=$listID";

    $chunk_size = 4096; //in bytes
    $handle = @fopen($url,'r');
    if (!$handle) {
      // @todo not sure a vanilla exception is best?
      throw new \Exception("Failed to access Mailchimp export API");
    }
    else {
      $i = 0;
      $header = array();
      while (!feof($handle)) {
        $buffer = fgets($handle, $chunk_size);
        if (trim($buffer)!=''){
          $obj = json_decode($buffer);
          if ($i==0){
            // Header row.
            // This will vary depending on how the list is setup.
            // We need to know the indexes of our groupings.
            $header = $obj;
            foreach (array_keys($groupings) as $grouping_name) {
              $grouping_index[$grouping_name] = array_search($grouping_name, $header);
            }
            // It's important that we do things in a predictable order or hashes won't match.
            ksort($grouping_index);

          } else {
            // We need to store the email address and a hash of all the other data.
            $email = $obj[0];
            // email, first name, last name
            $data_to_hash = "$email|$obj[1]|$obj[2]";
            foreach ($grouping_index as $idx) {
              // ensure values are in a known order, for comparison's sake.
              $values = explode(', ', $obj[$idx]);
              asort($values);
              $data_to_hash .= "|" . implode(', ', $values);
            }
            $hash = md5($data_to_hash);
            // run insert prepared statement
            $db->execute($insert, array($email, $hash));
          }
          $i++;
        }
      }
      fclose($handle);
    }

    // We don't need this any more.
    $db->freePrepared($insert);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  static function syncPushCollectCiviCRM(CRM_Queue_TaskContext $ctx, $listID, $groups, $identifier) {

    // To create the table we need to know what grouping fields are in use by
    // the CiviCRM's groups that use this List.
    $fields = array();
    $groupings = CRM_Mailchimp_Utils::getMCInterestGroupings($listID);
    foreach ($groupings as $groupingId=>$grouping) {
      $fields[] = "`$grouping[name]` VARCHAR(1024)";
    }
    $fields = implode(', ', $fields);

    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.
    //
    // @todo: this assumes that a grouping name is OK as a column name. Is this safe?
    // otherwise we can use 'grouping' . groupingId and have a map.
    CRM_Core_DAO::executeQuery("CREATE TABLE tmp_mailchimp_push_c (
      email VARCHAR(200),
      first_name VARCHAR(100),
      last_name VARCHAR(100),
      hash CHAR(32),
      $fields
    )");

    // @todo Here we need to find all contacts in all groups (smart and fixed) that are mapped to this LIST.
    // We need to add the data in taking care to order the grouping fields the right way
    // when creating the hash so they match the Mailchimp hash if all the same.


    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Remove contacts that are subscribed at Mailchimp but not in our list.
   *
   * This also removes from the temporary tables those records that do not need processing.
   */
  static function syncPushRemove(CRM_Queue_TaskContext $ctx, $listID, $groups, $identifier) {
$x=1;
    // Delete records have the same hash - these do not need an update.
    CRM_Core_DAO::executeQuery(
      "DELETE m, c
       FROM tmp_mailchimp_push_m m
       INNER JOIN tmp_mailchimp_push_c c ON m.email = c.email AND m.hash = c.hash;");

    // Now identify those that need removing from Mailchimp.
    // @todo implement the delete option, here just the unsubscribe is implemented.
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT m.email
       FROM tmp_mailchimp_push_m m
       WHERE NOT EXISTS (
         SELECT email FROM tmp_mailchimp_push_c c WHERE c.email = m.email
       );");

    // @todo loop the $dao object to make a list of emails to unsubscribe|delete from MC
    //

    // Finally we can delete the emails that we just processed from the mailchimp temp table.
    CRM_Core_DAO::executeQuery(
      "DELETE FROM tmp_mailchimp_push_m
       WHERE NOT EXISTS (
         SELECT email FROM tmp_mailchimp_push_c c WHERE c.email = tmp_mailchimp_push_m.email
       );");

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Batch update Mailchimp with new contacts that need to be subscribed, or have changed data.
   *
   * This also does the clean-up tasks of removing the temporary tables.
   */
  static function syncPushAdd(CRM_Queue_TaskContext $ctx, $listID, $groups, $identifier) {

    // @todo take the remaining details from tmp_mailchimp_push_c
    // and construct a batchUpdate (do they need to be batched into 1000s? I can't recal).
    //
    // ...

    // Finally, finish up by removing the two temporary tables
    CRM_Core_DAO::executeQuery("DROP TABLE tmp_mailchimp_push_m;");
    CRM_Core_DAO::executeQuery("DROP TABLE tmp_mailchimp_push_c;");

    return CRM_Queue_Task::TASK_SUCCESS;
  }


  // the following code will not be used but I've not deleted it yet as it may have copy-and-paste-able stuff in!
  static function syncGroups(CRM_Queue_TaskContext $ctx, $groupID) {
    // get member count
    $count  = CRM_Mailchimp_Utils::getMemberCountForGroupsToSync(array($groupID));

    // Set the Number of Rounds
    $rounds = ceil($count/self::BATCH_COUNT);

    // Get group info for display
    $groupInfo = CRM_Mailchimp_Utils::getGroupsToSync(array($groupID));

    // Setup a Task in the Queue
    $i = 0;
    while ($i < $rounds) {
      $start   = $i * self::BATCH_COUNT;
      $counter = ($rounds > 1) ? ($start + self::BATCH_COUNT) : $count;
      $task    = new CRM_Queue_Task(
        array('CRM_Mailchimp_Form_Sync', 'syncContacts'),
        array($groupID, $start),
        "Syncing '{$groupInfo[$groupID]['civigroup_title']}' - Contacts {$counter} of {$count}"
      );

      // Add the Task to the Queu
      $ctx->queue->createItem($task);
      $i++;
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Run the From
   *
   * @access public
   *
   * @return TRUE
   */
  static function syncContacts(CRM_Queue_TaskContext $ctx, $groupID, $start) {    
    if (!empty($groupID)) {
      $mcGroups  = CRM_Mailchimp_Utils::getGroupsToSync(array($groupID));
      $emailToIDs       = array();
      $toSubscribe      = array();        
      $toUnsubscribe    = array();
      $toDeleteEmailIDs = array();
      $groupings        = array();
      
      if(!empty($mcGroups)) {
        // Loop contacts in this group, gathering those with an available email
        // to subscribe them to the MC List and group.
        // Any who have an email, but have a opt-out/do-not-mail/on-hold flag set
        // are gathered for deletion from MC.
        $groupContact = CRM_Mailchimp_Utils::getGroupContactObject($groupID, $start);

        while ($groupContact->fetch()) {
          $contact = new CRM_Contact_BAO_Contact();
          $contact->id = $groupContact->contact_id;
          $contact->is_deleted = 0;
          $contact->find(TRUE);

          $email = new CRM_Core_BAO_Email();
          $email->contact_id = $groupContact->contact_id;
          $email->is_primary = TRUE;
          $email->find(TRUE);

          $listID      = $mcGroups[$groupContact->group_id]['list_id'];
          $groupName   = $mcGroups[$groupContact->group_id]['group_name'];
          $groupID     = $mcGroups[$groupContact->group_id]['group_id'];
          $groupingID  = $mcGroups[$groupContact->group_id]['grouping_id'];
          if ($groupingID && $groupName) {
            $groupings = array(
                array(
                  'id'     => $groupingID,
                  'groups' => array($groupName)
                )
              );
          }

          if ($email->email &&
            ($contact->is_opt_out   == 0 &&
            $contact->do_not_email  == 0 &&
            $contact->is_deleted    == 0 &&
            $email->on_hold         == 0)
          ) {
            $toSubscribe[$listID]['batch'][] = array(
              'email'       => array('email' => $email->email),
              'merge_vars'  => array(
                'fname'     => $contact->first_name,
                'lname'     => $contact->last_name,
                'groupings' => $groupings,
              ),
            );
          }

          elseif ($email->email &&
            ($contact->is_opt_out   == 1 ||
             $contact->do_not_email == 1 ||
             $email->on_hold        == 1)
          ) {
            $toDeleteEmailIDs[] = $email->id;
          }

          if ($email->id) {
            $emailToIDs["{$email->email}"]['id'] = $email->id;
            $emailToIDs["{$email->email}"]['group'] = $groupID ? $groupID : "null";
          }
        }
        $toUnsubscribe  = CRM_Mailchimp_Utils::deleteMCEmail($toDeleteEmailIDs);

        foreach ($toSubscribe as $listID => $vals) {
          // sync contacts using batchsubscribe
          $mailchimp = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
          $results   = $mailchimp->batchSubscribe( 
            $listID,
            $vals['batch'], 
            FALSE,
            TRUE, 
            FALSE
          );          

          // fill sync table based on response
          foreach (array('adds', 'updates', 'errors') as $key) {
            foreach ($results[$key] as $data) {
              $email  = $key == 'errors' ? strtolower($data['email']['email']) : strtolower($data['email']);
              $params = array(
                'email_id'   => $emailToIDs[$email]['id'],
                'mc_list_id' => $listID,
                'mc_group'   => $emailToIDs[$email]['group'],
                'mc_euid'    => CRM_Utils_Array::value('euid',$data),
                'mc_leid'    => CRM_Utils_Array::value('leid',$data),
                'sync_status' => $key == 'adds' ? 'Added' : ( $key == 'updates' ? 'Updated' : 'Error')
              );
              CRM_Mailchimp_BAO_MCSync::create($params);          
            }
          }
      }
    }
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }
}
