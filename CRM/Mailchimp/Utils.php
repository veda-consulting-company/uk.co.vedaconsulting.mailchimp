<?php

class CRM_Mailchimp_Utils {

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
        SELECT  entity_id, mc_list_id, mc_grouping_id , mc_group_id
        FROM    civicrm_value_mailchimp_settings mcs
        WHERE   $whereClause";
    $dao = CRM_Core_DAO::executeQuery($query);
    while ($dao->fetch()) {
      $groups[$dao->entity_id] = 
        array(
          'list_id'  => $dao->mc_list_id,
          'grouping_id' => $dao->mc_grouping_id,
          'group_id' => $dao->mc_group_id,
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
}
