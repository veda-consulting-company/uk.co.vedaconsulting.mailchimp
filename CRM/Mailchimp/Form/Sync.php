<?php

class CRM_Mailchimp_Form_Sync extends CRM_Core_Form {

  const QUEUE_NAME = 'mc-sync';
  const END_URL    = 'civicrm/mailchimp/sync';
  const END_PARAMS = 'action=finish';
  const BATCH_COUNT = 10;

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

    // get member count
    $count  = CRM_Mailchimp_Utils::getMemberCountForGroupsToSync();

    // Set the Number of Rounds
    $rounds = ceil($count/self::BATCH_COUNT);

   // Setup a Task in the Queue
    $i = 0;
    while ($i < $rounds) {
      $start = $i * self::BATCH_COUNT;
      $task  = new CRM_Queue_Task(
        array ('CRM_Mailchimp_Form_Sync', 'runSync'),
        array($start),
        'Mailchimp Sync - Contacts '. ($start+self::BATCH_COUNT) . ' of ' . $count
      );

      // Add the Task to the Queu
      $queue->createItem($task);
      $i++;
    }

    if ($i > 0) {
      // Setup the Runner
      $runner = new CRM_Queue_Runner(array(
        'title' => ts('Mailchimp Sync'),
        'queue' => $queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
        'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS),
      ));

      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      CRM_Core_Session::setStatus(ts('Nothing to sync. Make sure mailchimp settings are configured for the groups with enough members.'));
    }
  }

  /**
   * Run the From
   *
   * @access public
   *
   * @return TRUE
   */
  public function runSync(CRM_Queue_TaskContext $ctx, $start) {
    //sleep(1);
    $mcGroupIDs = CRM_Mailchimp_Utils::getGroupIDsToSync();
    if (!empty($mcGroupIDs)) {
      $mcGroups  = CRM_Mailchimp_Utils::getGroupsToSync();

      $groupContact = new CRM_Contact_BAO_GroupContact();
      $groupContact->whereAdd('group_id IN ('.implode(',', $mcGroupIDs).')');
      $groupContact->whereAdd("status = 'Added'");
      $groupContact->limit($start, self::BATCH_COUNT);
      $groupContact->find();

      $batch = array();
      while ($groupContact->fetch()) {
        $contact = new CRM_Contact_BAO_Contact();
        $contact->id = $groupContact->contact_id;
        $contact->find(TRUE);

        $email = new CRM_Core_BAO_Email();
        $email->contact_id = $groupContact->contact_id;
        $email->is_primary = TRUE;
        $email->find(TRUE);

        if ($email->email && 
          ($contact->is_opt_out == 0) && 
          ($contact->do_not_email == 0) &&
          ($email->on_hold == 0)) {
            $batch[] = array('email' => array('email' => $email->email));
          }
      }

      if (!empty($batch)) {
        $mailchimp = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
        $results   = $mailchimp->batchSubscribe( 
          $mcGroups[$groupContact->group_id]['list_id'], 
          $batch, 
          FALSE,
          TRUE, 
          TRUE
        );
      }
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }
}
