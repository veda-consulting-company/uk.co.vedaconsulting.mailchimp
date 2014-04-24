<?php

/**
 * Mailchimp API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
 
/**
 * Mailchimp Get Mailchimp Lists API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_getlists($params) {
  $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
  
  $results = $mcLists->getList();
  $lists = array();
  foreach($results['data'] as $list) {
    $lists[$list['id']] = $list['name'];
  }

  return civicrm_api3_create_success($lists);
}

/**
 * Mailchimp Get Mailchimp Groups API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_getgroups($params) {
  $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
  try {
    $results = $mcLists->interestGroupings($params['id']);
  } 
  catch (Exception $e) {
    return array();
  }
  $groups = array();
  foreach($results as $result) {
    foreach($result['groups'] as $group) {
      $groups[$result['name'] . CRM_Core_DAO::VALUE_SEPARATOR . $group['name']] = "{$result['name']}::{$group['name']}";
    }
  }

  return civicrm_api3_create_success($groups);
}

/**
 * Mailchimp Get all Mailchimp Lists & Groups API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_getlistsandgroups($params) {
  $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());

  $results = $mcLists->getList();
  $lists = array();

  foreach($results['data'] as $list) {
    $lists[$list['id']]['name'] = $list['name'];
    $lists[$list['id']]['id'] = $list['id'];

    $params = array('id' => $list['id']);
    $group_results = civicrm_api3_mailchimp_getgroups($params);

    $lists[$list['id']]['groups'] = $group_results['values'];
  }

  return civicrm_api3_create_success($lists);
}
<<<<<<< HEAD

/**
  * Mailchimp Get CiviCRM Group Mailchimp settings (Mailchimp List Id and Group)
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_getcivicrmgroupmailchimpsettings($params) {
  
  $groupIds = explode(',', $params['ids']);    
  $groups  = CRM_Mailchimp_Utils::getGroupsToSync($groupIds);
  
  return civicrm_api3_create_success($groups);
}
=======
>>>>>>> master
