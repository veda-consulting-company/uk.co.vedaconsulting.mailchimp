<?php

class CRM_Mailchimp_Form_Setting extends CRM_Core_Form {

  const 
    MC_SETTING_GROUP = 'MailChimp Preferences';

  /**
   * Function to actually build the form
   *
   * @return None
   * @access public
   */
  public function buildQuickForm() {
    
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
    
    // Create the Default Group
    $group = array('' => ts('- select group -')) + CRM_Core_PseudoConstant::group();    
    $this->add('select', 'default_group', ts('Default group'),$group );
   
    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Save & Test'),
      ),
    );

    // Add the Buttons.
    $this->addButtons($buttons);
  }

  public function setDefaultValues() {
    $defaults = $details = array();

    $apiKey = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'api_key', NULL, FALSE
    );
    
    $securityKey = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'security_key', NULL, FALSE
    );
    
    $defaultGroup = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'default_group', NULL, FALSE
    );
    
    $defaults['api_key'] = $apiKey;
    $defaults['security_key'] = $securityKey;
    $defaults['default_group'] = $defaultGroup;
    
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
      
    // Save the API Key & Save the Security Key & Save the Default Group
    if (CRM_Utils_Array::value('api_key', $params) || CRM_Utils_Array::value('security_key', $params) || CRM_Utils_Array::value('default_group', $params, NULL)) {
      CRM_Core_BAO_Setting::setItem($params['api_key'],
        self::MC_SETTING_GROUP,
        'api_key'
      );
      
      CRM_Core_BAO_Setting::setItem($params['security_key'],
        self::MC_SETTING_GROUP,
        'security_key'
      );  
      
      CRM_Core_BAO_Setting::setItem($params['default_group'],
        self::MC_SETTING_GROUP,
        'default_group'
      ); 
      try {
        $mcClient = new Mailchimp($params['api_key']);
        $mcHelper = new Mailchimp_Helper($mcClient);
        $details  = $mcHelper->accountDetails();
      } catch (Mailchimp_Invalid_ApiKey $e) {
        CRM_Core_Session::setStatus($e->getMessage());
        return FALSE;
      } catch (Mailchimp_HttpError $e) {
        CRM_Core_Session::setStatus($e->getMessage());
        return FALSE;
      }
      
         
        $message = "Following is the account information received from API callback:<br/>
        <table class='mailchimp-table'>
        <tr><td>Company:</td><td>{$details['contact']['company']}</td></tr>
        <tr><td>First Name:</td><td>{$details['contact']['fname']}</td></tr>
        <tr><td>Last Name:</td><td>{$details['contact']['lname']}</td></tr>
        </table>";
        CRM_Core_Session::setStatus($message);
    }
  }
}
