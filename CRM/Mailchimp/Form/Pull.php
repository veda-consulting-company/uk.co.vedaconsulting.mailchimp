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
    $defaultgroup = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'default_group');
    if(empty($defaultgroup)) {
      CRM_Core_Session::setStatus(ts('Nothing to pull. Make sure default group is configured in the <a href='.$setting_url.'>setting page</a>.'));
      return FALSE;
    }
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
  
  static function syncContacts(CRM_Queue_TaskContext $ctx, $listid, $contactsarray, $mcGroups) {
    
    $groupContact   = array();
    $defaultgroup   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'default_group');
    $mcGroups       = array_shift($mcGroups);
    $contactsarray  = array_shift($contactsarray);
    foreach($contactsarray as $key => $contact){
      $updateParams = array(
        'EMAIL' =>  $contact['Email Address'],
        'FNAME' =>  $contact['First Name'],
        'LNAME' =>  $contact['Last Name'],
      );
      $contactID    = CRM_Mailchimp_Utils::updateContactDetails($updateParams);
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
      
      if(!empty($contactID)) {
          if(!empty($mcGroups)){
            foreach ($contact as $parms => $value){
              foreach ($mcGroups['values'] as $mcGroupDetails) {
                //check whether a contact belongs to more than one group under one grouping
                  $valuearray = explode(',', $value);
                  if(empty($valuearray[0])) {
                    if(in_array($parms, $mcGroupDetails )) {
                      //Group Details are present but the contact is not assigned to any group in mailchimp
                      $groupContact[$defaultgroup][]  = $contactID;
                      break;
                    }
                  }
                  foreach($valuearray as $val){
                    if (in_array(trim($val), $mcGroupDetails)) {
                      $civiGroupID  = CRM_Mailchimp_Utils::getGroupIdForMailchimp($listid, $mcGroupDetails['groupingid'] , $mcGroupDetails['groupid']);
                      if(!empty($civiGroupID)) {
                        $groupContact[$civiGroupID][]   = $contactID;
                      } else {
                        $groupContact[$defaultgroup][]  = $contactID;
                      }
                    }
                  }
              }
            }
          }else {
            // if a list doesn't have groups,assign the contact to default group
            $groupContact[$defaultgroup][]  = $contactID;
          }
        }
      }
    foreach($groupContact as $groupID => $contactIDs ){
      CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $groupID, 'Admin', 'Added');
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }  
}