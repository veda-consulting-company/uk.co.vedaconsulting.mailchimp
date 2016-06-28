<?php

class CRM_Mailchimp_Utils {

  const MC_SETTING_GROUP = 'MailChimp Preferences';
  static function mailchimp() {
    $apiKey   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    $mcClient = new Mailchimp($apiKey);
    //CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils mailchimp $mcClient', $mcClient);
    return $mcClient;
  }

  /**
   * Look up an array of CiviCRM groups linked to Maichimp groupings.
   *
   * Indexed by CiviCRM groupId, including:
   *
   * - list_id    (MC)
   * - grouping_id(MC)
   * - group_id   (MC)
   * - is_mc_update_grouping (bool) - is the subscriber allowed to update this via MC interface?
   * - group_name (MC)
   * - grouping_name (MC)
   * - civigroup_title
   * - civigroup_uses_cache boolean
   *
   * @param $groupIDs mixed array of CiviCRM group Ids to fetch data for; or empty to return ALL mapped groups.
   * @param $mc_list_id mixed Fetch for a specific Mailchimp list only, or null.
   * @param $membership_only bool. Only fetch mapped membership groups (i.e. NOT linked to a MC grouping).
   *
   */
  static function getGroupsToSync($groupIDs = array(), $mc_list_id = null, $membership_only = FALSE) {

    $params = $groups = $temp = array();
	
	foreach ($groupIDs as $value) {
        if($value){
          $temp[] = $value;
        }
    }
	 
	$groupIDs = $temp;

    if (!empty($groupIDs)) {
      $groupIDs = implode(',', $groupIDs);
      $whereClause = "entity_id IN ($groupIDs)";
    } else {
      $whereClause = "1 = 1";
    }

    $whereClause .= " AND mc_list_id IS NOT NULL AND mc_list_id <> ''";

    if ($mc_list_id) {
      // just want results for a particular MC list.
      $whereClause .= " AND mc_list_id = %1 ";
      $params[1] = array($mc_list_id, 'String');
    }

    if ($membership_only) {
      $whereClause .= " AND (mc_grouping_id IS NULL OR mc_grouping_id = '')";
    }

    $query  = "
      SELECT  entity_id, mc_list_id, mc_grouping_id, mc_group_id, is_mc_update_grouping, cg.title as civigroup_title, cg.saved_search_id, cg.children
 FROM    civicrm_value_mailchimp_settings mcs
      INNER JOIN civicrm_group cg ON mcs.entity_id = cg.id
      WHERE $whereClause";
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    while ($dao->fetch()) {
      $groups[$dao->entity_id] =
        array(
          'list_id'               => $dao->mc_list_id,
          'grouping_id'           => $dao->mc_grouping_id,
          'group_id'              => $dao->mc_group_id,
          'is_mc_update_grouping' => $dao->is_mc_update_grouping,
          'group_name'            => CRM_Mailchimp_Utils::getMCGroupName($dao->mc_list_id, $dao->mc_grouping_id, $dao->mc_group_id),
          'grouping_name'         => CRM_Mailchimp_Utils::getMCGroupingName($dao->mc_list_id, $dao->mc_grouping_id),
          'civigroup_title'       => $dao->civigroup_title,
          'civigroup_uses_cache'    => (bool) (($dao->saved_search_id > 0) || (bool) $dao->children),
        );
    }

    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupsToSync $groups', $groups);

    return $groups;
  }

  static function getGroupIDsToSync() {
    $groupIDs = self::getGroupsToSync();
    return array_keys($groupIDs);
  }

