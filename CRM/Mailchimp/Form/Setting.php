<?php

class CRM_Mailchimp_Form_Setting extends CRM_Core_Form {

  const 
    MC_SETTING_GROUP = 'MailChimp Preferences';

   /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() { 
    $currentVer = CRM_Core_BAO_Domain::version(TRUE);
    //if current version is less than 4.4 dont save setting
    if (version_compare($currentVer, '4.4') < 0) {
      CRM_Core_Session::setStatus("You need to upgrade to version 4.4 or above to work with extension Mailchimp","Version:");
    }
  }  

  public static function formRule($params){
    $currentVer = CRM_Core_BAO_Domain::version(TRUE);
    $errors = array();
    if (version_compare($currentVer, '4.4') < 0) {        
      $errors['version_error'] = " You need to upgrade to version 4.4 or above to work with extension Mailchimp";
    }
    return empty($errors) ? TRUE : $errors;
  }

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    $this->addFormRule(array('CRM_Mailchimp_Form_Setting', 'formRule'), $this);

    CRM_Core_Resources::singleton()->addStyleFile('uk.co.vedaconsulting.mailchimp', 'css/mailchimp.css');

    $webhook_url = CRM_Utils_System::url('civicrm/mailchimp/webhook', 'reset=1',  TRUE, NULL, FALSE, TRUE);
    $this->assign( 'webhook_url', 'Webhook URL - '.$webhook_url);

    // Add the API Key Element
    $this->addElement('text', 'api_key', ts('API Key'), array(
      'size' => 48,
    ));    

    // Add the User Security Key Element    
    $this->addElement('text', 'security_key', ts('Security Key'), array(
      'size' => 24,
    ));

    // Add Enable or Disable Debugging
    $enableOptions = array(1 => ts('Yes'), 0 => ts('No'));
    $this->addRadio('enable_debugging', ts('Enable Debugging'), $enableOptions, NULL);

    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Save & Test'),
      ),
    );

    // Add the Buttons.
    $this->addButtons($buttons);

    try {
      // Initially we won't be able to do this as we don't have an API key.
      $api = CRM_Mailchimp_Utils::getMailchimpApi();

      // Check for warnings and output them as status messages.
      $warnings = CRM_Mailchimp_Utils::checkGroupsConfig();
      foreach ($warnings as $message) {
        CRM_Core_Session::setStatus($message);
      }
    }
    catch (Exception $e){
      CRM_Core_Session::setStatus('Could not use the Mailchimp API - ' . $e->getMessage() . ' You will see this message If you have not yet configured your Mailchimp acccount.');
    }
  }

  public function setDefaultValues() {
    $defaults = $details = array();

    $apiKey = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'api_key', NULL, FALSE
    );

    $securityKey = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'security_key', NULL, FALSE
    );

    $enableDebugging = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'enable_debugging', NULL, FALSE
    );
    $defaults['api_key'] = $apiKey;
    $defaults['security_key'] = $securityKey;
    $defaults['enable_debugging'] = $enableDebugging;

    return $defaults;
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return None
   */
  public function postProcess() {
    // Store the submitted values in an array.
    $params = $this->controller->exportValues($this->_name);

    // Save the API Key & Save the Security Key
    if (CRM_Utils_Array::value('api_key', $params) || CRM_Utils_Array::value('security_key', $params)) {
      CRM_Core_BAO_Setting::setItem($params['api_key'],
        self::MC_SETTING_GROUP,
        'api_key'
      );

      CRM_Core_BAO_Setting::setItem($params['security_key'],
        self::MC_SETTING_GROUP,
        'security_key'
      );

      CRM_Core_BAO_Setting::setItem($params['enable_debugging'], self::MC_SETTING_GROUP, 'enable_debugging');

      try {
        $mcClient = CRM_Mailchimp_Utils::getMailchimpApi(TRUE);
        $response  = $mcClient->get('/');
        if (empty($response->data->account_name)) {
          throw new Exception("Could not retrieve account details, although a response was received. Somthing's not right.");
        }

      } catch (Exception $e) {
        CRM_Core_Session::setStatus($e->getMessage());
        return FALSE;
      }

      $message = "Following is the account information received from API callback:<br/>
      <table class='mailchimp-table'>
      <tr><td>Account Name:</td><td>" . htmlspecialchars($response->data->account_name) . "</td></tr>
      <tr><td>Account Email:</td><td>" . htmlspecialchars($response->data->email) . "</td></tr>
      </table>";

      CRM_Core_Session::setStatus($message);
    }
  }
}


