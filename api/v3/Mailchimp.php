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
  
  $lists = array();

  /**
    * Fix for #155 - Sync limited to 25 MailChimp Lists
  **/
  $results = $mcLists->getList(NULL, 0, 100); //get max number of mailing lists i.e. 100

  foreach($results['data'] as $list) {
    $lists[$list['id']] = $list['name'];
  }

  $pages = ceil($results['total']/100); //calculate the number of page requests to be made to fetch all the mailing lists

  for( $i=1; $i< $pages; $i++ ) {
    $results = $mcLists->getList(NULL, $i, 100); //get 100 results for each page
    foreach($results['data'] as $list) {
      $lists[$list['id']] = $list['name'];
    }
  }

  return civicrm_api3_create_success($lists);
}

/**
 * Mailchimp Get Mailchimp Membercount API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_getmembercount($params) {
  $mcLists = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
  
  $results = $mcLists->getList();
  $listmembercount = array();
  foreach($results['data'] as $list) {
    $listmembercount[$list['id']] = $list['stats']['member_count'];
  }

  return civicrm_api3_create_success($listmembercount);
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
    $groups[$result['id']]['id'] = $result['id'];
    $groups[$result['id']]['name'] = $result['name'];
    foreach($result['groups'] as $group) {
      $groups[$result['id']]['groups'][$group['id']] = "{$result['name']}::{$group['name']}";
    }
  }

  return civicrm_api3_create_success($groups);
}

/**
 * Mailchimp Get Mailchimp Groupids API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_getgroupid($params) {
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
        $groups[] = array(
          'groupingid'  => $result['id'],
          'groupingname'  => $result['name'],
          'groupname' =>  $group['name'],
          'groupid' =>  $group['id'],
        );
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
    if (!empty($group_results)) {
      $lists[$list['id']]['grouping'] = $group_results['values'];
    }
  }

  return civicrm_api3_create_success($lists);
}

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
  $groupIds = empty($params['ids']) ? array() : explode(',', $params['ids']);
  $groups  = CRM_Mailchimp_Utils::getGroupsToSync($groupIds);
  return civicrm_api3_create_success($groups);
}

/**
 * CiviCRM to Mailchimp Sync
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_sync($params) {
  $groups = CRM_Mailchimp_Utils::getGroupsToSync(array(), null, $membership_only = TRUE);
  foreach ($groups as $group_id => $details) {
    $list           = new Mailchimp_Lists(CRM_Mailchimp_Utils::mailchimp());
    $webhookoutput  = $list->webhooks($details['list_id']);
    if($webhookoutput[0]['sources']['api'] == 1) {
      return civicrm_api3_create_error('civicrm_api3_mailchimp_sync -  API is set in Webhook setting for listID '.$details['list_id'].' Please uncheck API' );
    }
  }
  $result = $pullResult = array();
	
	// Do pull first from mailchimp to CiviCRM
	$pullRunner = CRM_Mailchimp_Form_Pull::getRunner($skipEndUrl = TRUE);
	if ($pullRunner) {
    $pullResult = $pullRunner->runAll();
  }
	
	// Do push from CiviCRM to mailchimp 
  $runner = CRM_Mailchimp_Form_Sync::getRunner($skipEndUrl = TRUE);
  if ($runner) {
    $result = $runner->runAll();
  }

  if ($pullResult['is_error'] == 0 && $result['is_error'] == 0) {
    return civicrm_api3_create_success();
  }
  else {
    return civicrm_api3_create_error();
  }
}

/*function civicrm_api3_mailchimp_pull($params) {
  $result = array();
  $runner = CRM_Mailchimp_Form_Pull::getRunner($params);
  if ($runner) {
    $result = $runner->runAll();
  }

  if ($result['is_error'] == 0) {
    return civicrm_api3_create_success();
  }
  else {
    return civicrm_api3_create_error();
  }
}*/
