<?php

use CRM_Mailchimp_ExtensionUtil as E;
use CRM_Mailchimp_Check as C;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_Mailchimp_Form_Check extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-check';
  const END_URL    = 'civicrm/mailchimp/check';
  const END_PARAMS = 'state=done';

  /**
   * @inherit
   */
  public function buildQuickForm() {
    CRM_Core_Resources::singleton()->addStyleFile('uk.co.vedaconsulting.mailchimp', 'css/mailchimp.css');
    CRM_Utils_System::setTitle(ts('Mailchimp Group Statistics'));
    $state = CRM_Utils_Request::retrieve('state', 'String', CRM_Core_DAO::$_nullObject, FALSE, 'tmp', 'GET');
    if ($state == 'done') {
      $data = CRM_Mailchimp_Utils::cacheGet(C::CACHE_KEY);
      // Add links to group settings.
      foreach ($data as $groupId => $groupData) {
        $data[$groupId]['url'] = $this->groupPageUrl($groupId);
        foreach ($groupData['sub_groups'] as $subId => $subData) {
           $data[$groupId]['sub_groups'][$subId]['url'] = $this->groupPageUrl($subId);
        }
      }
      $this->assign('groupData', $data);
    }
    else {
      $this->addButtons(array(
        array(
          'type' => 'submit',
          'name' => E::ts('Generate Report'),
          'isDefault' => TRUE,
        ),
      ));
    }

    // export form elements
    parent::buildQuickForm();
  }

  /**
   * Get a url for group edit page.
   *
   * @var int $gid
   *  Group ID
   */
  protected function groupPageUrl($gid) {
    $groupUrl = 'civicrm/group';
    $urlParams = [
      'action' => 'update',
      'reset' => 1,
      'id' => $gid,
    ];
    return CRM_Utils_System::url(
        $groupUrl,
        $urlParams,
        TRUE,
        NULL,
        FALSE,
        FALSE
     );
  }

  public function postProcess() {
    parent::postProcess();
    $runner = self::getQueueRunner();
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    }
  }

  /**
   * Set up the queue.
   */
  public static function getQueueRunner() {
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // reset stats
    CRM_Mailchimp_Utils::cacheSet(C::CACHE_KEY, []);
    $task  = new CRM_Queue_Task(
          ['CRM_Mailchimp_Form_Check', 'addQueueTasks'],
          [],
          "Preparing tasks."
          );

    // Add the Task to the Queue
    $queue->createItem($task);

    // Setup the Runner
    $runnerParams = array(
      'title' =>  ts('Mailchimp Group Statistics'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
    );

    // Each list is a task.
    $runner = new CRM_Queue_Runner($runnerParams);
    return $runner;
  }

  public static function addQueueTasks(CRM_Queue_TaskContext $ctx, $params = []) {
    $listCount = 0;
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
    foreach ($groups as $group_id => $details) {

      $details['civicrm_group_id'] = $group_id;
      $identifier = "List " . $listCount++ . " " . $details['civigroup_title'];
      $tasks = [];
      $tasks[]  = new CRM_Queue_Task(
          ['CRM_Mailchimp_Check', 'checkCiviGroupTask'],
          [$details['list_id']],
          "$identifier: Checking group data from CiviCRM."
          );
      $tasks[] = new CRM_Queue_Task(
          ['CRM_Mailchimp_Check', 'checkMailchimpListTask'],
          [$details['list_id']],
          "$identifier: Checking list data from Mailchimp."
          );

      // Add the Task to the Queue
      foreach ($tasks as $task) {
        $ctx->queue->createItem($task);
      }
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }

}
