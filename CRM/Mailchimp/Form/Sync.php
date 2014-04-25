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
    // Setup the Queue
    $queue = CRM_Queue_Service::singleton()->create(array(
      'name'  => self::QUEUE_NAME,
      'type'  => 'Sql',
      'reset' => TRUE,
    ));
    
    $groups = CRM_Mailchimp_Utils::getGroupsToSync();
    foreach ($groups as $groupID => $groupVals) {
      $task  = new CRM_Queue_Task(
        array ('CRM_Mailchimp_Form_Sync', 'syncGroups'),
        array($groupID),
        "Syncing group: $groupID"
      );

      // Add the Task to the Queu
      $queue->createItem($task);
    }


#    // get member count
#    $count  = CRM_Mailchimp_Utils::getMemberCountForGroupsToSync();
#
#    // Set the Number of Rounds
#    $rounds = ceil($count/self::BATCH_COUNT);
#
#   // Setup a Task in the Queue
#    $i = 0;
#    while ($i < $rounds) {
#      $start = $i * self::BATCH_COUNT;
#      $task  = new CRM_Queue_Task(
#        array ('CRM_Mailchimp_Form_Sync', 'syncContacts'),
#        array($start),
#        'Mailchimp Sync - Contacts '. ($start+self::BATCH_COUNT) . ' of ' . $count
#      );
#
#      // Add the Task to the Queu
#      $queue->createItem($task);
#      $i++;
#    }

    if (!empty($groups)) {
      CRM_Mailchimp_BAO_MCSync::resetTable();

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

  public function syncGroups(CRM_Queue_TaskContext $ctx, $groupID) {
    CRM_Core_Error::debug_var('$groupID', $groupID);
    // get member count
    $count  = CRM_Mailchimp_Utils::getMemberCountForGroupsToSync(array($groupID));
    CRM_Core_Error::debug_var('$count', $count);

    // Set the Number of Rounds
    $rounds = ceil($count/self::BATCH_COUNT);
    CRM_Core_Error::debug_var('$rounds', $rounds);

   // Setup a Task in the Queue
    $i = 0;
    while ($i < $rounds) {
      $start   = $i * self::BATCH_COUNT;
      $counter = ($rounds > 1) ? ($start + self::BATCH_COUNT) : $count;
      $task    = new CRM_Queue_Task(
        array('CRM_Mailchimp_Form_Sync', 'syncContacts'),
        array($groupID, $start),
        "Syncing group: {$groupID} - Contacts ". $counter . ' of ' . $count
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
  public function syncContacts(CRM_Queue_TaskContext $ctx, $groupID, $start) {
    //$mcGroupIDs = CRM_Mailchimp_Utils::getGroupIDsToSync();
    if (!empty($groupID)) {
      $mcGroups  = CRM_Mailchimp_Utils::getGroupsToSync($groupID);

      $groupContact = new CRM_Contact_BAO_GroupContact();
      $groupContact->group_id = $groupID;
      $groupContact->whereAdd("status = 'Added'");
      $groupContact->limit($start, self::BATCH_COUNT);
      $groupContact->find();

      $emailToIDs = array();
      $mapper = array();
      while ($groupContact->fetch()) {
        $contact = new CRM_Contact_BAO_Contact();
        $contact->id = $groupContact->contact_id;
        $contact->find(TRUE);

        $email = new CRM_Core_BAO_Email();
        $email->contact_id = $groupContact->contact_id;
        $email->is_primary = TRUE;
        $email->find(TRUE);

        $listID = $mcGroups[$groupContact->group_id]['list_id'];
        $groups = $mcGroups[$groupContact->group_id]['group_id'];
        $groupings = array();
        if (!empty($groups)) {
          list($grouping, $group) = explode(CRM_Core_DAO::VALUE_SEPARATOR, trim($groups));
          if ($grouping && $group) {
            $groupings = 
              array(
                array(
                  'name'   => $grouping,
                  'groups' => array($group)
                )
              );
          }
        }

        if ($email->email && 
          ($contact->is_opt_out   == 0) && 
          ($contact->do_not_email == 0) &&
          ($email->on_hold        == 0)
        ) {
          $mapper[$listID]['batch'][] = array(
            'email'       => array('email' => $email->email),
            'merge_vars'  => array(
              'fname'     => $contact->first_name, 
              'lname'     => $contact->last_name,
              'groupings' => $groupings,
            ),
          );
        } 
        if ($email->id) {
          $emailToIDs["{$email->email}"]['id'] = $email->id;
          $emailToIDs["{$email->email}"]['group'] = $group ? $group : "null";
        }
      }

      foreach ($mapper as $listID => $vals) {
        $mailchimp = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
        $results   = $mailchimp->batchSubscribe( 
          $listID,
          $vals['batch'], 
          FALSE,
          TRUE, 
          FALSE
        );
        foreach (array('adds', 'updates', 'errors') as $key) {
          foreach ($results[$key] as $data) {
            $email  = $key == 'errors' ? $data['email']['email'] : $data['email'];
            $params = array(
              'email_id'   => $emailToIDs[$email]['id'],
              'mc_list_id' => $listID,
              'mc_group'   => $emailToIDs[$email]['group'],
              'mc_euid'    => $data['euid'],
              'mc_leid'    => $data['leid'],
              'sync_status' => $key == 'adds' ? 'Added' : ( $key == 'updates' ? 'Updated' : 'Error')
            );
            CRM_Mailchimp_BAO_MCSync::create($params);
          }
        }
      }
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }
}
