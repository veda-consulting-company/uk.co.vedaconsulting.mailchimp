<?php

class CRM_Mailchimp_Form_Setting extends CRM_Core_Form {

  const 
    MC_SETTING_GROUP = 'MailChimp Preferences';
  protected $_id;

   /**
   * Function to pre processing
   *
   * @return None
   * @access public
   */
  function preProcess() { 
    $this->_id = $this->get('id');
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
    if ($this->_action & CRM_Core_Action::DELETE) {
      $this->addButtons(array(
          array(
            'type' => 'next',
            'name' => ts('Delete'),
            'isDefault' => TRUE,
          ),
          array(
            'type' => 'cancel',
            'name' => ts('Cancel'),
          ),
        )
      );
      return;
    }
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
    $this->addButtons(array(
        array(
          'type' => 'upload',
          'name' => ts('Save'),
          'isDefault' => TRUE,
        ),
        array(
          'type' => 'cancel',
          'name' => ts('Cancel'),
        ),
      )
    );

    /*
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
     * 
     */
  }

  public function setDefaultValues() {
    $defaults = $details = array();

    if ($this->_action & CRM_Core_Action::UPDATE) {
      if (isset($this->_id)) {
        $selectQuery = "SELECT api_key, security_key FROM mailchimp_civicrm_account WHERE id = %1";
        $selectQueryParams = array(1=>array($this->_id, 'Int'));
        $dao = CRM_Core_DAO::executeQuery($selectQuery, $selectQueryParams);
        if ($dao->fetch()) {
          $defaults['enable_debugging'] = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'enable_debugging', NULL, FALSE);
          $defaults['api_key'] = $dao->api_key;
          $defaults['security_key'] = $dao->security_key;
        }
      }
    }

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

    if ($this->_action & CRM_Core_Action::DELETE) {
      $deleteQuery = "DELETE FROM mailchimp_civicrm_account WHERE id = %1";
      $deleteQueryParams = array(1=>array($this->_id, 'Int'));
      CRM_Core_DAO::executeQuery($deleteQuery, $deleteQueryParams);
      return;
    }

      try {
        $mcClient = CRM_Mailchimp_Utils::getMailchimpApiFromApiKey($params['api_key']);
        $response  = $mcClient->get('/');
        if (empty($response->data->account_name)) {
          throw new Exception("Could not retrieve account details, although a response was received. Somthing's not right.");
        }

      } catch (Exception $e) {
        CRM_Core_Session::setStatus($e->getMessage());
        return FALSE;
      }
      
    if ($this->_action & CRM_Core_Action::ADD) {
        CRM_Core_BAO_Setting::setItem($params['enable_debugging'], self::MC_SETTING_GROUP, 'enable_debugging');
        $accountName = htmlspecialchars($response->data->account_name);
        $insertQuery = "INSERT INTO `mailchimp_civicrm_account` (`api_key`, `security_key`, `account_name`)
          VALUES (%1, %2, %3) ON DUPLICATE KEY UPDATE `security_key` = %2, `account_name` = %3";
        $insertQueryParams = array(1=>array($params['api_key'], 'String'), 2=>array($params['security_key'], 'String'), 3=>array($accountName, 'String'));
        CRM_Core_DAO::executeQuery($insertQuery, $insertQueryParams);
    }
    if ($this->_action & CRM_Core_Action::UPDATE) {
        $updateQuery = "UPDATE mailchimp_civicrm_account SET api_key = %1, security_key = %2 WHERE id = %3";
        $updateQueryParams = array(1=>array($params['api_key'], 'String'), 2=>array($params['security_key'], 'String'), 3=>array($this->_id, 'Int'));
        CRM_Core_DAO::executeQuery($updateQuery, $updateQueryParams);
        CRM_Core_BAO_Setting::setItem($params['enable_debugging'], self::MC_SETTING_GROUP, 'enable_debugging');
    }

      $message = "Following is the account information received from API callback:<br/>
      <table class='mailchimp-table'>
      <tr><td>Account Name:</td><td>" . htmlspecialchars($response->data->account_name) . "</td></tr>
      <tr><td>Account Email:</td><td>" . htmlspecialchars($response->data->email) . "</td></tr>
      </table>";

      CRM_Core_Session::setStatus($message);
  }
}


