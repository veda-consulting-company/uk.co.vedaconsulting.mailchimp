<?php

class CRM_Mailchimp_Form_Pull extends CRM_Core_Form {
  
  const QUEUE_NAME = 'mc-pull';
  const END_URL    = 'civicrm/mailchimp/pull';
  const END_PARAMS = 'state=done';
  
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
      $task = new CRM_Queue_Task(
        array('CRM_Mailchimp_Form_Pull', 'pullLists'),
        array($listid),
        "Pulling '{$listname}' - Contacts of {$listmembercount['values'][$listid]}"
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
    
    $apikey       = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    $pullcontacts = array();
    $contactID    = array();
    $dc           = 'us'.substr($apikey, -1);
    $url          = 'http://'.$dc.'.api.mailchimp.com/export/1.0/list?apikey='.$apikey.'&id='.$listid;
    $json         = file_get_contents($url);
    $temp         = explode("\n", $json);
    foreach($temp as $key => $value){
      if($key == 0)
        continue;
      $data       = json_decode($value,TRUE);
      $pullcontacts[$listid][] = array(
        'EMAIL'     => $data[0], 
        'FNAME'     => $data[1],
        'LNAME'     => $data[2],
      );  
    }
    
    foreach($pullcontacts as $listid => $vals){
      foreach($vals as $key => $params){
        $contactID[] = CRM_Mailchimp_Utils::updateContactDetails($params);
      }
    }
    return CRM_Queue_Task::TASK_SUCCESS;
  }  
}