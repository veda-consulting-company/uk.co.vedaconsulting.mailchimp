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
    if (empty($groupIDs)) {
      $groupIDs = self::getGroupIDsToSync();
    }
    if (!empty($groupIDs)) {
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
  static function updateContactDetails($params) {
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
  
}
