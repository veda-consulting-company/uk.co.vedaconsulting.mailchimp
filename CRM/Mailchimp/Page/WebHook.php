<?php
class CRM_Mailchimp_Page_WebHook extends CRM_Core_Page {

  const
    MC_SETTING_GROUP = 'MailChimp Preferences';

  function run() {


    $my_key = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'security_key', NULL, FALSE
    );
    CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Page_WebHook run $my_key= ', $my_key);

    if (CRM_Core_Config::singleton()->userPermissionClass->isModulePermissionSupported() && !CRM_Mailchimp_Permission::check('allow webhook posts')) {
      CRM_Core_Error::fatal();
    }
	
    // Check the key
    // @todo is this a DOS attack vector? seems a lot of work for saying 403, go away, to a robot!
    if(!isset($_GET['key']) || $_GET['key'] != $my_key ) {
      CRM_Core_Error::fatal();
    }

    if (!empty($_POST['data']['list_id']) && !empty($_POST['type'])) {
      $requestType = $_POST['type'];
      $requestData = $_POST['data'];
      // Return if API is set in webhook setting for lists
      $list	      = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
      $webhookoutput  = $list->webhooks($requestData['list_id']);
      if($webhookoutput[0]['sources']['api'] == 1) {
	CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Page_WebHook run API is set in Webhook setting for listID', $requestData['list_id'] );
	return;      
      }

      switch ($requestType) {
       case 'subscribe':
       case 'unsubscribe':
       case 'profile':
        // Create/Update contact details in CiviCRM
        $delay = ( $requestType == 'profile' );
        $contactID = CRM_Mailchimp_Utils::updateContactDetails($requestData['merges'], $delay);
        $contactArray = array($contactID);

          // Subscribe/Unsubscribe to related CiviCRM groups
        self::manageCiviCRMGroupSubcription($contactID, $requestData, $requestType);
		
		      CRM_Mailchimp_Utils::checkDebug('Start - CRM_Mailchimp_Page_WebHook run $_POST= ', $_POST);
          CRM_Mailchimp_Utils::checkDebug('Start - CRM_Mailchimp_Page_WebHook run $contactID= ', $contactID);
          CRM_Mailchimp_Utils::checkDebug('Start - CRM_Mailchimp_Page_WebHook run $requestData= ', $requestData);
          CRM_Mailchimp_Utils::checkDebug('Start - CRM_Mailchimp_Page_WebHook run $requestType= ', $requestType);
          break;

      case 'upemail':
        // Mailchimp Email Update event
        // Try to find the email address
        $email = new CRM_Core_BAO_Email();
        $email->get('email', $requestData['old_email']);

        CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Page_WebHook run- case upemail $requestData[old_email]= ', $requestData['old_email']);

          // If the Email was found.
        if (!empty($email->contact_id)) {
          $email->email = $requestData['new_email'];
          $email->save();
            CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Page_WebHook run- case upemail inside condition $requestData[new_email]= ', $requestData['new_email']);
          }
        break;
      case 'cleaned':
        // Try to find the email address
        $email = new CRM_Core_BAO_Email();
        $email->get('email', $requestData['email']);

        CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Page_WebHook run - case cleaned $requestData[new_email]= ', $requestData['email']);
          // If the Email was found.
        if (!empty($email->contact_id)) {
          $email->on_hold = 1;
          $email->holdEmail($email);
            $email->save();
            CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Page_WebHook run - case cleaned inside condition $email= ', $email);
            CRM_Mailchimp_Utils::checkDebug('CRM_Mailchimp_Page_WebHook run - case cleaned inside condition $requestData[new_email]= ', $requestData['email']);
          }
        break;
        default:
          // unhandled webhook
        CRM_Mailchimp_Utils::checkDebug('End- CRM_Mailchimp_Page_WebHook run $contactID= ', $contactID);
        CRM_Mailchimp_Utils::checkDebug('End- CRM_Mailchimp_Page_WebHook run $requestData= ', $requestData);
        CRM_Mailchimp_Utils::checkDebug('End- CRM_Mailchimp_Page_WebHook run $requestType= ', $requestType);
        CRM_Mailchimp_Utils::checkDebug('End - CRM_Mailchimp_Page_WebHook run $email= ', $email);
      }
    }

