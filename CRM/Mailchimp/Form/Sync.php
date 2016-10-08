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
      $this->assign('dry_run', $stats['dry_run']);
      foreach ($groups as $group_id => $details) {
        if (empty($details['list_name'])) {
          continue;
        }
        $list_stats = $stats[$details['list_id']];
        $output_stats[] = array(
          'name' => $details['civigroup_title'],
          'stats' => $list_stats,
        );
      }
      $this->assign('stats', $output_stats);

      // Load contents of mailchimp_log table.
      $dao = CRM_Core_DAO::executeQuery("SELECT * FROM mailchimp_log ORDER BY id");
      $logs = [];
      while ($dao->fetch()) {
        $logs []= [
          'group' => $dao->group_id,
          'email' => $dao->email,
          'name' => $dao->name,
          'message' => $dao->message,
          ];
      }
      $this->assign('error_messages', $logs);
    }
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {

    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
    $will = '';
    $wont = '';
    if (!empty($_GET['reset'])) {
      foreach ($groups as $group_id => $details) {
        $description = "<a href='/civicrm/group?reset=1&action=update&id=$group_id' >"
          . "CiviCRM group $group_id: "
          . htmlspecialchars($details['civigroup_title']) . "</a>";

        if (empty($details['list_name'])) {
          $wont .= "<li>$description</li>";
        }
        else {
          $will .= "<li>$description &rarr; Mailchimp List: " . htmlspecialchars($details['list_name']) . "</li>";
        }
      }
    }
    $msg = '';
    if ($will) {
      $msg .= "<h2>" . ts('The following lists will be synchronised') . "</h2><ul>$will</ul>";

      $this->addElement('checkbox', 'mc_dry_run',
        ts('Dry Run? (if ticked no changes will be made to CiviCRM or Mailchimp.)'));

      // Create the Submit Button.
      $buttons = array(
        array(
          'type' => 'submit',
          'name' => ts('Sync Contacts'),
        ),
      );
      $this->addButtons($buttons);
    }
    if ($wont) {
      $msg .= "<h2>" . ts('The following lists will be NOT synchronised') . "</h2><p>The following list(s) no longer exist at Mailchimp.</p><ul>$wont</ul>";
    }
    $this->assign('summary', $msg);
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    $vals = $this->_submitValues;
    $runner = self::getRunner(FALSE, !empty($vals['mc_dry_run']));
    // Clear out log table.
    CRM_Mailchimp_Sync::dropLogTable();
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to sync. Make sure mailchimp settings are configured for the groups with enough members.'));
    }
  }

  /**
   * Set up the queue.
   */
  public static function getRunner($skipEndUrl = FALSE, $dry_run = FALSE) {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // reset push stats
    $stats = ['dry_run' => $dry_run];
    CRM_Core_BAO_Setting::setItem($stats, CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');

    // We need to process one list at a time.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync getRunner $groups= ', $groups);

    // Each list is a task.
    $listCount = 0;
    foreach ($groups as $group_id => $details) {
      if (empty($details['list_name'])) {
        // This list has been deleted at Mailchimp, or for some other reason we
        // could not access its name. Best not to sync it.
        continue;
      }

      $stats[$details['list_id']] = [
        'c_count'      => 0,
        'mc_count'     => 0,
        'in_sync'      => 0,
        'updates'      => 0,
        'additions'    => 0,
        'unsubscribes' => 0,
      ];

      $identifier = "List " . $listCount++ . " " . $details['civigroup_title'];

      $task  = new CRM_Queue_Task(
        ['CRM_Mailchimp_Form_Sync', 'syncPushList'],
        [$details['list_id'], $identifier, $dry_run],
        "$identifier: collecting data from CiviCRM."
      );

      // Add the Task to the Queue
      $queue->createItem($task);
    }
    if (count($stats)==1) {
      // Nothing to do. (only key is 'dry_run')
      return FALSE;
    }

    // Setup the Runner
    $runnerParams = array(
      'title' => ($dry_run ? ts('Dry Run: ') : '') . ts('Mailchimp Push Sync: update Mailchimp from CiviCRM'),
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
  public static function syncPushList(CRM_Queue_TaskContext $ctx, $listID, $identifier, $dry_run) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushList $listID= ', $listID);
    // Split the work into parts:

    // Add the CiviCRM collect data task to the queue
    // It's important that this comes before the Mailchimp one, as some
    // fast contact matching SQL can run if it's done this way.
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushCollectCiviCRM'),
      array($listID),
      "$identifier: Fetched data from CiviCRM, fetching from Mailchimp..."
    ));

    // Add the Mailchimp collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushCollectMailchimp'),
      array($listID),
      "$identifier: Fetched data from Mailchimp. Matching..."
    ));

    // Add the slow match process for difficult contacts.
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushDifficultMatches'),
      array($listID),
      "$identifier: Matched up contacts. Comparing..."
    ));

    // Add the Mailchimp collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushIgnoreInSync'),
      array($listID),
      "$identifier: Ignored any in-sync already. Updating Mailchimp..."
    ));

    // Add the Mailchimp changes
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Sync', 'syncPushToMailchimp'),
      array($listID, $dry_run),
      "$identifier: Completed additions/updates/unsubscribes."
    ));

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  public static function syncPushCollectCiviCRM(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectCiviCRM $listID= ', $listID);

    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['c_count'] = $sync->collectCiviCrm('push');

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectCiviCRM $stats[$listID][c_count]= ', $stats[$listID]['c_count']);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Mailchimp data into temporary working table.
   */
  public static function syncPushCollectMailchimp(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectMailchimp $listID= ', $listID);

    // Nb. collectCiviCrm must have run before we call this.
    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['mc_count'] = $sync->collectMailchimp('push', $civi_collect_has_already_run=TRUE);

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushCollectMailchimp $stats[$listID][mc_count]', $stats[$listID]['mc_count']);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Do the difficult matches.
   */
  public static function syncPushDifficultMatches(CRM_Queue_TaskContext $ctx, $listID) {

    // Nb. collectCiviCrm must have run before we call this.
    $sync = new CRM_Mailchimp_Sync($listID);
    $c = $sync->matchMailchimpMembersToContacts();
    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Sync syncPushDifficultMatches count=', $c);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Mailchimp data into temporary working table.
   */
  public static function syncPushIgnoreInSync(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushIgnoreInSync $listID= ', $listID);

    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['in_sync'] = $sync->removeInSync('push');

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushIgnoreInSync $stats[$listID][in_sync]', $stats[$listID]['in_sync']);
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Batch update Mailchimp with new contacts that need to be subscribed, or
   * have changed data including unsubscribes.
   */
  public static function syncPushToMailchimp(CRM_Queue_TaskContext $ctx, $listID, $dry_run) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync syncPushAdd $listID= ', $listID);

    // Do the batch update. Might take a while :-O
    $sync = new CRM_Mailchimp_Sync($listID);
    $sync->dry_run = $dry_run;
    // this generates updates and unsubscribes
    $stats[$listID] = $sync->updateMailchimpFromCivi();
    // Finally, finish up by removing the two temporary tables
    //CRM_Mailchimp_Sync::dropTemporaryTables();
    static::updatePushStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Update the push stats setting.
   */
  public static function updatePushStats($updates) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Sync updatePushStats $updates= ', $updates);

    $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
    foreach ($updates as $listId=>$settings) {
      if ($listId == 'dry_run') {
        continue;
      }
      foreach ($settings as $key=>$val) {
        $stats[$listId][$key] = $val;
      }
    }
    CRM_Core_BAO_Setting::setItem($stats, CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'push_stats');
  }
}
