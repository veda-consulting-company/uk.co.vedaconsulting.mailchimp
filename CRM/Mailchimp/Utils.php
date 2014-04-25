<?php

class CRM_Mailchimp_Utils {

  static function mailchimp() {
    $apiKey   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
    $mcClient = new Mailchimp($apiKey);
    return $mcClient;
  }

  static function getGroupsToSync($id = FALSE) {
    $groups = array();
    if ($id > 1) {
      $query = "
        SELECT entity_id, mailchimp_list_id, mailchimp_group
        FROM civicrm_value_mailchimp_settings
        WHERE entity_id = %1";

      $dao = CRM_Core_DAO::executeQuery($query, array('1' => array($id , 'Integer')));
      if ($dao->fetch()) {
        $groups[$dao->entity_id] = 
          array(
            'list_id'  => $dao->mailchimp_list_id,
            'group_id' => $dao->mailchimp_group
          );
      }
    }
    
    if (!$id) {
      $query  = "
        SELECT  entity_id, mailchimp_list_id, mailchimp_group    
        FROM    civicrm_value_mailchimp_settings mcs
        WHERE   mailchimp_list_id IS NOT NULL AND mailchimp_list_id <> ''";

      $dao = CRM_Core_DAO::executeQuery($query);
      while ($dao->fetch()) {
        $groups[$dao->entity_id] = 
          array(
            'list_id'  => $dao->mailchimp_list_id,
            'group_id' => $dao->mailchimp_group
          );
      }
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
