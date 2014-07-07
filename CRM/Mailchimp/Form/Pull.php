<?php

class CRM_Mailchimp_Form_Pull extends CRM_Core_Form {
  
  const QUEUE_NAME = 'mc-pull';
  const END_URL    = 'civicrm/mailchimp/pull';
  const END_PARAMS = 'state=done';
  const BATCH_COUNT = 10;

  public function buildQuickForm() {
    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Pull'),
      ),
    );
    // Add the Buttons.
    $this->addButtons($buttons);
  }
  
  public function postProcess() {
    $runner = self::getRunner();
    if ($runner) {
      // Run Everything in the Queue via the Web.
      $runner->runAllViaWeb();
    } else {
      $setting_url = CRM_Utils_System::url('civicrm/mailchimp/settings', 'reset=1',  TRUE, NULL, FALSE, TRUE);
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
     
    $lists            = array();
    $listmembercount  = array();
    $listname         = array();
    $listmembercount  = civicrm_api('Mailchimp' , 'getmembercount' , array('version' => 3));
    $lists            = civicrm_api('Mailchimp' , 'getlists' , array('version' => 3));

    foreach ($lists['values'] as $listid => $listname) {
      $count = $listmembercount['values'][$listid];
      $task = new CRM_Queue_Task(
        array('CRM_Mailchimp_Form_Pull', 'pullLists'),
        array($listid),
        "Pulling '{$listname}' - Contacts of {$count}"
      );
        
      // Add the Task to the Queu
      $queue->createItem($task);
    }

    if (!empty($lists['values'])) {
      // Setup the Runner
      $runner = new CRM_Queue_Runner(array(
        'title' => ts('Mailchimp Pull'),
        'queue' => $queue,
        'errorMode'=> CRM_Queue_Runner::ERROR_ABORT,
        'onEndUrl' => CRM_Utils_System::url(self::END_URL, self::END_PARAMS, TRUE, NULL, FALSE),
      ));
      return $runner;
    }
    return FALSE;
  }
  
  static function pullLists(CRM_Queue_TaskContext $ctx, $listid) {
    
    $apikey         = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    $contactColumns = array();
    $contacts       = array();
    $columnNames    = array();
    $dc             = 'us'.substr($apikey, -1);
    $url            = 'http://'.$dc.'.api.mailchimp.com/export/1.0/list?apikey='.$apikey.'&id='.$listid;
    $json           = file_get_contents($url);
    $temp           = explode("\n", $json);
    foreach($temp as $key => $value){
      if($key == 0){
        $contactColumns = json_decode($value,TRUE);
        continue;
      }
      $data       = json_decode($value,TRUE);
      $contacts[$listid][] =  array_combine($contactColumns, $data);
    }
    $groupContact = array();
    $mcGroups = civicrm_api('Mailchimp' , 'getgroupid' , array('version' => 3,'id'=>$listid));
    foreach($contacts[$listid] as $key => $contact){
      $updateParams = array(
        'EMAIL' =>  $contact['Email Address'],
        'FNAME' =>  $contact['First Name'],
        'LNAME' =>  $contact['Last Name'],
      );
      $contactID    = CRM_Mailchimp_Utils::updateContactDetails($updateParams);
      foreach ($contact as $parms => $value){
        if(!empty($mcGroups)){
          foreach ($mcGroups['values'] as $mcGroupDetails){
            if (in_array($value, $mcGroupDetails)) {
              $civiGroupID  = CRM_Mailchimp_Utils::getGroupIdForMailchimp($listid, $mcGroupDetails['groupingid'] , $mcGroupDetails['groupid']);
              if(!empty($contactID) && !empty($civiGroupID))
              $groupContact[$civiGroupID][] = $contactID;
            }
          }
        }
      }
    }
    foreach($groupContact as $groupID => $contactIDs ){
      CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $groupID, 'Admin', 'Added');
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }  
}