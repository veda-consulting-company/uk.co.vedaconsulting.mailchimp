<?php

class CRM_Mailchimp_Form_Setting extends CRM_Core_Form {
  
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
    
    // Remove or Unsubscribe Preference
    $removeOptions = array(1 => ts('Delete MailChimp Subscriber'), 0 => ts('Unsubscribe MailChimp Subscriber'));
    $this->addRadio('list_removal', ts('List Removal'), $removeOptions, NULL);

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
  }

  public function setDefaultValues() {
    $defaults = $details = array();
    if ($this->_action & CRM_Core_Action::UPDATE) {
      if (isset($this->_id)) {
        $selectQuery = "SELECT api_key, security_key, list_removal FROM mailchimp_civicrm_account WHERE id = %1";
        $selectQueryParams = array(1=>array($this->_id, 'Int'));
        $dao = CRM_Core_DAO::executeQuery($selectQuery, $selectQueryParams);
        if ($dao->fetch()) {
          $defaults['api_key'] = $dao->api_key;
          $defaults['security_key'] = $dao->security_key;
          $defaults['list_removal'] = $dao->list_removal;
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
      
      if ($this->_action & CRM_Core_Action::ADD) {
        $insertQuery = "INSERT INTO `mailchimp_civicrm_account` (`api_key`, `security_key`, `list_removal`)
          VALUES (%1, %2, %3) ON DUPLICATE KEY UPDATE `security_key` = %2, `list_removal` = %3";
        $insertQueryParams = array(1=>array($params['api_key'], 'String'), 2=>array($params['security_key'], 'String'), 3=>array($params['list_removal'], 'Int'));
        CRM_Core_DAO::executeQuery($insertQuery, $insertQueryParams);
      }
      if ($this->_action & CRM_Core_Action::UPDATE) {
        $updateQuery = "UPDATE mailchimp_civicrm_account SET api_key = %1, security_key = %2, list_removal = %3 WHERE id = %4";
        $updateQueryParams = array(1=>array($params['api_key'], 'String'), 2=>array($params['security_key'], 'String'), 3=>array($params['list_removal'], 'Int'), 4=>array($this->_id, 'Int'));
        CRM_Core_DAO::executeQuery($updateQuery, $updateQueryParams);
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

  
