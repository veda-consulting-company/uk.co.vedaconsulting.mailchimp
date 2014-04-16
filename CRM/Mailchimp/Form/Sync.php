<?php

class CRM_Mailchimp_Form_Sync extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-sync';
  const END_URL    = 'civicrm/mailchimp/sync';
  const END_PARAMS = 'action=finish';

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
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));

    // Set the Number of Rounds to 0
    $rounds = 10;
    $count  = 100;

    // Setup a Task in the Queue
    $i = 0;
    while ($i < $rounds) {
      $start = $i * 10;
      $task  = new CRM_Queue_Task(
        array ('CRM_Mailchimp_Form_Sync', 'runSync'),
        array($start),
        'Mailchimp Sync - Contacts '. ($start+10) . ' of ' . $count
      );

      // Add the Task to the Queu
      $queue->createItem($task);
      $i++;
    }

    // Setup the Runner
    $runner = new CRM_Queue_Runner(array(
      'title' => ts('Mailchimp Sync'),
      'queue' => $queue,
      'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
      'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS),
    ));

    // Run Everything in the Queue via the Web.
    $runner->runAllViaWeb();
  }

  /**
   * Run the From
   *
   * @access public
   *
   * @return TRUE
   */
  public function runSync(CRM_Queue_TaskContext $ctx, $start) {
    sleep(1);
    return CRM_Queue_Task::TASK_SUCCESS;
  }
}
