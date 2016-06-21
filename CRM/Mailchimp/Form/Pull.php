<?php
/**
 * @file
 * This provides the Sync Pull from Mailchimp to CiviCRM form.
 */

class CRM_Mailchimp_Form_Pull extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-pull';
  const END_URL    = 'civicrm/mailchimp/pull';
  const END_PARAMS = 'state=done';

  function preProcess() {
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');

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
          $will .= "<li>Mailchimp List: " . htmlspecialchars($details['list_name']) . " &rarr; $description</li>";
        }
      }
    }
    $msg = '';
    if ($will) {
      $msg .= "<h2>" . ts('The following lists will be synchronised') . "</h2><ul>$will</ul>";

      // Create the Submit Button.
      $buttons = array(
        array(
          'type' => 'submit',
          'name' => ts('Sync Contacts'),
        ),
      );

      $this->addElement('checkbox', 'mc_dry_run',
        ts('Dry Run? (if ticked no changes will be made to CiviCRM or Mailchimp.)'));

      $this->addButtons($buttons);
    }
    if ($wont) {
      $msg .= "<h2>" . ts('The following lists will be NOT synchronised') . "</h2><p>The following list(s) no longer exist at Mailchimp.</p><ul>$wont</ul>";
    }
    $this->assign('summary', $msg);

  }

  public function postProcess() {
    $setting_url = CRM_Utils_System::url('civicrm/mailchimp/settings', 'reset=1',  TRUE, NULL, FALSE, TRUE);
    $vals = $this->_submitValues;
    $runner = self::getRunner(FALSE, !empty($vals['mc_dry_run']));
    // Clear out log table.
    CRM_Mailchimp_Sync::dropLogTable();
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to pull. Make sure mailchimp settings are configured in the <a href='.$setting_url.'>setting page</a>.'));
    }
  }

  public static function getRunner($skipEndUrl = FALSE, $dry_run = FALSE) {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // reset pull stats.
    $stats = ['dry_run' => $dry_run];
    CRM_Core_BAO_Setting::setItem($stats, CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');

    // We need to process one list at a time.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only=TRUE);
    if (!$groups) {
      // Nothing to do.
      return FALSE;
    }
    // Each list is a task.
    $listCount = 1;
    foreach ($groups as $group_id => $details) {
      if (empty($details['list_name'])) {
        // This list has been deleted at Mailchimp, or for some other reason we
        // could not access its name. Best not to sync it.
        continue;
      }
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
        array($details['list_id'], $identifier, $dry_run),
        "$identifier: collecting data from CiviCRM..."
      );

      // Add the Task to the Queue
      $queue->createItem($task);
    }
    // Setup the Runner
		$runnerParams = array(
      'title' => ($dry_run ? ts('Dry Run: ') : '') . ts('Mailchimp Pull Sync: update CiviCRM from Mailchimp'),
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
    static::updatePullStats($stats);
    return $runner;
  }

  public static function syncPullList(CRM_Queue_TaskContext $ctx, $listID, $identifier, $dry_run) {
    // Add the CiviCRM collect data task to the queue
    // It's important that this comes before the Mailchimp one, as some
    // fast contact matching SQL can run if it's done this way.
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPullCollectCiviCRM'),
      array($listID),
      "$identifier: Fetched data from CiviCRM, fetching from Mailchimp..."
    ));

    // Add the Mailchimp collect data task to the queue
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPullCollectMailchimp'),
      array($listID),
      "$identifier: Fetched data from Mailchimp. Matching..."
    ));

    // Add the slow match process for difficult contacts.
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPullMatch'),
      array($listID),
      "$identifier: Matched up contacts. Comparing..."
    ));

    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPullIgnoreInSync'),
      array($listID),
      "$identifier: Ignored any in-sync contacts. Updating CiviCRM with changes."
    ));

    // Add the Civi Changes.
    $ctx->queue->createItem( new CRM_Queue_Task(
      array('CRM_Mailchimp_Form_Pull', 'syncPullFromMailchimp'),
      array($listID, $dry_run),
      "$identifier: Completed."
    ));

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect CiviCRM data into temporary working table.
   */
  public static function syncPullCollectCiviCRM(CRM_Queue_TaskContext $ctx, $listID) {

    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['c_count'] = $sync->collectCiviCrm('pull');
    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Pull syncPullCollectCiviCRM $stats[$listID][c_count]', $stats[$listID]['c_count']);

    static::updatePullStats($stats);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Collect Mailchimp data into temporary working table.
   */
  public static function syncPullCollectMailchimp(CRM_Queue_TaskContext $ctx, $listID) {

    // Nb. collectCiviCrm must have run before we call this.
    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['mc_count'] = $sync->collectMailchimp('pull');

    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Pull syncPullCollectMailchimp count=', $stats[$listID]['mc_count']);
    static::updatePullStats($stats);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Do the difficult matches.
   */
  public static function syncPullMatch(CRM_Queue_TaskContext $ctx, $listID) {

    // Nb. collectCiviCrm must have run before we call this.
    $sync = new CRM_Mailchimp_Sync($listID);
    $c = $sync->matchMailchimpMembersToContacts();
    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Form_Pull syncPullMatch count=', $c);
    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Remove anything that's the same.
   */
  public static function syncPullIgnoreInSync(CRM_Queue_TaskContext $ctx, $listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Pull syncPullIgnoreInSync $listID= ', $listID);

    $sync = new CRM_Mailchimp_Sync($listID);
    $stats[$listID]['in_sync'] = $sync->removeInSync('pull');

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Form_Pull syncPullIgnoreInSync in-sync= ', $stats[$listID]['in_sync']);
    static::updatePullStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * New contacts and profile changes need bringing into CiviCRM.
   */
  public static function syncPullFromMailchimp(CRM_Queue_TaskContext $ctx, $listID, $dry_run) {

    // Do the batch update. Might take a while :-O
    $sync = new CRM_Mailchimp_Sync($listID);
    $sync->dry_run = $dry_run;
    // this generates updates and group changes.
    $stats[$listID] = $sync->updateCiviFromMailchimp();
    // Finally, finish up by removing the two temporary tables
    // @todo re-enable this: CRM_Mailchimp_Sync::dropTemporaryTables();
    static::updatePullStats($stats);

    return CRM_Queue_Task::TASK_SUCCESS;
  }

  /**
   * Update the pull stats setting.
   */
  public static function updatePullStats($updates) {
    $stats = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
    foreach ($updates as $list_id=>$settings) {
      if ($list_id == 'dry_run') {
        continue;
      }
      foreach ($settings as $key=>$val) {
        if (!empty($stats[$list_id][$key])) {
          $stats[$list_id][$key] += $val;
        }
        else {
          $stats[$list_id][$key] = $val;
        }
      }
    }
    CRM_Core_BAO_Setting::setItem($stats, CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'pull_stats');
  }

}