  static function getMemberCountForGroupsToSync($groupIDs = array()) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupsToSync $groupIDs', $groupIDs);
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
      $count = CRM_Core_DAO::singleValueQuery($smartGroupQuery);
      CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMemberCountForGroupsToSync $count', $count);
      return $count;
				  
      
    }
    else if (!empty($groupIDs)) {
      $groupIDs = implode(',', $groupIDs);
      $query    = "
        SELECT  count(*)
        FROM    civicrm_group_contact
        WHERE   status = 'Added' AND group_id IN ($groupIDs)";
      $count = CRM_Core_DAO::singleValueQuery($query);
      CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMemberCountForGroupsToSync $count', $count);
      return $count;
    }
    return 0;
  }

  /**
   * return the group name for given list, grouping and group
   *
   */
  static function getMCGroupName($listID, $groupingID, $groupID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCGroupName $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCGroupName $groupingID', $groupingID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCGroupName $groupID', $groupID);

    $info = static::getMCInterestGroupings($listID);

    // Check list, grouping, and group exist
    if (empty($info[$groupingID]['groups'][$groupID])) {
      return NULL;
    }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMCGroupName $info', $info[$groupingID]['groups'][$groupID]['name']);
    
    return $info[$groupingID]['groups'][$groupID]['name'];
  }

  /**
   * Return the grouping name for given list, grouping MC Ids.
   */
  static function getMCGroupingName($listID, $groupingID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCGroupingName $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCGroupingName $groupingID', $groupingID);

    $info = static::getMCInterestGroupings($listID);

    // Check list, grouping, and group exist
    if (empty($info[$groupingID])) {
      return NULL;
    }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMCGroupingName $info ', $info[$groupingID]['name']);
    
    return $info[$groupingID]['name'];
  }

  /**
   * Get interest groupings for given ListID (cached).
   *
   * Nb. general API function used by several other helper functions.
   *
   * Returns an array like {
   *   [groupingId] => array(
   *     'id' => [groupingId],
   *     'name' => ...,
   *     'form_field' => ...,    (not v interesting)
   *     'display_order' => ..., (not v interesting)
   *     'groups' => array(
   *        [MC groupId] => array(
   *          'id' => [MC groupId],
   *          'bit' => ..., ?
   *          'name' => ...,
   *          'display_order' => ...,
   *          'subscribers' => ..., ?
   *          ),
   *        ...
   *        ),
   *   ...
   *   ) 
   *
   */
  static function getMCInterestGroupings($listID) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMCInterestGroupings $listID', $listID);

    if (empty($listID)) {
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
      /*  re-map $result for quick access via grouping_id and groupId
       *
       *  Nb. keys for grouping:
       *  - id
       *  - name
       *  - form_field    (not v interesting)
       *  - display_order (not v interesting)
       *  - groups: array as follows, keyed by GroupId
       *
       *  Keys for each group
       *  - id
       *  - bit ?
       *  - name
       *  - display_order
       *  - subscribers ?
       *
       */
      foreach ($results as $grouping) {
        

        $mapper[$listID][$grouping['id']] = $grouping;
        unset($mapper[$listID][$grouping['id']]['groups']);
        foreach ($grouping['groups'] as $group) {
          $mapper[$listID][$grouping['id']]['groups'][$group['id']] = $group;
        }
      }
    }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMCInterestGroupings $mapper', $mapper[$listID]);
    return $mapper[$listID];
  }

  /*
   * Get Mailchimp group ID group name
   */
  static function getMailchimpGroupIdFromName($listID, $groupName) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMailchimpGroupIdFromName $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getMailchimpGroupIdFromName $groupName', $groupName);

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
          CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getMailchimpGroupIdFromName= ', $group['id']);
          return $group['id'];
        }
      }
    }
  }
  
  static function getGroupIdForMailchimp($listID, $groupingID, $groupID) {

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupIdForMailchimp $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupIdForMailchimp $groupingID', $groupingID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupIdForMailchimp $groupID', $groupID);

    if (empty($listID)) {
      return NULL;
    }
    
    if (!empty($groupingID) && !empty($groupID)) {
      $whereClause = "mc_list_id = %1 AND mc_grouping_id = %2 AND mc_group_id = %3";
    } else {
      $whereClause = "mc_list_id = %1";
    }

    $query  = "
      SELECT  entity_id
      FROM    civicrm_value_mailchimp_settings mcs
      WHERE   $whereClause";
    $params = 
        array(
          '1' => array($listID , 'String'),
          '2' => array($groupingID , 'String'),
          '3' => array($groupID , 'String'),
        );
    $dao = CRM_Core_DAO::executeQuery($query, $params);
    if ($dao->fetch()) {
      CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupIdForMailchimp $dao->entity_id', $dao->entity_id);
      return $dao->entity_id;
    }
    
  }
  
  /**
   * Try to find out already if we can find a unique contact for this e-mail
   * address.
   */
  static function guessCidsMailchimpContacts() {
    // If an address is unique, that's the one we need.
    CRM_Core_DAO::executeQuery(
        "UPDATE tmp_mailchimp_push_m m
          JOIN civicrm_email e1 ON m.email = e1.email
          LEFT OUTER JOIN civicrm_email e2 ON m.email = e2.email AND e1.id <> e2.id
          SET m.cid_guess = e1.contact_id
          WHERE e2.id IS NULL")->free();
    // In the other case, if we find a unique contact with matching
    // first name, last name and e-mail address, it is probably the one we
    // are looking for as well.
    CRM_Core_DAO::executeQuery(
       "UPDATE tmp_mailchimp_push_m m
          JOIN civicrm_email e1 ON m.email = e1.email
          JOIN civicrm_contact c1 ON e1.contact_id = c1.id AND c1.first_name = m.first_name AND c1.last_name = m.last_name 
          LEFT OUTER JOIN civicrm_email e2 ON m.email = e2.email
          LEFT OUTER JOIN civicrm_contact c2 on e2.contact_id = c2.id AND c2.first_name = m.first_name AND c2.last_name = m.last_name AND c2.id <> c1.id
          SET m.cid_guess = e1.contact_id
          WHERE m.cid_guess IS NULL AND c2.id IS NULL")->free();
  }

  /**
   * Update first name and last name of the contacts of which we already
   * know the contact id.
   */
  static function updateGuessedContactDetails() {
    // In theory I could do this with one SQL join statement, but this way
    // we would bypass user defined hooks. So I will use the API, but only
    // in the case that the names are really different. This will save
    // some expensive API calls. See issue #188.

    $dao = CRM_Core_DAO::executeQuery(
      "SELECT c.id, m.first_name, m.last_name
       FROM tmp_mailchimp_push_m m
       JOIN civicrm_contact c ON m.cid_guess = c.id
       WHERE m.first_name NOT IN ('', COALESCE(c.first_name, ''))
          OR m.last_name  NOT IN ('', COALESCE(c.last_name,  ''))");

    while ($dao->fetch()) {
      $params = array('id' => $dao->id);
      if ($dao->first_name) {
        $params['first_name'] = $dao->first_name;
      }
      if ($dao->last_name) {
        $params['last_name'] = $dao->last_name;
      }
      civicrm_api3('Contact', 'create', $params);
    }
    $dao->free();
  }

  /*
   * Create/Update contact details in CiviCRM, based on the data from Mailchimp webhook
   */
  static function updateContactDetails(&$params, $delay = FALSE) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils updateContactDetails $params', $params);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils updateContactDetails $delay', $delay);

    if (empty($params)) {
      return NULL;
    }
    $params['status'] = array('Added' => 0, 'Updated' => 0);
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
    $contactids = CRM_Mailchimp_Utils::getContactFromEmail($params['EMAIL']);
    
    if(count($contactids) > 1) {
       CRM_Core_Error::debug_log_message( 'Mailchimp Pull/Webhook: Multiple contacts found for the email address '. print_r($params['EMAIL'], true), $out = false );
       return NULL;
    }
    if(count($contactids) == 1) {
      $contactParams  = CRM_Mailchimp_Utils::updateParamsExactMatch($contactids, $params);
      $params['status']['Updated']  = 1;
    }
    if(empty($contactids)) {
      //check for contacts with no primary email address
      $id  = CRM_Mailchimp_Utils::getContactFromEmail($params['EMAIL'], FALSE);

      if(count($id) > 1) {
        CRM_Core_Error::debug_log_message( 'Mailchimp Pull/Webhook: Multiple contacts found for the email address which is not primary '. print_r($params['EMAIL'], true), $out = false );
        return NULL;
      }
      if(count($id) == 1) {
        $contactParams  = CRM_Mailchimp_Utils::updateParamsExactMatch($id, $params);
        $params['status']['Updated']  = 1;
      }
      // Else create new contact
      if(empty($id)) {
        $params['status']['Added']  = 1;
      }
      
    }
    // Create/Update Contact details
    $contactResult = civicrm_api('Contact' , 'create' , $contactParams);

    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils updateContactDetails $contactID', $contactResult['id']);

    return $contactResult['id'];
  }
  
  static function getContactFromEmail($email, $primary = TRUE) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getContactFromEmail $email', $email);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getContactFromEmail $primary', $primary);

    $primaryEmail  = 1;
    if(!$primary) {
     $primaryEmail = 0;
    }
    $contactids = array();
    $query = "
      SELECT `contact_id` FROM civicrm_email ce
      INNER JOIN civicrm_contact cc ON ce.`contact_id` = cc.id
      WHERE ce.email = %1 AND ce.is_primary = {$primaryEmail} AND cc.is_deleted = 0 ";
    $dao   = CRM_Core_DAO::executeQuery($query, array( '1' => array($email, 'String'))); 
    while($dao->fetch()) {
      $contactids[] = $dao->contact_id;
    }
    
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getContactFromEmail $contactids', $contactids);

    return $contactids;
  }
  
  static function updateParamsExactMatch($contactids = array(), $params) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils updateParamsExactMatch $params', $params);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils updateParamsExactMatch $contactids', $contactids);


    $contactParams =
        array(
          'version'       => 3,
          'contact_type'  => 'Individual',
          'first_name'    => $params['FNAME'],
          'last_name'     => $params['LNAME'],
          'email'         => $params['EMAIL'],
        );
    if(count($contactids) == 1) {
        $contactParams['id'] = $contactids[0];
        unset($contactParams['contact_type']);
        // Don't update firstname/lastname if it was empty
        if(empty($params['FNAME']))
          unset($contactParams['first_name']);
        if(empty($params['LNAME']))
          unset ($contactParams['last_name']);
      }
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils updateParamsExactMatch $contactParams', $contactParams);

    return $contactParams;
  }
  /*
   * Function to get the associated CiviCRM Groups IDs for the Grouping array
   * sent from Mialchimp Webhook.
   *
   * Note: any groupings from Mailchimp that do not map to CiviCRM groups are
   * silently ignored. Also, if a subscriber has no groupings, this function
   * will not return any CiviCRM groups (because all groups must be mapped to
   * both a list and a grouping).
   */
  static function getCiviGroupIdsforMcGroupings($listID, $mcGroupings) {

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getCiviGroupIdsforMcGroupings $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getCiviGroupIdsforMcGroupings $mcGroupings', $mcGroupings);

    if (empty($listID) || empty($mcGroupings)) {
      return array();
    }
    $civiGroups = array();
    foreach ($mcGroupings as $key => $mcGrouping) {
      if(!empty($mcGrouping['groups'])) {
        $mcGroups = @explode(',', $mcGrouping['groups']);
        foreach ($mcGroups as $mcGroupKey => $mcGroupName) {
          // Get Mailchimp group ID from group name. Only group name is passed in by Webhooks
          $mcGroupID = self::getMailchimpGroupIdFromName($listID, trim($mcGroupName));
          // Mailchimp group ID is unavailable
          if (empty($mcGroupID)) {
            // Try the next one.
            continue;
          }

          // Find the CiviCRM group mapped with the Mailchimp List and Group
          $civiGroupID = self::getGroupIdForMailchimp($listID, $mcGrouping['id'] , $mcGroupID);
          if (!empty($civiGroupID)) {
            $civiGroups[] = $civiGroupID;
          }
        }
      }
    }
 
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getCiviGroupIdsforMcGroupings $civiGroups', $civiGroups);
    return $civiGroups;
  }

  /*
   * Function to get CiviCRM Groups for the specific Mailchimp list in which the Contact is Added to
   */
  static function getGroupSubscriptionforMailchimpList($listID, $contactID) {

    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupSubscriptionforMailchimpList $listID', $listID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupSubscriptionforMailchimpList $contactID', $contactID);

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
  
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupSubscriptionforMailchimpList $civiGroups', $civiGroups);

    return $civiMcGroups;
  }
  
   /*
   * Function to delete Mailchimp contact for given CiviCRM email ID
   */
  static function deleteMCEmail($emailId = array() ) {
  CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils deleteMCEmail $emailId', $emailId);
    /*
    modified by mathavan@vedaconsulting.co.uk
    table name civicrm_mc_sync has no longer exist
    and dont have leid, euid, list_id informations
    so returning null to avoid the script
    */

    return NULL;

    //end
	
    if (empty($emailId)) {
      return NULL;
    }
    $toDelete = array();
    $listID = array();
    $email = NULL;
    $query = NULL;
    
    if (!empty($emailId)) {      
      $emailIds = implode(',', $emailId);      
      // @todo I think this code meant to include AND is_latest.
      // Looks very inefficient otherwise?
	  #Mathavan@vedaconsulting.co.uk, commmenting the query, table no longer exist
      //$query = "SELECT * FROM civicrm_mc_sync WHERE email_id IN ($emailIds) ORDER BY id DESC";
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
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils deleteMCEmail $toDelete', $toDelete);
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
  
   /**
   * Function to call syncontacts with smart groups and static groups
   *
   * Returns object that can iterate over a slice of the live contacts in given group.
   */
  static function getGroupContactObject($groupID, $start = null) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupContactObject $groupID', $groupID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupContactObject $start', $start);

    $group           = new CRM_Contact_DAO_Group();
    $group->id       = $groupID;
    $group->find();

    if($group->fetch()){
      //Check smart groups (including parent groups, which function as smart groups).
      if($group->saved_search_id || $group->children){
        $groupContactCache = new CRM_Contact_BAO_GroupContactCache();
        $groupContactCache->group_id = $groupID;
        if ($start !== null) {
          $groupContactCache->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        }
        $groupContactCache->find();
        CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupContactObject $groupContactCache', $groupContactCache);
        return $groupContactCache;
      }
      else {
        $groupContact = new CRM_Contact_BAO_GroupContact();
        $groupContact->group_id = $groupID;
        $groupContact->whereAdd("status = 'Added'");
        if ($start !== null) {
          $groupContact->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        }
        $groupContact->find();
        CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupContactObject $groupContact', $groupContact);
        return $groupContact;
      }
    }
    return FALSE;
  }
   /**
   * Function to call syncontacts with smart groups and static groups xxx delete
   *
   * Returns object that can iterate over a slice of the live contacts in given group.
   */
  static function getGroupMemberships($groupIDs) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils getGroupMemberships $groupIDs', $groupIDs);


    $group           = new CRM_Contact_DAO_Group();
    $group->id       = $groupID;
    $group->find();

    if($group->fetch()){
      //Check smart groups
      if($group->saved_search_id){
        $groupContactCache = new CRM_Contact_BAO_GroupContactCache();
        $groupContactCache->group_id = $groupID;
        if ($start !== null) {
          $groupContactCache->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        }
        $groupContactCache->find();
        CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupMemberships $groupContactCache', $groupContactCache);
        return $groupContactCache;
      }
      else {
        $groupContact = new CRM_Contact_BAO_GroupContact();
        $groupContact->group_id = $groupID;
        $groupContact->whereAdd("status = 'Added'");
        if ($start !== null) {
          $groupContact->limit($start, CRM_Mailchimp_Form_Sync::BATCH_COUNT);
        }
        $groupContact->find();
        CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils getGroupMemberships $groupContact', $groupContact);
        return $groupContact;
      }
    }
    
    return FALSE;
  }
  
  /*
   * Function to subscribe/unsubscribe civicrm contact in Mailchimp list
	 *
	 * $groupDetails - Array
	 *	(
	 *			[list_id] => ec641f8988
	 *		  [grouping_id] => 14397
	 *			[group_id] => 35609
	 *			[is_mc_update_grouping] => 
	 *			[group_name] => 
	 *			[grouping_name] => 
	 *			[civigroup_title] => Easter Newsletter
	 *			[civigroup_uses_cache] => 
	 * )
	 * 
	 * $action - subscribe/unsubscribe
   */
  static function subscribeOrUnsubsribeToMailchimpList($groupDetails, $contactID, $action) {
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $groupDetails', $groupDetails);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $contactID', $contactID);
    CRM_Mailchimp_Utils::checkDebug('Start-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $action', $action);

    if (empty($groupDetails) || empty($contactID) || empty($action)) {
      return NULL;
    }
    
    // We need to get contact's email before subscribing in Mailchimp
		$contactParams = array(
			'version'       => 3,
			'id'  					=> $contactID,
		);
		$contactResult = civicrm_api('Contact' , 'get' , $contactParams);
		// This is the primary email address of the contact
		$email = $contactResult['values'][$contactID]['email'];
		
		if (empty($email)) {
			// Its possible to have contacts in CiviCRM without email address
			// and add to group offline
			return;
		}
		
		// Optional merges for the email (FNAME, LNAME)
		$merge = array(
			'FNAME' => $contactResult['values'][$contactID]['first_name'],
			'LNAME' => $contactResult['values'][$contactID]['last_name'],
		);
	
		$listID = $groupDetails['list_id'];
    if (!$listID) {
      return;
    }
		$grouping_id = $groupDetails['grouping_id'];
		$group_id = $groupDetails['group_id'];
		if (!empty($grouping_id) AND !empty($group_id)) {
			$merge_groups[$grouping_id] = array('id'=> $groupDetails['grouping_id'], 'groups'=>array());
			$merge_groups[$grouping_id]['groups'][] = CRM_Mailchimp_Utils::getMCGroupName($listID, $grouping_id, $group_id);
			
			// remove the significant array indexes, in case Mailchimp cares.
			$merge['groupings'] = array_values($merge_groups);
		}
		
		// Send Mailchimp Lists API Call.
		$list = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
		switch ($action) {
			case "subscribe":
				// http://apidocs.mailchimp.com/api/2.0/lists/subscribe.php
				try {
					$result = $list->subscribe($listID, array('email' => $email), $merge, $email_type='html', $double_optin=FALSE, $update_existing=FALSE, $replace_interests=TRUE, $send_welcome=FALSE);
				}
				catch (Exception $e) {
          // Don't display if the error is that we're already subscribed.
          $message = $e->getMessage();
          if ($message !== $email . ' is already subscribed to the list.') {
            CRM_Core_Session::setStatus($message);
          }
				}
				break;
			case "unsubscribe":
				// https://apidocs.mailchimp.com/api/2.0/lists/unsubscribe.php
                $delete = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'list_removal', NULL, FALSE);
				try {
					$result = $list->unsubscribe($listID, array('email' => $email), $delete, $send_goodbye=false, $send_notify=false);
				}
				catch (Exception $e) {
					CRM_Core_Session::setStatus($e->getMessage());
				}
				break;
		}
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $groupDetails', $groupDetails);
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $contactID', $contactID);
    CRM_Mailchimp_Utils::checkDebug('End-CRM_Mailchimp_Utils subscribeOrUnsubsribeToMailchimpList $action', $action);
  }

  static function checkDebug($class_function = 'classname', $debug) {
    $debugging = CRM_Core_BAO_Setting::getItem(self::MC_SETTING_GROUP, 'enable_debugging', NULL, FALSE
    );

    if ($debugging == 1) {
      CRM_Core_Error::debug_var($class_function, $debug);
    }
  }
}
