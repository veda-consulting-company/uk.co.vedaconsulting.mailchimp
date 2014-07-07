<?php

class CRM_Mailchimp_Utils {
  
  const 
    MC_SETTING_GROUP = 'MailChimp Preferences';
  static function mailchimp() {
    $apiKey   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    $mcClient = new Mailchimp($apiKey);
    return $mcClient;
  }

  static function getGroupsToSync($ids = array()) {
    $groups = array();

    if (!empty($ids)) {
      $groupIDs = implode(',', $ids);
      $whereClause = "entity_id IN ($groupIDs)";
    } else {
      $whereClause = "mc_list_id IS NOT NULL AND mc_list_id <> ''";
    }

    $query  = "
      SELECT  entity_id, mc_list_id, mc_grouping_id, mc_group_id, cg.title as civigroup_title
      FROM    civicrm_value_mailchimp_settings mcs
      INNER JOIN civicrm_group cg ON mcs.entity_id = cg.id
      WHERE   $whereClause";
    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      $groups[$dao->entity_id] = 
        array(
          'list_id'     => $dao->mc_list_id,
          'grouping_id' => $dao->mc_grouping_id,
          'group_id'    => $dao->mc_group_id,
          'group_name'  => CRM_Mailchimp_Utils::getMCGroupName($dao->mc_list_id, $dao->mc_grouping_id, $dao->mc_group_id),
          'civigroup_title' => $dao->civigroup_title,
        );
    }
    return $groups;
  }

  static function getGroupIDsToSync() {
    $groupIDs = self::getGroupsToSync();
    return array_keys($groupIDs);
  }

  static function getMemberCountForGroupsToSync($groupIDs = array()) {
    $group = new CRM_Contact_DAO_Group();
    foreach ($groupIDs as $key => $value) {
    $group->id  = $value;      
    }
    $group->find(TRUE);
    
    if (empty($groupIDs)) {
      $groupIDs = self::getGroupIDsToSync();
    }
    if(!empty($groupIDs) && $group->saved_search_id){
      $groupIDs = implode(',', $groupIDs);
      $smartGroupQuery = " 
                  SELECT count(*)
                  FROM civicrm_group_contact_cache smartgroup_contact
                  WHERE smartgroup_contact.group_id IN ($groupIDs)";
      return CRM_Core_DAO::singleValueQuery($smartGroupQuery);
    }
    else if (!empty($groupIDs)) {
      $groupIDs = implode(',', $groupIDs);
      $query    = "
        SELECT  count(*)
        FROM    civicrm_group_contact
        WHERE   status = 'Added' AND group_id IN ($groupIDs)";
      return CRM_Core_DAO::singleValueQuery($query);
    }
    return 0;
  }

  static function getMCGroupName($listID, $groupingID, $groupID) {
    if (empty($listID) || empty($groupingID) || empty($groupID)) {
      return NULL;
    }

    static $mapper = array();
    if (!array_key_exists($listID, $mapper)) {
      $mapper[$listID] = array();

      $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
      try {
        $results = $mcLists->interestGroupings($listID);
      } 
      catch (Exception $e) {
        return NULL;
      }

      foreach ($results as $grouping) {
        foreach ($grouping['groups'] as $group) {
          $mapper[$listID][$grouping['id']][$group['id']] = $group['name'];
        }
      }
    }
    return $mapper[$listID][$groupingID][$groupID];
  }
  
  /*
   * Get Mailchimp group ID group name
   */
  static function getMailchimpGroupIdFromName($listID, $groupName) {
    if (empty($listID) || empty($groupName)) {
      return NULL;
    }

    $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
    try {
      $results = $mcLists->interestGroupings($listID);
    } 
    catch (Exception $e) {
      return NULL;
    }
    
    foreach ($results as $grouping) {
      foreach ($grouping['groups'] as $group) {
        if ($group['name'] == $groupName) {
          return $group['id'];
        }
      }
    }
  }
  
  static function getGroupIdForMailchimp($listID, $groupingID, $groupID) {
    if (empty($listID) || empty($groupingID) || empty($groupID)) {
      return NULL;
    }

    $query  = "
      SELECT  entity_id
      FROM    civicrm_value_mailchimp_settings mcs
      WHERE   mc_list_id = %1 AND mc_grouping_id = %2 AND mc_group_id = %3";
    $params = 
        array(
          '1' => array($listID , 'String'),
          '2' => array($groupingID , 'String'),
          '3' => array($groupID , 'String'),
        );
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    if ($dao->fetch()) {
      return $dao->entity_id;
    }
  }
  
  /*
   * Create/Update contact details in CiviCRM, based on the data from Mailchimp webhook
   */
  static function updateContactDetails($params, $delay = FALSE) {
    if (empty($params)) {
      return NULL;
    }

    $contactParams = 
        array(
          'version'       => 3,
          'contact_type'  => 'Individual',
          'first_name'    => $params['FNAME'],
          'last_name'     => $params['LNAME'],
          'email'         => $params['EMAIL'],
        );
    
    if($delay){
      //To avoid a new duplicate contact to be created as both profile and upemail events are happening at the same time
      sleep(20);
    }
    $email = new CRM_Core_BAO_Email();
		$email->get('email', $params['EMAIL']);
    
    // If the Email was found.
    if (!empty($email->contact_id)) {
      $contactParams['id'] = $email->contact_id;
    }
    
    // Create/Update Contact details
    $contactResult = civicrm_api('Contact' , 'create' , $contactParams);
    
    return $contactResult['id'];
  }
  
  /*
   * Function to get the associated CiviCRM Groups IDs for the Grouping array sent from Mialchimp Webhook
   */
  static function getCiviGroupIdsforMcGroupings($listID, $mcGroupings) {
    if (empty($listID) || empty($mcGroupings)) {
      return NULL;
    }
    
    $civiGroups = array();
    foreach ($mcGroupings as $key => $mcGrouping) {
      $mcGroups = @explode(',', $mcGrouping['groups']);
      foreach ($mcGroups as $mcGroupKey => $mcGroupName) {
        // Get Mailchimp group ID from group name. Only group name is passed in by Webhooks
        $mcGroupID = self::getMailchimpGroupIdFromName($listID, trim($mcGroupName));
        // Mailchimp group ID is unavailable
        if (empty($mcGroupID)) {
          break;
        }
        
        // Find the CiviCRM group mapped with the Mailchimp List and Group
        $civiGroupID = self::getGroupIdForMailchimp($listID, $mcGrouping['id'] , $mcGroupID);
        if (!empty($civiGroupID)) {
          $civiGroups[] = $civiGroupID;
        }
        else{
          $civiGroups[] = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP,
            'default_group', NULL, FALSE
          );
        }
      }
    }   
    return $civiGroups;
  } 
  
  /*
   * Function to get CiviCRM Groups for the specific Mailchimp list in which the Contact is Added to
   */
  static function getGroupSubscriptionforMailchimpList($listID, $contactID) {
    if (empty($listID) || empty($contactID)) {
      return NULL;
    }
    
    $civiMcGroups = array();
    $query  = "
      SELECT  entity_id
      FROM    civicrm_value_mailchimp_settings mcs
      WHERE   mc_list_id = %1";
    $params = array('1' => array($listID, 'String'));
    
    $dao = CRM_Core_DAO::executeQuery($query ,$params);
    while ($dao->fetch()) {
      $groupContact = new CRM_Contact_BAO_GroupContact();
      $groupContact->group_id = $dao->entity_id;
      $groupContact->contact_id = $contactID;
      $groupContact->whereAdd("status = 'Added'");
      $groupContact->find();
      if ($groupContact->fetch()) {
        $civiMcGroups[] = $dao->entity_id;
      }
    }
   
    return $civiMcGroups;
  }
  
   /*
   * Function to delete Mailchimp contact for given CiviCRM email ID
   */
  static function deleteMCEmail($emailId = array() ) {
    if (empty($emailId)) {
      return NULL;
    }
    $toDelete = array();
    $listID = array();
    $email = NULL;
    $query = NULL;
    
    if (!empty($emailId)) {      
      $emailIds = implode(',', $emailId);      
      $query = "SELECT * FROM civicrm_mc_sync WHERE email_id IN ($emailIds) ORDER BY id DESC";
    }
    $dao = CRM_Core_DAO::executeQuery($query);       
        
    while ($dao->fetch()) {
      $leidun = $dao->mc_leid;
      $euidun = $dao->mc_euid;
      $listID = $dao->mc_list_id;   
      $mc_group = $dao->mc_group;
      $email_id = $dao->email_id;
      $email = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Email', $dao->email_id, 'email', 'id');
 
      $toDelete[$listID]['batch'][] = array(
        'email' => $email,
        'euid'  => $euidun,
        'leid'  => $leidun,       
      );      
                 
      $params = array(
        'email_id'   => $dao->email_id,
        'mc_list_id' => $listID,
        'mc_group'   => $mc_group,
        'mc_euid'  => $euidun,
        'mc_leid' => $leidun,            
        'sync_status' => 'Removed'
      );
      
      CRM_Mailchimp_BAO_MCSync::create($params);   
    } 
    
    foreach ($toDelete as $listID => $vals) {
      // sync contacts using batchunsubscribe
      $mailchimp = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
      $results   = $mailchimp->batchUnsubscribe( 
        $listID,
        $vals['batch'], 
        TRUE,
        TRUE, 
        TRUE
      );  
    }       
    return $toDelete;
  }
  
   /*
   * Function to call syncontacts with smart groups and static groups
   */
  static function getGroupContactObject($groupID, $start) {
    $group           = new CRM_Contact_DAO_Group();
    $group->id       = $groupID;
    $group->find();

    if($group->fetch()){
      //Check smart groups  
      if($group->saved_search_id){
        $groupContactCache = new CRM_Contact_BAO_GroupContactCache();
        $groupContactCache->group_id = $groupID;
        $groupContactCache->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        $groupContactCache->find(); 
        return $groupContactCache;     
      }
      else {
        $groupContact = new CRM_Contact_BAO_GroupContact();
        $groupContact->group_id = $groupID;
        $groupContact->whereAdd("status = 'Added'");
        $groupContact->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        $groupContact->find();    
        return $groupContact;
      }
    }
    return FALSE;
  }
}