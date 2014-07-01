<?php

require_once 'mailchimp.civix.php';
require_once 'vendor/mailchimp/Mailchimp.php';
require_once 'vendor/mailchimp/Mailchimp/Lists.php';

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function mailchimp_civicrm_config(&$config) {
  _mailchimp_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function mailchimp_civicrm_xmlMenu(&$files) {
  _mailchimp_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function mailchimp_civicrm_install() {
  $parentId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Navigation', 'Mailings', 'id', 'name');
  $weight   = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Navigation', 'From Email Addresses', 'weight', 'name');

  if ($parentId) {
    $mailchimpMenuTree = 
      array(
        array(
          'label' => ts('Mailchimp Settings'),
          'name'  => 'Mailchimp_Settings',
          'url'   => 'civicrm/mailchimp/settings&reset=1',
        ),
        array(
          'label' => ts('Mailchimp Sync'),
          'name'  => 'Mailchimp_Sync',
          'url'   => 'civicrm/mailchimp/sync&reset=1',
        ),
      );

    foreach ($mailchimpMenuTree as $key => $menuItems) {
      $menuItems['is_active'] = 1;
      $menuItems['parent_id'] = $parentId;
      $menuItems['weight']    = ++$weight;
      $menuItems['permission'] = 'administer CiviCRM';
      CRM_Core_BAO_Navigation::add($menuItems);
    }
    CRM_Core_BAO_Navigation::resetNavigation();
  }

  // create a sync job
  $params = array(
    'sequential' => 1,
    'name'          => 'Mailchimp Sync',
    'description'   => 'Sync contacts from civi to mailchimp.',
    'run_frequency' => 'Daily',
    'api_entity'    => 'Mailchimp',
    'api_action'    => 'sync',
    'is_active'     => 0,
  );
  $result = civicrm_api3('job', 'create', $params);

  return _mailchimp_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_uninstall
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function mailchimp_civicrm_uninstall() {
  $mailchimpMenuItems = array(
    'Mailchimp_Settings', 
    'Mailchimp_Sync', 
  );

  foreach ($mailchimpMenuItems as $name) {
    $itemId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Navigation', $name, 'id', 'name', TRUE);
    if ($itemId) {
      CRM_Core_BAO_Navigation::processDelete($itemId);
    }
  }
  CRM_Core_BAO_Navigation::resetNavigation();

  return _mailchimp_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function mailchimp_civicrm_enable() {
  return _mailchimp_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function mailchimp_civicrm_disable() {
  return _mailchimp_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function mailchimp_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _mailchimp_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function mailchimp_civicrm_managed(&$entities) {
  return _mailchimp_civix_civicrm_managed($entities);
}

/**
 * Implementation of hook_civicrm_caseTypes
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function mailchimp_civicrm_caseTypes(&$caseTypes) {
  _mailchimp_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implementation of hook_civicrm_alterSettingsFolders
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function mailchimp_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _mailchimp_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implementation of hook_civicrm_buildForm
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm
 */
function mailchimp_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Group_Form_Edit' AND ($form->getAction() == CRM_Core_Action::ADD OR $form->getAction() == CRM_Core_Action::UPDATE)) {
    // Get all the Mailchimp lists
    $lists = array();
    $params = array(
      'version' => 3,
      'sequential' => 1,
    );
    $lists = civicrm_api('Mailchimp', 'getlists', $params);
    if(!$lists['is_error']){
      // Add form elements
      $form->add('select', 'mailchimp_list', ts('Mailchimp List'), array('' => '- select -') + $lists['values'] , FALSE );
      $form->add('select', 'mailchimp_group', ts('Mailchimp Group'), array('' => '- select -') , FALSE );

      // Prepopulate details if 'edit' action
      $groupId = $form->getVar('_id');
      if ($form->getAction() == CRM_Core_Action::UPDATE AND !empty($groupId)) {

        $mcDetails  = CRM_Mailchimp_Utils::getGroupsToSync(array($groupId));

        if (!empty($mcDetails)) {
          $defaults['mailchimp_list'] = $mcDetails[$groupId]['list_id'];
          $form->setDefaults($defaults);  
          $form->assign('mailchimp_group_id' , $mcDetails[$groupId]['group_id']);
        }
      }
    }
  }
}

/**
 * Implementation of hook_civicrm_pageRun
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_pageRun
 */
function mailchimp_civicrm_pageRun( &$page ) {
  if ($page->getVar('_name') == 'CRM_Group_Page_Group') {
    $params = array(
      'version' => 3,
      'sequential' => 1,
    );
    // Get all the mailchimp lists/groups and pass it to template as JS array
    // To reduce the no. of AJAX calls to get the list/group name in Group Listing Page
    $result = civicrm_api('Mailchimp', 'getlistsandgroups', $params);
    if(!$result['is_error']){
    $list_and_groups = json_encode($result['values']);
    $page->assign('lists_and_groups', $list_and_groups);
    }
  }
}

/**
 * Implementation of hook_civicrm_pre
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_pre
 */
function mailchimp_civicrm_pre( $op, $objectName, $id, &$params ) {
  $params1 = array(
    'version' => 3,
    'sequential' => 1,
    'contact_id' => $id,
    'id' => $id,    
  );
  $email  = NULL;
  
  if($objectName == 'Email') {
    $email = new CRM_Core_BAO_Email();
    $email->id = $id;
    $email->find(TRUE);    
  }
  
  if($objectName == 'Email' && 
    ( ($op == 'delete') || 
      ($op == 'edit' && $params['on_hold'] == 0 && $email->on_hold == 0 && $params['is_bulkmail'] == 0) )
  ) {
    CRM_Mailchimp_Utils::deleteMCEmail(array($id));
  }
  
  if ($op == 'delete' && $objectName == 'Individual') {    
    $result = civicrm_api('Contact', 'get', $params1);
    foreach ($result['values'] as $key => $value) {
      $emailId  = $value['email_id'];
      CRM_Mailchimp_Utils::deleteMCEmail(array($emailId)); 
    }   
  } 
}

/**
 * Implementation of hook_civicrm_permission
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_permission
 */
function mailchimp_civicrm_permission( &$permissions ) {  
  $prefix = ts('Mailchimp') . ': '; // name of extension or module
  $permissions = array(
    'allow webhook posts' => $prefix . ts('allow webhook posts'),
  );  
}

function mailchimp_civicrm_post( $op, $objectName, $objectId, &$objectRef ) {
  if ($op == 'delete' && $objectName == 'GroupContact') { 
    $emailIds= array();
    $contactIds = array();
    $contactIds = $objectRef;
    if (empty($contactIds)) {
      return NULL;
    }
    if (!empty($contactIds)) {      
      $contactIDs = implode(',', $contactIds); 
      $query = "
        SELECT ce.id FROM `civicrm_email` ce
        LEFT JOIN civicrm_contact cc ON cc.id = ce.contact_id
        WHERE ce.contact_id IN ($contactIDs) AND ce.id is not null";
    
      $dao = CRM_Core_DAO::executeQuery($query); 
        
      while ($dao->fetch()) {
        $emailIds[] = $dao->id;
      }
    }
    CRM_Mailchimp_Utils::deleteMCEmail($emailIds); 
  }
}
  
