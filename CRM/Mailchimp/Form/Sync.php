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

  static function getRunner() {
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
        array ('CRM_Mailchimp_Form_Sync', 'syncPushList'),
        array($details['list_id'], $identifier),
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

    static::updatePushStats($stats);

    return $runner;
  }

  /**
   * Set up (sub)queue for syncing a Mailchimp List.
   */
  static function syncPushList(CRM_Queue_TaskContext $ctx, $listID, $identifier) {

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

    $stats[$listID]['mc_count'] = static::syncCollectMailchimp($listID);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  static function syncPushCollectCiviCRM(CRM_Queue_TaskContext $ctx, $listID) {

    $stats[$listID]['c_count'] = static::syncCollectCiviCRM($listID);
    static::updatePushStats($stats);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Unsubscribe contacts that are subscribed at Mailchimp but not in our list.
   */
  static function syncPushRemove(CRM_Queue_TaskContext $ctx, $listID) {
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
    CRM_Core_Error::debug_var( 'Mailchimp batchUnsubscribe $listID= ', $listID);
    CRM_Core_Error::debug_var( 'Mailchimp batchUnsubscribe $batch= ', $batch);
    
    // Send Mailchimp Lists API Call: http://apidocs.mailchimp.com/api/2.0/lists/batch-unsubscribe.php
    $list = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
    $result = $list->batchUnsubscribe( $listID, $batch, $delete=FALSE, $send_bye=FALSE, $send_notify=FALSE);

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

    // @todo take the remaining details from tmp_mailchimp_push_c
    // and construct a batchUpdate (do they need to be batched into 1000s? I can't recal).

    $dao = CRM_Core_DAO::executeQuery( "SELECT * FROM tmp_mailchimp_push_c;");

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
        $merge_groups[$grouping_id] = array('id'=>$grouping_id, 'groups'=>array());

        foreach ($groups as $group_id => $is_member) {
          if ($is_member) {
            $merge_groups[$grouping_id]['groups'][] = CRM_Mailchimp_Utils::getMCGroupName($listID, $grouping_id, $group_id);
          }
        }
      }
      // remove the significant array indexes, in case Mailchimp cares.
      $merge['groupings'] = array_values($merge_groups);

      $batch[] = array('email' => array('email' => $dao->email), 'email_type' => 'html', 'merge_vars' => $merge);
      $stats[$listID]['added']++;
    }
    if (!$batch) {
      // Nothing to do
      return CRM_Queue_Task::TASK_SUCCESS;
    }

    // Log the batch subscribe details
    CRM_Core_Error::debug_var( 'Mailchimp batchSubscribe $listID= ', $listID);
    CRM_Core_Error::debug_var( 'Mailchimp batchSubscribe $batch= ', $batch);
    
    // Send Mailchimp Lists API Call.
    // http://apidocs.mailchimp.com/api/2.0/lists/batch-subscribe.php
    $list = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
    $result = $list->batchSubscribe( $listID, $batch, $double_optin=FALSE, $update=TRUE, $replace_interests=TRUE);
    // debug: file_put_contents(DRUPAL_ROOT . '/logs/' . date('Y-m-d-His') . '-MC-push.log', print_r($result,1));

    // @todo check result (keys: error_count, add_count, update_count)

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
    // Create a temporary table.
    // Nb. these are temporary tables but we don't use TEMPORARY table because they are
    // needed over multiple sessions because of queue.

    CRM_Core_DAO::executeQuery( "DROP TABLE IF EXISTS tmp_mailchimp_push_m;");
    $dao = CRM_Core_DAO::executeQuery(
      "CREATE TABLE tmp_mailchimp_push_m (
        email VARCHAR(200),
        first_name VARCHAR(100),
        last_name VARCHAR(100),
        euid VARCHAR(10),
        leid VARCHAR(10),
        hash CHAR(32),
        groupings VARCHAR(4096),
        PRIMARY KEY (email, hash));");
    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_m VALUES(?, ?, ?, ?, ?, ?, ?)');

    // We need to know what grouping data we care about. The rest we completely ignore.
    // We only care about CiviCRM groups that are mapped to this MC List:
    $mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID);


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

    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_m");
    $dao->fetch();
    return $dao->c;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  static function syncCollectCiviCRM($listID) {

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
        PRIMARY KEY (email_id, email, hash)
        );");
    // Cheekily access the database directly to obtain a prepared statement.
    $db = $dao->getDatabaseConnection();
    $insert = $db->prepare('INSERT INTO tmp_mailchimp_push_c VALUES(?, ?, ?, ?, ?, ?, ?)');

    // We need to know what groupings we have maps to.
    // We only care about CiviCRM groups that are mapped to this MC List:
    $mapped_groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID);
    
    // First, get all subscribers from the membership group for this list.
    // ... Find CiviCRM group id for the membership group.
    // ... And while we're at it, build an SQL-safe array of groupIds for groups mapped to groupings.
    //     (we use that later)
    $membership_group_id = FALSE;
    $grouping_group_ids = array('normal'=>array(),'smart'=>array());
    $default_info = array();
    foreach ($mapped_groups as $group_id => $details) {
      CRM_Contact_BAO_GroupContactCache::loadAll($group_id);
      if (!$details['grouping_id']) {
        $membership_group_id = $group_id;
      }
      else {
        $grouping_group_ids[ ($details['civigroup_uses_cache'] ? 'smart' : 'normal') ][] = (int)$group_id;
        $default_info[ $details['grouping_id'] ][ $details['group_id'] ] = FALSE;
      }
    }
    $grouping_group_ids['smart']  = implode(',', $grouping_group_ids['smart']);
    $grouping_group_ids['normal'] = implode(',', $grouping_group_ids['normal']);
    if (!$membership_group_id) {
      throw new Exception("No CiviCRM group is mapped to determine membership of Mailchimp list $listID");
    }
    // ... Load all subscribers in $groupContact object
    if (!($groupContact = CRM_Mailchimp_Utils::getGroupContactObject($membership_group_id))) {
      throw new Exception("No CiviCRM group is mapped to determine membership of Mailchimp list $listID. CiviCRM group $membership_group_id failed to load");
    }

    // Now we iterate through the subscribers, collecting data about the other mapped groups
    // This is pretty inefficient :-(
    while ($groupContact->fetch()) {
      // Find the contact, for the name fields
      $contact = new CRM_Contact_BAO_Contact();
      $contact->id = $groupContact->contact_id;
      $contact->is_deleted = 0;
      $contact->find(TRUE);

      // Find their primary (bulk) email
      $email = new CRM_Core_BAO_Email();
      $email->contact_id = $groupContact->contact_id;
      $email->is_primary = TRUE;
      $email->find(TRUE);

      // If no email, it's like they're not there.
      if (!$email->email || $email->on_hold || $contact->is_opt_out || $contact->do_not_email) {
        //@todo update stats.
        continue;
      }

      // Find out if they're in any groups that we care about.
      // Start off as not in the groups...
      $info = $default_info;
      // We can do this with two queries, one for normal groups, one for smart groups.

      // Normal groups.
      if ($grouping_group_ids['normal']) {
        $groupContact2 = new CRM_Contact_BAO_GroupContact();
        $groupContact2->contact_id = $groupContact->contact_id;
        $groupContact2->whereAdd("status = 'Added'");
        $groupContact2->whereAdd("group_id IN ($grouping_group_ids[normal])");
        $groupContact2->find();
        while ($groupContact2->fetch()) {
          // need MC grouping_id and group_id
          $details = $mapped_groups[ $groupContact2->group_id ];
          $info[ $details['grouping_id'] ][ $details['group_id'] ] = TRUE;
        }
        unset($groupContact2);
      }

      // Smart groups
      if ($grouping_group_ids['smart']) {
        $groupContactCache = new CRM_Contact_BAO_GroupContactCache();
        $groupContactCache->contact_id = $groupContact->contact_id;
        $groupContactCache->whereAdd("group_id IN ($grouping_group_ids[smart])");
        $groupContactCache->find();
        while ($groupContactCache->fetch()) {
          // need MC grouping_id and group_id
          $details = $mapped_groups[ $groupContactCache->group_id ];
          $info[ $details['grouping_id'] ][ $details['group_id'] ] = TRUE;
        }
        unset($groupContactCache);
      }

      // OK we should now have all the info we need.
      // Serialize the grouping array for SQL storage - this is the fastest way.
      $info = serialize($info);

      // we're ready to store this but we need a hash that contains all the info
      // for comparison with the hash created from the CiviCRM data (elsewhere).
      //          email,           first name,      last name,      groupings
      $hash = md5($email->email . $contact->first_name . $contact->last_name . $info);
      // run insert prepared statement
      $db->execute($insert, array($contact->id, $email->id, $email->email, $contact->first_name, $contact->last_name, $hash, $info));
    }

    // Tidy up.
    $db->freePrepared($insert);
    // count
    $dao = CRM_Core_DAO::executeQuery("SELECT COUNT(*) c  FROM tmp_mailchimp_push_c");
    $dao->fetch();
    return $dao->c;
  }

  /**
   * Update the push stats setting.
   */
  static function updatePushStats($updates) {
    $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
    foreach ($updates as $listId=>$settings) {
      foreach ($settings as $key=>$val) {
        $stats[$listId][$key] = $val;
      }
    }
    CRM_Core_BAO_Setting::setItem($stats, CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
  }
  /**
   * Removes from the temporary tables those records that do not need processing.
   */
  static function syncIdentical() {
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
    return $count;
  }

}
