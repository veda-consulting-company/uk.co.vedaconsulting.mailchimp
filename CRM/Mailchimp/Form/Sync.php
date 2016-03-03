<?php
/**
 * @file
 * This provides the Sync Push from CiviCRM to Mailchimp form.
 */

class CRM_Mailchimp_Form_Sync extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-sync';
  const END_URL    = 'civicrm/mailchimp/sync';
  const END_PARAMS = 'state=done';

  /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() {
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
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

  static function getRunner($skipEndUrl = FALSE) {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // reset push stats
    CRM_Core_BAO_Setting::setItem(Array(), CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
    $stats = array();
   
    // We need to process one list at a time.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync getRunner $groups= ', $groups);
    
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
        'group_id' => 0,
        'error_count' => 0
      );

      $identifier = "List " . $listCount++ . " " . $details['civigroup_title'];

      $task  = new CRM_Queue_Task(
        array ('CRM_Mailchimp_Form_Sync', 'syncPushList'),
        array($details['list_id'], $identifier),
        "Preparing queue for $identifier"
      );

      // Add the Task to the Queue
      $queue->createItem($task);
    }

    // Setup the Runner
		$runnerParams = array(
      'title' => ts('Mailchimp Sync: CiviCRM to Mailchimp'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
    );
		// Skip End URL to prevent redirect
		// if calling from cron job
		if ($skipEndUrl == TRUE) {
			unset($runnerParams['onEndUrl']);
		}
    $runner = new CRM_Queue_Runner($runnerParams);

    static::updatePushStats($stats);
    
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync getRunner $identifier= ', $identifier);

    return $runner;
  }

  /**
   * Set up (sub)queue for syncing a Mailchimp List.
   */
  static function syncPushList(CRM_Queue_TaskContext $ctx, $listID, $identifier) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushList $listID= ', $listID);
    // Split the work into parts:
    // @todo 'force' method not implemented here.

    // Add the Mailchimp collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushCollectMailchimp'),
      array($listID),
      "$identifier: Fetched data from Mailchimp"
    ));

    // Add the CiviCRM collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushCollectCiviCRM'),
      array($listID),
      "$identifier: Fetched data from CiviCRM"
    ));

    // Add the removals task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushRemove'),
      array($listID),
      "$identifier: Removed those who should no longer be subscribed"
    ));

    // Add the batchUpdate to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushAdd'),
      array($listID),
      "$identifier: Added new subscribers and updating existing data changes"
    ));

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Mailchimp data into temporary working table.
   */
  static function syncPushCollectMailchimp(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectMailchimp $listID= ', $listID);

    $stats[$listID]['mc_count'] = static::syncCollectMailchimp($listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectMailchimp $stats[$listID][mc_count]', $stats[$listID]['mc_count']);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  static function syncPushCollectCiviCRM(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectCiviCRM $listID= ', $listID);

    $stats[$listID]['c_count'] = static::syncCollectCiviCRM($listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectCiviCRM $stats[$listID][c_count]= ', $stats[$listID]['c_count']);
 
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Unsubscribe contacts that are subscribed at Mailchimp but not in our list.
   */
  static function syncPushRemove(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushRemove $listID= ', $listID);
    // Delete records have the same hash - these do not need an update.
    static::updatePushStats(array($listID=>array('in_sync'=> static::syncIdentical())));

    // Now identify those that need removing from Mailchimp.
    // @todo implement the delete option, here just the unsubscribe is implemented.
    $dao = CRM_Core_DAO::executeQuery(
      "SELECT m.email, m.euid, m.leid
       FROM tmp_mailchimp_push_m m
       WHERE NOT EXISTS (
         SELECT email FROM tmp_mailchimp_push_c c WHERE c.email = m.email
       );");

    // Loop the $dao object to make a list of emails to unsubscribe|delete from MC
    // http://apidocs.mailchimp.com/api/2.0/lists/batch-unsubscribe.php
    $batch = array();
    $stats[$listID]['removed'] = 0;
    while ($dao->fetch()) {
      $batch[] = array('email' => $dao->email, 'euid' => $dao->euid, 'leid' => $dao->leid);
      $stats[$listID]['removed']++;
    }
    if (!$batch) {
      // Nothing to do
      return CRM_Queue_Task::TASK_SUCCESS;
    }

    // Log the batch unsubscribe details
    CRM_Core_Error::debug_var('Mailchimp batchUnsubscribe syncPushRemove $batch= ', $batch);
    // Send Mailchimp Lists API Call: http://apidocs.mailchimp.com/api/2.0/lists/batch-unsubscribe.php
    $list = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
    $result = $list->batchUnsubscribe( $listID, $batch, $delete=FALSE, $send_bye=FALSE, $send_notify=FALSE);

    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncPushRemove $batchUnsubscriberesult= ', $result);
    // @todo check errors? $result['errors'] $result['success_count']

    // Finally we can delete the emails that we just processed from the mailchimp temp table.
    CRM_Core_DAO::executeQuery(
      "DELETE FROM tmp_mailchimp_push_m
       WHERE NOT EXISTS (
         SELECT email FROM tmp_mailchimp_push_c c WHERE c.email = tmp_mailchimp_push_m.email
       );");

    static::updatePushStats($stats);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Batch update Mailchimp with new contacts that need to be subscribed, or have changed data.
   *
   * This also does the clean-up tasks of removing the temporary tables.
   */
  static function syncPushAdd(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushAdd $listID= ', $listID);

    // @todo take the remaining details from tmp_mailchimp_push_c
    // and construct a batchUpdate (do they need to be batched into 1000s? I can't recal).

    $dao = CRM_Core_DAO::executeQuery( "SELECT * FROM tmp_mailchimp_push_c;");
    $stats = array();
    // Loop the $dao object to make a list of emails to subscribe/update
    $batch = array();
    while ($dao->fetch()) {
      $merge = array(
        'FNAME' => $dao->first_name,
        'LNAME' => $dao->last_name,
      );
      // set the groupings.
      $groupings = unserialize($dao->groupings);
      // this is a array(groupingid=>array(groupid=>bool membership))
      $merge_groups = array();
      foreach ($groupings as $grouping_id => $groups) {
       // CRM_Mailchimp_Utils::checkDebug('get groups $groups= ', $groups);
        $merge_groups[$grouping_id] = array('id' => $grouping_id, 'groups' => array());


        foreach ($groups as $group_id => $is_member) {
          if ($is_member) {
            $merge_groups[$grouping_id]['groups'][] = CRM_Mailchimp_Utils::getMCGroupName($listID, $grouping_id, $group_id);

          }
        }
      }
      // remove the significant array indexes, in case Mailchimp cares.
      $merge['groupings'] = array_values($merge_groups);

      $batch[$dao->email] = array('email' => array('email' => $dao->email), 'email_type' => 'html', 'merge_vars' => $merge);
      $stats[$listID]['added']++;
    }
    if (!$batch) {
      // Nothing to do
      return CRM_Queue_Task::TASK_SUCCESS;
    }

    // Log the batch subscribe details
    CRM_Core_Error::debug_var('Mailchimp syncPushAdd batchSubscribe $batch= ', $batch);
    // Send Mailchimp Lists API Call.
    // http://apidocs.mailchimp.com/api/2.0/lists/batch-subscribe.php
    $list = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
    $batchs = array_chunk($batch, 50, true);
    $batchResult = array();
    $result = array('errors' => array());
    foreach($batchs as $id => $batch) {
      $batchResult[$id] = $list->batchSubscribe( $listID, $batch, $double_optin=FALSE, $update=TRUE, $replace_interests=TRUE);
      $result['error_count'] += $batchResult[$id]['error_count'];
      // @TODO: updating stats for errors, create sql error "Data too long for column 'value'" (for long array)
      if ($batchResult[$id]['errors']) {
        foreach ($batchResult[$id]['errors'] as $errorDetails){
          // Resubscribe if email address is reported as unsubscribed        
          // they want to resubscribe.
          if ($errorDetails['code'] == 212) {
            $unsubscribedEmail = $errorDetails['email']['email'];
            $list->subscribe( $listID, $batch[$unsubscribedEmail]['email'], $batch[$unsubscribedEmail]['merge_vars'], $batch[$unsubscribedEmail]['email_type'], FALSE, TRUE, FALSE, FALSE);
            $result['error_count'] -= 1;
          }
          else {
            $result['errors'][] = $errorDetails;
          }
        }
      }
      CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncPushAdd $batchsubscriberesultinloop= ', $batchResult[$id]);
    }
    // debug: file_put_contents(DRUPAL_ROOT . '/logs/' . date('Y-m-d-His') . '-MC-push.log', print_r($result,1));

    $get_GroupId = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID);

    CRM_Mailchimp_Utils::checkDebug('$get_GroupId= ', $get_GroupId);
    // @todo check result (keys: error_count, add_count, update_count)

    $stats[$listID]['group_id'] = array_keys($get_GroupId);
    $stats[$listID]['error_count'] = $result['error_count'];
    $stats[$listID]['error_details'] = $result['errors'];
   
    static::updatePushStats($stats);

    // Finally, finish up by removing the two temporary tables
   CRM_Core_DAO::executeQuery("DROP TABLE tmp_mailchimp_push_m;");
   CRM_Core_DAO::executeQuery("DROP TABLE tmp_mailchimp_push_c;");


    return CRM_Queue_Task::TASK_SUCCESS;

  }


  /**
   * Collect Mailchimp data into temporary working table.
   */
  static function syncCollectMailchimp($listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectMailchimp $listID= ', $listID);
    // Create a temporary table.
    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.

    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_m;");
    CRM_Core_DAO::executeQuery(
      "CREATE TABLE tmp_mailchimp_push_m (
        email VARCHAR(200),
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        euid VARCHAR(10),
        leid VARCHAR(10),
        hash CHAR(32),
        groupings VARCHAR(4096),
        cid_guess INT(10),
        PRIMARY KEY (email, hash))
        ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ;");
    // I'll use the cid_guess column to store the cid when it is
    // immediately clear. This will speed up pulling updates (see #118).
    // Create an index so that this cid_guess can be used for fast
    // searching.
    $dao = CRM_Core_DAO::executeQuery(
        "CREATE INDEX index_cid_guess ON tmp_mailchimp_push_m(cid_guess);");

    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
	$insert = $db->prepare('INSERT INTO tmp_mailchimp_push_m(email, first_name, last_name, euid, leid, hash, groupings) VALUES(?, ?, ?, ?, ?, ?, ?)');

    // We need to know what grouping data we care about. The rest we completely ignore.
    // We only care about CiviCRM groups that are mapped to this MC List:
    $mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID);

    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncCollectMailchimp $mapped_groups', $mapped_groups);

    // Prepare to access Mailchimp export API
    // See http://apidocs.mailchimp.com/export/1.0/list.func.php
    // Example result (spacing added)
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

    // Load headers from the export.
    // This is an array of strings. We need to find the array indexes for the columns we're interested in.
    $buffer = fgets($handle, $chunk_size);
    if (trim($buffer)=='') {
      // @todo not sure a vanilla exception is best?
      throw new \Exception("Failed to read from Mailchimp export API");
    }
    $header = json_decode($buffer);
    // We need to know the indexes of our groupings
    foreach ($mapped_groups as $civi_group_id => &$details) {
      if (!$details['grouping_name']) {
        // this will be the membership group.
        continue;
      }
      $details['idx'] = array_search($details['grouping_name'], $header);
    }
    unset($details);
    // ... and LEID and EUID fields.
    $leid_idx = array_search('LEID', $header);
    $euid_idx = array_search('EUID', $header);

    //
    // Main loop of all the records.
    //
    while (!feof($handle)) {
      $buffer = trim(fgets($handle, $chunk_size));
      if (!$buffer) {
        continue;
      }
      // fetch array of columns.
      $subscriber = json_decode($buffer);

      // Find out which of our mapped groups apply to this subscriber.
      $info = array();
      foreach ($mapped_groups as $civi_group_id => $details) {
        if (!$details['grouping_name']) {
          // this will be the membership group.
          continue;
        }

        // Fetch the data for this grouping.
        $mc_groups = explode(', ', $subscriber[ $details['idx'] ]);
        // Is this mc group included?
        $info[ $details['grouping_id'] ][ $details['group_id'] ] = in_array($details['group_name'], $mc_groups);
      }
      // Serialize the grouping array for SQL storage - this is the fastest way.
      $info = serialize($info);

      // we're ready to store this but we need a hash that contains all the info
      // for comparison with the hash created from the CiviCRM data (elsewhere).
      //          email,           first name,      last name,      groupings
      $hash = md5($subscriber[0] . $subscriber[1] . $subscriber[2] . $info);
      // run insert prepared statement
      $db->execute($insert, array($subscriber[0], $subscriber[1], $subscriber[2], $subscriber[$euid_idx], $subscriber[$leid_idx], $hash, $info));
    }

    // Tidy up.
    fclose($handle);
    $db->freePrepared($insert);

    // Guess the contact ID's, to speed up syncPullUpdates (See issue #188).
    CRM_Mailchimp_Utils::guessCidsMailchimpContacts();

    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_m");
    $dao->fetch();
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync syncCollectMailchimp $listID= ', $listID);
    return $dao->c;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  static function syncCollectCiviCRM($listID) {
  CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncCollectCiviCRM $listID= ', $listID);
    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.
    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_c;");
    $dao = CRM_Core_DAO::executeQuery("CREATE TABLE tmp_mailchimp_push_c (
        contact_id INT(10) UNSIGNED NOT NULL,
        email_id INT(10) UNSIGNED NOT NULL,
        email VARCHAR(200),
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        hash CHAR(32),
        groupings VARCHAR(4096),
        PRIMARY KEY (email_id, email, hash))
        ENGINE=InnoDB DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci ;");
    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_c VALUES(?, ?, ?, ?, ?, ?, ?)');

    //create table for mailchim civicrm syn errors
    $dao = CRM_Core_DAO::executeQuery("CREATE TABLE IF NOT EXISTS mailchimp_civicrm_syn_errors (
        id int(11) NOT NULL AUTO_INCREMENT,
        email VARCHAR(200),
        error VARCHAR(200),
        error_count int(10),
        group_id int(20),
        list_id VARCHAR(20),
        PRIMARY KEY (id)
        );");

    // We need to know what groupings we have maps to.
    // We only care about CiviCRM groups that are mapped to this MC List:
    $mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID);
    
    // First, get all subscribers from the membership group for this list.
    // ... Find CiviCRM group id for the membership group.
    // ... And while we're at it, build an SQL-safe array of groupIds for groups mapped to groupings.
    //     (we use that later)
    $membership_group_id = FALSE;
    // There used to be a distinction between the handling of 'normal' groups
    // and smart groups. But now the API will take care of this.
    $grouping_group_ids = array();
    $default_info = array();

    // The CiviCRM Contact API returns group titles instead of group ID's.
    // Nobody knows why. So let's build this array to convert titles to ID's.
    $title2gid = array();

    foreach ($mapped_groups as $group_id => $details) {
      $title2gid[$details['civigroup_title']] = $group_id;
      CRM_Contact_BAO_GroupContactCache::loadAll($group_id);
      if (!$details['grouping_id']) {
        $membership_group_id = $group_id;
      }
      else {
        $grouping_group_ids[] = (int)$group_id;
        $default_info[ $details['grouping_id'] ][ $details['group_id'] ] = FALSE;
      }
    }
    if (!$membership_group_id) {
      throw new Exception("No CiviCRM group is mapped to determine membership of Mailchimp list $listID");
    }
    // Use a nice API call to get the information for tmp_mailchimp_push_c.
    // The API will take care of smart groups.
    $result = civicrm_api3('Contact', 'get', array(
      'is_deleted' => 0,
      // The email filter below does not work (CRM-18147)
      // 'email' => array('IS NOT NULL' => 1),
      // Now I think that on_hold is NULL when there is no e-mail, so if
      // we are lucky, the filter below implies that an e-mail address
      // exists ;-)
      'on_hold' => 0,
      'is_opt_out' => 0,
      'do_not_email' => 0,
      'group' => $membership_group_id,
      'return' => array('first_name', 'last_name', 'email_id', 'email', 'group'),
      'options' => array('limit' => 0),
    ));

    foreach ($result['values'] as $contact) {
      // Find out the ID's of the groups the $contact belongs to, and
      // save in $info.
      $info = $default_info;

      $contact_group_titles = explode(',', $contact['group'] );
      foreach ($contact_group_titles as $title) {
        $group_id = $title2gid[$title];
        if (in_array($group_id, $grouping_group_ids)) {
          $details = $mapped_groups[$group_id];
          $info[$details['grouping_id']][$details['group_id']] = TRUE;
        }
      }

      // OK we should now have all the info we need.
      // Serialize the grouping array for SQL storage - this is the fastest way.
      $info = serialize($info);

      // we're ready to store this but we need a hash that contains all the info
      // for comparison with the hash created from the CiviCRM data (elsewhere).
      //          email,           first name,      last name,      groupings
      $hash = md5($contact['email'] . $contact['first_name'] . $contact['last_name'] . $info);
      // run insert prepared statement
      $db->execute($insert, array($contact['id'], $contact['email_id'], $contact['email'], $contact['first_name'], $contact['last_name'], $hash, $info));
    }

    // Tidy up.
    $db->freePrepared($insert);
    // count
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_c");
    $dao->fetch();
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync syncCollectCiviCRM $listID= ', $listID);
    return $dao->c;
  }

  /**
   * Update the push stats setting.
   */
  static function updatePushStats($updates) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync updatePushStats $updates= ', $updates);
    $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
    
    foreach ($updates as $listId=>$settings) {
      foreach ($settings as $key=>$val) {
        // avoid error details to store in civicrm_settings table
        // create sql error "Data too long for column 'value'" (for long array)
        if ($key == 'error_details') {
          continue;
        }
        $stats[$listId][$key] = $val;
      }
    }
    CRM_Core_BAO_Setting::setItem($stats, CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');

    //$email = $error_count = $error = $list_id = array();

    foreach ($updates as $list => $listdetails) {
      if (isset($updates[$list]['error_count']) && !empty($updates[$list]['error_count'])) {
        $error_count = $updates[$list]['error_count'];
      }
      $list_id = $list;

      if (isset($updates[$list]['group_id']) && !empty($updates[$list]['group_id'])) {
        foreach ($updates[$list]['group_id'] as $keys => $values) {
          $group_id = $values;
          $deleteQuery = "DELETE FROM `mailchimp_civicrm_syn_errors` WHERE group_id =$group_id";
          CRM_Core_DAO::executeQuery($deleteQuery);
        }
      }

      if (isset($updates[$list]['error_details']) && !empty($updates[$list]['error_details'])) {
        foreach ($updates[$list]['error_details'] as $key => $value) {
          $error = $value['error'];
          $email = $value['email']['email'];

          CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync updatePushStats $group_id=', $group_id);
          CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync updatePushStats $error_count=', $error_count);

          $insertQuery = "INSERT INTO `mailchimp_civicrm_syn_errors` (`email`, `error`, `error_count`, `list_id`, `group_id`) VALUES (%1,%2, %3, %4, %5)";
          $queryParams = array(
            1 => array($email, 'String'),
            2 => array($error, 'String'),
            3 => array($error_count, 'Integer'),
            4 => array($list_id, 'String'),
            5 => array($group_id, 'Integer')
          );
          CRM_Core_DAO::executeQuery($insertQuery, $queryParams);
        }
      }
    }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync updatePushStats $updates= ', $updates);
  }
  
  /**
   * Removes from the temporary tables those records that do not need processing.
   */
  static function syncIdentical() {
    //CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncIdentical $count= ', $count);
    // Delete records have the same hash - these do not need an update.
    // count
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(c.email) co FROM tmp_mailchimp_push_m m
      INNER JOIN tmp_mailchimp_push_c c ON m.email = c.email AND m.hash = c.hash;");
    $dao->fetch();
    $count = $dao->co;
    CRM_Core_DAO::executeQuery(
      "DELETE m, c
       FROM tmp_mailchimp_push_m m
       INNER JOIN tmp_mailchimp_push_c c ON m.email = c.email AND m.hash = c.hash;");

    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Form_Sync syncIdentical $count= ', $count);
    return $count;
  }
}
