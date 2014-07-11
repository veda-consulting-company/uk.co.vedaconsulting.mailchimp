<?php
class CRM_Mailchimp_Page_WebHook extends CRM_Core_Page {
    
  const 
    MC_SETTING_GROUP = 'MailChimp Preferences';
     
  function run() {
    $my_key = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'security_key', NULL, FALSE
    );
    
    // Check the key
    try{
      if(!isset($_GET['key']) || $_GET['key'] != $my_key ) {
      throw new Exception("No security key provided or not match");
      }
    }
    catch (Exception $e) {
      CRM_Core_Session::setStatus($e->getMessage());
      return FALSE;
    }      
    // Check the security key to run webhook
    if (isset($_GET['key']) && $_GET['key']==$my_key) {

      if(!empty($_POST['data']['list_id']) && !empty($_POST['type'])) {
        $requestType = $_POST['type'];
        $requestData = $_POST['data'];

        // Mailchimp Subscribe event
        if($requestType == 'subscribe' OR $requestType == 'unsubscribe') {

          // Create/Update contact details in CiviCRM
          $contactID = CRM_Mailchimp_Utils::updateContactDetails($requestData['merges']);
          $contactArray = array($contactID);

          // Subscribe/Unsubscribe to related CiviCRM groups 
          self::manageCiviCRMGroupSubcription($contactArray , $requestData , $requestType);
        }

        // Mailchimp Email Update event
        else if($requestType == 'profile') {
            
          // Create/Update contact details in CiviCRM
          $contactID = CRM_Mailchimp_Utils::updateContactDetails($requestData['merges'], TRUE);
          $contactArray = array($contactID);

          $listID = $requestData['list_id'];
          $mcGroupings = $requestData['merges']['GROUPINGS'];

          // Get the associated CiviCRM Group IDs for the Mailchimp List & Grouping
          $civiGroups = CRM_Mailchimp_Utils::getCiviGroupIdsforMcGroupings($listID, $mcGroupings);
          // Get all CiviCRM groups which are mapped to the Mailchimp List, to which the contact is added to
          $contactGroups = CRM_Mailchimp_Utils::getGroupSubscriptionforMailchimpList($listID , $contactID);

          // Contact is added to any group in Mailchimp, we also need to add the contact to CiviCRM group
          $addedGroups = array_diff($civiGroups , $contactGroups);
          if (!empty($addedGroups)) {
            foreach ($addedGroups as $key => $groupID) {
              CRM_Contact_BAO_GroupContact::addContactsToGroup($contactArray, $groupID, 'Admin', 'Added');
            }
          }

          // Contact is removed from any group in Mailchimp, we also need to remove the contact from CiviCRM group
          $removedGroups = array_diff($contactGroups, $civiGroups);
          if (!empty($removedGroups)) {
            foreach ($removedGroups as $key => $groupID) {
              CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactArray, $groupID, 'Admin', 'Removed');
            }
          }
        }

        // Mailchimp Email Update event
        else if($requestType == 'upemail') {
          // Try to find the email address
          $email = new CRM_Core_BAO_Email();
          $email->get('email', $requestData['old_email']);

          // If the Email was found.
          if (!empty($email->contact_id)) {
            $email->email = $requestData['new_email'];
            $email->save();
          }
        }

        // Mailchimp Cleaned Email  event
        else if($requestType == 'cleaned') {
          // Try to find the email address
          $email = new CRM_Core_BAO_Email();
          $email->get('email', $requestData['email']);

          // If the Email was found.
          if (!empty($email->contact_id)) {
            $email->on_hold = 1;
            $email->holdEmail($email);
          }
        }
      }
    }

    // Return the JSON output
    header('Content-type: application/json');
    print json_encode($data);
    CRM_Utils_System::civiExit();
  } 
  
  /*
   * Add/Remove contact from CiviCRM Groups mapped with Mailchimp List & Groups 
   */
  static function manageCiviCRMGroupSubcription($contactID = array(), $requestData , $action) {
    if (empty($contactID) || empty($requestData['merges']['GROUPINGS']) || empty($requestData['list_id']) || empty($action)) {
      return NULL;
    }
    
    $listID = $requestData['list_id'];
    
    $mcGroupings = $requestData['merges']['GROUPINGS'];
    
    // Get the associated CiviCRM Group IDs for the Mailchimp List & Grouping
    $civiGroups = CRM_Mailchimp_Utils::getCiviGroupIdsforMcGroupings($listID, $mcGroupings);
 
    // Add or Remove from the CiviCRM Groups
    foreach ($civiGroups as $key => $groupID) {
      if ($action == 'subscribe') {
        CRM_Contact_BAO_GroupContact::addContactsToGroup($contactID, $groupID, 'Admin', 'Added');
      }

      // Remove the contact from CiviCRM group
      if ($action == 'unsubscribe') {
        CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactID, $groupID, 'Admin', 'Removed');
        $group           = new CRM_Contact_DAO_Group();
        $group->id       = $groupID;
        $group->find();
        if($group->fetch()){
        //Check smart groups  
          if($group->saved_search_id){
            CRM_Contact_BAO_GroupContactCache::remove($groupID);
          }
        }
      }
    }
  } 
  
}
