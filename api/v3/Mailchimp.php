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
	// Get the API Key
  $api_key   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
	
	$mc_client = new Mailchimp($api_key);
  $mc_lists = new Mailchimp_Lists($mc_client);
	
	$results = $mc_lists->getList();
	
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
	// Get the API Key
  $api_key   = CRM_Core_BAO_Setting::getItem(CRM_Mailchimp_Form_Setting::MC_SETTING_GROUP, 'api_key');
	
	$mc_client = new Mailchimp($api_key);
  $mc_lists = new Mailchimp_Lists($mc_client);
	
	$results = $mc_lists->interestGroupings($params['id']);
	
	$groups = array();
	
	foreach($results as $result) {
    foreach($result['groups'] as $group) {
      $groups[$group['id']] = $group['name'];
    }
	}
	
  return civicrm_api3_create_success($groups);
}

