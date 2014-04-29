<?php
class CRM_Mailchimp_Page_WebHook extends CRM_Core_Page {

  function run() {
    
    if(!empty($_POST['data']['list_id']) && !empty($_POST['type'])) {
    	$requestType = $_POST['type'];
    	$requestData = $_POST['data'];
      
      // Mailchimp Subscribe event
      if($requestType == 'subscribe' OR $requestType == 'unsubscribe') {
        
        // Create/Update contact details in CiviCRM
        try {
          $contactID = CRM_Mailchimp_Utils::updateContactDetails($requestData['merges']);
        } 
        catch (Exception $e) {
          return NULL;
        }
        
        // Subscribe/Unsubscribe to related CiviCRM groups 
        self::manageCiviCRMGroupSubcription(array($contactID) , $requestData , $requestType);
      }
      
      // Mailchimp Email Update event
      else if($requestType == 'profile') {
        $contactID = CRM_Mailchimp_Utils::updateContactDetails($requestData['merges']);
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

    // Return the JSON output
    header('Content-type: application/json');
    print json_encode($data);
    CRM_Utils_System::civiExit();
  }
  
  /*
   * Add/Remove contact from CiviCRM Groups mapped with Mailchimp List & Groups 
   */
  static function manageCiviCRMGroupSubcription($contactIDs , $requestData , $action) {
    if (empty($contactIDs) || empty($requestData['merges']['GROUPINGS']) || empty($requestData['list_id']) || empty($action)) {
      return NULL;
    }
    
    $listID = $requestData['list_id'];
    
    $mcGroupings = $requestData['merges']['GROUPINGS'];

    foreach ($mcGroupings as $key => $mcGrouping) {
          
      $mcGroups = @explode(',', $mcGrouping['groups']);

      foreach ($mcGroups as $mcGroupKey => $mcGroupName) {

        // Get Mailchimp group ID group name. Only group name is passed in by Webhooks
        $mcGroupID = CRM_Mailchimp_Utils::getMailchimpGroupIdFromName($listID, trim($mcGroupName));

        // Mailchimp group ID is unavailable
        if (empty($mcGroupID)) {
          break;
        }

        // Find the CiviCRM group mapped with the Mailchimp List and Group
        $civicrmGroupID = CRM_Mailchimp_Utils::getGroupIdForMailchimp($listID, $mcGrouping['id'] , $mcGroupID);

        // No CiviCRM groups mapped to this Mailchimp group
        if (empty($civicrmGroupID)) {
          break;
        }
        
        // Add the contact to the CiviCRM group
        if ($action == 'subscribe') {
          CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $civicrmGroupID, 'Admin', 'Added');
        }
        
        // Remove the contact from CiviCRM group
        if ($action == 'unsubscribe') {
          // Check if the Email address was initially synced from CiviCRM
          $query = "SELECT sync_status as status, count(*) as count FROM civicrm_mc_sync GROUP BY sync_status";
          $dao   = CRM_Core_DAO::executeQuery($query);
          while ($dao->fetch()) {
            $stats[$dao->status] = $dao->count;
          }
          
          CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactIDs, $civicrmGroupID, 'Admin', 'Removed');
        }
      }
    }
  }
  
}