    // Return the JSON output
    header('Content-type: application/json');
    $data = NULL;// We should ideally throw some status
    print json_encode($data);
    CRM_Utils_System::civiExit();
  }

  /*
   * Add/Remove contact from CiviCRM Groups mapped with Mailchimp List & Groups
   */
  static function manageCiviCRMGroupSubcription($contactID = array(), $requestData , $action) {
    CRM_Mailchimp_Utils::checkDebug('Start- CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $contactID= ', $contactID);
    CRM_Mailchimp_Utils::checkDebug('Start- CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $requestData= ', $requestData);
    CRM_Mailchimp_Utils::checkDebug('Start- CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $requestType= ', $action);
    
    if (empty($contactID) || empty($requestData['list_id']) || empty($action)) {
      return NULL;
    }
    $listID = $requestData['list_id'];
    $groupContactRemoves = $groupContactAdditions = array();

    // Deal with subscribe/unsubscribe.
    // We need the CiviCRM membership group for this list.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID, $membership_only=TRUE);
    $allGroups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID, $membership_only = FALSE);
    if (!$groups) {
      // This list is not mapped to a group in CiviCRM.
      return NULL;
    }
    $_ = array_keys($groups);
    $membershipGroupID = $_[0];
    if ($action == 'subscribe') {
      $groupContactAdditions[$membershipGroupID][] = $contactID;
    }
    elseif ($action == 'unsubscribe') {
      $groupContactRemoves[$membershipGroupID][] = $contactID;
	  
      $mcGroupings = array();
      foreach (empty($requestData['merges']['GROUPINGS']) ? array() : $requestData['merges']['GROUPINGS'] as $grouping) {
        foreach (explode(', ', $grouping['groups']) as $group) {
          $mcGroupings[$grouping['id']][$group] = 1;
        }
      }
      foreach ($allGroups as $groupID => $details) {
        if ($groupID != $membershipGroupID && $details['is_mc_update_grouping']) {
          if (!empty($mcGroupings[$details['grouping_id']][$details['group_name']])) {
            $groupContactRemoves[$groupID][] = $contactID;
          }
        }
      }
    }

    // Now deal with all the groupings that are mapped to CiviCRM groups for this list
    // and that have the allow MC updates flag set.
    /* Sample groupings from MC:
     *
     *     [GROUPINGS] => Array(
     *       [0] => Array(
     *           [id] => 11365
     *           [name] => CiviCRM
     *           [groups] => special
     *       ))
     * Re-map to mcGroupings[grouping_id][group_name] = 1;
     */
    $mcGroupings = array();
    foreach (empty($requestData['merges']['GROUPINGS']) ? array() : $requestData['merges']['GROUPINGS'] as $grouping) {
      foreach (explode(', ', $grouping['groups']) as $group){
        $mcGroupings[$grouping['id']][$group] = 1;
      }
    }
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID, $membership_only = FALSE);

    CRM_Mailchimp_Utils::checkDebug('Middle- CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $groups ', $groups);
    CRM_Mailchimp_Utils::checkDebug('Middle- CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $mcGroupings ', $mcGroupings);

    foreach ($groups as $groupID=>$details) {
      if ($groupID != $membershipGroupID && $details['is_mc_update_grouping']) {
        // This is a group we allow updates for.
      
	    if (empty($mcGroupings[$details['grouping_id']][$details['group_name']])) {
          $groupContactRemoves[$groupID][] = $contactID;
		  }
        else {
          $groupContactAdditions[$groupID][] = $contactID;
        }
      }
    }

    // Add contacts to groups, if anything to do.
    foreach($groupContactAdditions as $groupID => $contactIDs ) {
      CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $groupID, 'Admin', 'Added');	  
    }

    // Remove contacts from groups, if anything to do.
    foreach($groupContactRemoves as $groupID => $contactIDs ) {
      CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactIDs, $groupID, 'Admin', 'Removed');
    }
		
    CRM_Mailchimp_Utils::checkDebug('End - CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $groupContactRemoves ', $groupContactRemoves);
    CRM_Mailchimp_Utils::checkDebug('End - CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $groupContactAdditions ', $groupContactAdditions);
    CRM_Mailchimp_Utils::checkDebug('End - CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $contactID= ', $contactID);
    CRM_Mailchimp_Utils::checkDebug('End - CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $requestData= ', $requestData);
    CRM_Mailchimp_Utils::checkDebug('End - CRM_Mailchimp_Page_WebHook manageCiviCRMGroupSubcription $requestType= ', $action);
  }
}
