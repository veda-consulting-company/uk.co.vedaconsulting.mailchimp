<?php
// @todo I don't think this handles remove from smart groups.
class CRM_Mailchimp_Page_WebHook extends CRM_Core_Page {

  const
    MC_SETTING_GROUP = 'MailChimp Preferences';

  function run() {

    $my_key = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
      'security_key', NULL, FALSE
    );

    // Useful for debugging: file_put_contents('/tmp/mc-dump-' . time(), serialize($_POST) . "\n\n" . print_r($_POST,1));
    //$_GET['key']=$my_key;
    //$_POST=unserialize();

    // Check the key
    // @todo is this a DOS attack vector? seems a lot of work for saying 403, go away, to a robot!
    if(!isset($_GET['key']) || $_GET['key'] != $my_key ) {
      CRM_Core_Session::setStatus("No security key provided or not match");
      return FALSE;
    }

    if(!empty($_POST['data']['list_id']) && !empty($_POST['type'])) {
      $requestType = $_POST['type'];
      $requestData = $_POST['data'];

      switch ($requestType) {
      case 'subscribe':
      case 'unsubscribe':
      case 'profile':
        // Create/Update contact details in CiviCRM
        $delay = ( $requestType == 'profile' );
        $contactID = CRM_Mailchimp_Utils::updateContactDetails($requestData['merges'], $delay);
        $contactArray = array($contactID);

        // Subscribe/Unsubscribe to related CiviCRM groups
        self::manageCiviCRMGroupSubcription($contactID , $requestData , $requestType);
        break;

      case 'upemail':
        // Mailchimp Email Update event
        // Try to find the email address
        $email = new CRM_Core_BAO_Email();
        $email->get('email', $requestData['old_email']);

        // If the Email was found.
        if (!empty($email->contact_id)) {
          $email->email = $requestData['new_email'];
          $email->save();
        }
        break;
      case 'cleaned':
        // Try to find the email address
        $email = new CRM_Core_BAO_Email();
        $email->get('email', $requestData['email']);

        // If the Email was found.
        if (!empty($email->contact_id)) {
          $email->on_hold = 1;
          $email->holdEmail($email);
        }
        break;
      default:
        // unhandled webhook
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
    if (empty($contactID) || empty($requestData['list_id']) || empty($action)) {
      return NULL;
    }
    $listID = $requestData['list_id'];
    $groupContactRemoves = $groupContactAdditions = array();

    // Deal with subscribe/unsubscribe.
    // We need the CiviCRM membership group for this list.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID, $membership_only=TRUE);
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
      $groupContactAdditions[$membershipGroupID][] = $contactID;
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
      foreach (explode(', ', $grouping['groups']) as $group);
      $mcGroupings[$grouping['id']][$group] = 1;
    }

    $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $listID, $membership_only=FALSE);
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

    // Make changes.
    if ($groupContactAdditions) {
      // We have some contacts to add into groups...
      foreach($groupContactAdditions as $groupID => $contactIDs ) {
        CRM_Contact_BAO_GroupContact::addContactsToGroup($contactIDs, $groupID, 'Admin', 'Added');
      }
    }

    if ($groupContactRemoves) {
      // We have some contacts to add into groups...
      foreach($groupContactRemoves as $groupID => $contactIDs ) {
        CRM_Contact_BAO_GroupContact::removeContactsFromGroup($contactIDs, $groupID, 'Admin', 'Removed');
      }
    }

    /*
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
     */
  }
}
