<?php

class CRM_Mailchimp_Form_Setting extends CRM_Core_Form {

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
    $this->add('text', 'mailchimp_api_key', ts('API Key'), array(
      'size' => 48,
    ), TRUE);

    // Add the User Security Key Element
    $this->add('text', 'mailchimp_security_key', ts('Security Key'), array(
      'size' => 24,
    ), TRUE);

    // Add Enable or Disable Debugging
    $enableOptions = array(1 => ts('Yes'), 0 => ts('No'));
    $this->addRadio('mailchimp_enable_debugging', ts('Enable Debugging'), $enableOptions, NULL);


    $result = civicrm_api3('UFGroup', 'get', array(
      'sequential' => 1,
      'group_type' => "Contact",
      'is_active'  => 1,
    ));
    // Add profile selection for syncronization.
    $profileOptions = array(0 => '-- None --');
    if (!empty($result['values'])) {
      foreach ($result['values'] as $profile) {
        $profileOptions[$profile['id']] = $profile['title'];
      }
    }
    $yesNo = array(1 => ts('Yes'), 0 => ts('No'));
    $this->addRadio('mailchimp_sync_checksum', ts('Sync Checksum and Contact ID'), $yesNo, NULL);
    $this->add('select', 'mailchimp_sync_profile', ts('Sync fields from profile'), $profileOptions);
    // Not a setting.
    $this->addRadio('mailchimp_create_merge_fields', ts('Create missing fields on mailchimp lists'), $yesNo, NULL);
    $this->addRadio('mailchimp_sync_tags', ts('Sync Tags?'), $yesNo, NULL);
    // Create the Submit Button.
    $buttons = array(
      array(
        'type' => 'submit',
        'name' => ts('Save & Test'),
      ),
      array(
        'type' => 'cancel',
        'name' => ts('Cancel'),
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

    $apiKey = Civi::settings()->get('mailchimp_api_key');

    $securityKey = Civi::settings()->get('mailchimp_security_key');
    if (empty($securityKey)) {
      $securityKey = CRM_Mailchimp_Utils::generateWebhookKey();
    }

    $enableDebugging = Civi::settings()->get('mailchimp_enable_debugging');
    $syncProfile = Civi::settings()->get('mailchimp_sync_profile');
    $syncChecksum = Civi::settings()->get('mailchimp_sync_checksum');
    $syncTags = Civi::settings()->get('mailchimp_sync_tags');

    $defaults['mailchimp_api_key'] = $apiKey;
    $defaults['mailchimp_security_key'] = $securityKey;
    $defaults['mailchimp_enable_debugging'] = $enableDebugging;
    $defaults['mailchimp_sync_profile'] = $syncProfile;
    $defaults['mailchimp_sync_checksum'] = $syncChecksum;
    $defaults['mailchimp_sync_tags'] = $syncTags;

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
    if (CRM_Utils_Array::value('mailchimp_api_key', $params) || CRM_Utils_Array::value('mailchimp_security_key', $params)) {


      foreach ([
        'mailchimp_api_key',
        'mailchimp_enable_debugging',
        'mailchimp_security_key',
        'mailchimp_sync_checksum',
        'mailchimp_sync_tags',
        'mailchimp_sync_profile',
      ] as $_) {
        Civi::settings()->set($_, $params[$_]);
      }

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

      // Check CMS's permission for (presumably) anonymous users.
      if (!self::checkMailchimpPermission($params['mailchimp_security_key'])) {
        CRM_Core_Session::setStatus(ts("Mailchimp WebHook URL requires 'allow webhook posts' permission to be set for any user roles."));
      }
      // Create Merge Fields from for each list.
      if (!empty($params['mailchimp_sync_profile']) && !empty($params['mailchimp_create_merge_fields'])) {
        $this->createMailchimpMergeFields($params['mailchimp_sync_profile']);
      }
    }
  }

  public static function checkMailchimpPermission($securityKey) {
    if (empty($securityKey)) {
      return FALSE;
    }

    $urlParams = array(
      'reset' => 1,
      'key' => $securityKey,
    );
    $webhook_url = CRM_Utils_System::url('civicrm/mailchimp/webhook', $urlParams,  TRUE, NULL, FALSE, TRUE);

    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $webhook_url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_HTTPHEADER => array(
        "cache-control: no-cache",
        "content-type: application/x-www-form-urlencoded",
        "postman-token: febcecbd-c6f6-6e2e-f0f1-36e1fdc9cafa"
      ),
    ));

    $response = curl_exec($curl);
    $info = curl_getinfo($curl);
    curl_close($curl);

    return ($info['http_code'] != 200) ? FALSE : TRUE;
  }

  public function createMailchimpMergeFields($profileID, $includeChecksum=FALSE) {
    // Get custom fields from profile and create data for creating on MC.
    $ufFieldResult = civicrm_api3('UFField', 'get', ['uf_group_id' => $profileID]);
    $mergeFields = $existingFields = array();
    if (!empty($ufFieldResult['values'])) {
      foreach ($ufFieldResult['values'] as $field) {
        if (0 === strpos($field['field_name'],'custom_') && $field['is_active']) {
          $mergeFields[$field['field_name']] = [
            'tag' => strtoupper($field['field_name']),
            'name' => $field['label'],
            // By default make all fields type text and not public.
            'type' => 'text',
            'public' => FALSE,
          ];
        }
      }
    }
    // Checksum and contact id.
    if (Civi::settings()->get('mailchimp_sync_checksum')) {
      $default = ['type' => 'text', 'public' => FALSE];
      foreach ([
        'contact_id' => 'Contact ID',
        'checksum' => 'Checksum'

      ] as $tag => $name) {
        $mergeFields[$tag] = array_merge(['tag' => strtoupper($tag), 'name' => $name], $default);
      }
    }

    // Get existing fields for each list.
    $listResult = civicrm_api3('Mailchimp', 'getlists');
    $msg = [];
    if (!empty($listResult['values'])) {
      foreach ($listResult['values'] as $listId => $listName) {
        $listCreateFields = $mergeFields;
        $existingFields = $this->getMergeFields($listId);
        foreach($existingFields as $mergeField) {
          $key = strtolower($mergeField->tag);
          if (isset($listCreateFields[$key])) {
            CRM_Core_Session::setStatus("Field $key exists on $listName, skipping");
            unset($listCreateFields[$key]);
          }
        }
        // Create the merge field for the list.
        foreach ($listCreateFields as $createField) {
          $response = $this->createMergeField($listId, $createField);
          if ($response && $response->http_code == 200) {
            $name = $response->data->name;
            $tag = $response->data->tag;
            CRM_Core_Session::setStatus( "$name ($tag) created for $listName.", "Created field On Mailchimp");
          }
        }
      }
    }
  }

  /**
   * Get Merge Field definitions for a Mailchimp Group.
   * @param string $listId
   * @return array
   */
  protected function getMergeFields($listId) {
    $mcClient = CRM_Mailchimp_Utils::getMailchimpApi(TRUE);
    $path = '/lists/' . $listId . '/merge-fields';
    $response = $mcClient->get($path);
    return $response->data->merge_fields;
  }

  protected function createMergeField($listId, $data) {
    $mcClient = CRM_Mailchimp_Utils::getMailchimpApi(TRUE);
    $path = '/lists/' . $listId . '/merge-fields';
    try {
      $response = $mcClient->post($path, $data);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message($e->getMessage());
      CRM_Core_Error::debug_var(__CLASS__ . __FUNCTION__, $response);
    }
    return $response;
  }
}


