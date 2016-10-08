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
  $api = CRM_Mailchimp_Utils::getMailchimpApi();

  $query = ['offset' => 0, 'count' => 100, 'fields'=>'lists.id,lists.name,total_items'];

  $lists = [];
  do {
    $data = $api->get('/lists', $query)->data;
    foreach ($data->lists as $list) {
      $lists[$list->id] = $list->name;
    }
    $query['offset'] += 100;
  } while ($query['offset'] * 100 < $data->total_items);

  return civicrm_api3_create_success($lists);
}

/**
 * Get Mailchimp Interests.
 *
 * Returns an array whose keys are interest hashes and whose values are
 * arrays. Nb. Mailchimp now (2016) talks "Interest Categories" which each
 * contain "Interests". It used to talk of "groupings and groups" which was much
 * more confusing!
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_getinterests($params) {
  try {
    $list_id = $params['id'];
    $results = CRM_Mailchimp_Utils::getMCInterestGroupings($list_id);
  } 
  catch (Exception $e) {
    return array();
  }

  $interests = [];
  foreach ($results as $category_id => $category_details) {
    $interests[$category_id]['id'] = $category_id;
    $interests[$category_id]['name'] = $category_details['name'];
    foreach ($category_details['interests'] as $interest_id => $interest_details) {
      $interests[$category_id]['interests'][$interest_id] = "$category_details[name]::$interest_details[name]";
    }
  }

  return civicrm_api3_create_success($interests);
}

/**
 * CiviCRM to Mailchimp Push Sync.
 *
 * This is a schedulable job.
 *
 * Note this was previously named 'sync' and did a pull, then a push request.
 * However this is problematic because each of these syncs brings the membership
 * exactly in-line, so there's nothing for the 'push' to do anyway. The pull
 * will remove any contacts from the synced membership group that are not in the
 * Mailchimp list. This means any contacts added to the membership group that
 * have not been sent up to Mailchimp (there are several scenarios when this
 * happens: bulk additions, smart groups, ...) will be removed from the group
 * before they've ever been subscribed.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_pushsync($params) {
  // Do push from CiviCRM to mailchimp
  $runner = CRM_Mailchimp_Form_Sync::getRunner($skipEndUrl = TRUE);
  if ($runner) {
    $result = $runner->runAll();
  }

  if ($result['is_error'] == 0) {
    return civicrm_api3_create_success();
  }
  else {
    if (isset($result['exception']) && $result['exception'] instanceof Exception) {
      return civicrm_api3_create_error($result['exception']->getMessage());
    }
    return civicrm_api3_create_error('Unknown error');
  }
}
/**
 * Pull sync from Mailchimp to CiviCRM.
 *
 * This is a schedulable job.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */ 
function civicrm_api3_mailchimp_pullsync($params) {
  // Do push from CiviCRM to mailchimp
  $runner = CRM_Mailchimp_Form_Pull::getRunner($skipEndUrl = TRUE);
  if ($runner) {
    $result = $runner->runAll();
  }

  if ($result['is_error'] == 0) {
    return civicrm_api3_create_success();
  }
  else {
    if (isset($result['exception']) && $result['exception'] instanceof Exception) {
      return civicrm_api3_create_error($result['exception']->getMessage());
    }
    return civicrm_api3_create_error('Unknown error');
  }
}

// Deprecated below here. No code in this extension uses these, so if your 3rd
// party code does use them time to take action.
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
 * Mailchimp Get Mailchimp Groups API.
 *
 * Returns an array whose keys are interest grouping Ids and whose values are
 * arrays. Nb. Mailchimp now (2016) talks "Interest Categories" which each
 * contain "Interests". It used to talk of "groupings and groups" which was much
 * more confusing!
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

