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
  
   // create a pull job
  $params = array(
    'sequential' => 1,
    'name'          => 'Mailchimp Pull',
    'description'   => 'Pull contacts from mailchimp to civi.',
    'run_frequency' => 'Daily',
    'api_entity'    => 'Mailchimp',
    'api_action'    => 'pull',
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

      $options = array(
        ts('Subscribers are NOT able to update this grouping using Mailchimp'),
        ts('Subscribers are able to update this grouping using Mailchimp')
      );
      $form->addRadio('is_mc_update_grouping', '', $options, NULL, '<br/>');

      $options = array(
        ts('No integration'),
        ts('Sync membership of this group with membership of a Mailchimp List'),
        ts('Sync membership of with a Mailchimp interest grouping')
      );
      $form->addRadio('mc_integration_option', '', $options, NULL, '<br/>');

      // Prepopulate details if 'edit' action
      $groupId = $form->getVar('_id');
      if ($form->getAction() == CRM_Core_Action::UPDATE AND !empty($groupId)) {

        $mcDetails  = CRM_Mailchimp_Utils::getGroupsToSync(array($groupId));

        if (!empty($mcDetails)) {
          $defaults['mailchimp_list'] = $mcDetails[$groupId]['list_id'];
          $defaults['is_mc_update_grouping'] = $mcDetails[$groupId]['is_mc_update_grouping'];
          if ($defaults['is_mc_update_grouping'] == NULL) {
            $defaults['is_mc_update_grouping'] = 0;
          }
          if ($mcDetails[$groupId]['list_id'] && $mcDetails[$groupId]['group_id']) {
            $defaults['mc_integration_option'] = 2;
          } else if ($mcDetails[$groupId]['list_id']) {
            $defaults['mc_integration_option'] = 1;
          } else {
            $defaults['mc_integration_option'] = 0;
          }

          $form->setDefaults($defaults);  
          $form->assign('mailchimp_group_id' , $mcDetails[$groupId]['group_id']);
          $form->assign('mailchimp_list_id' ,  $mcDetails[$groupId]['list_id']);
        } else {
          // defaults for a new group
          $defaults['mc_integration_option'] = 0;
          $defaults['is_mc_update_grouping'] = 0;
          $form->setDefaults($defaults);  
        }
      }
    }
  }
}

/**
 * Implements hook_civicrm_validateForm( $formName, &$fields, &$files, &$form, &$errors )
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_validateForm
 *
 */
function mailchimp_civicrm_validateForm( $formName, &$fields, &$files, &$form, &$errors ) {
  if ($formName != 'CRM_Group_Form_Edit') {
    return;
  }
  if ($fields['mc_integration_option'] == 1) {
    // Setting up a membership group.
    if (empty($fields['mailchimp_list'])) {
      $errors['mailchimp_list'] = ts('Please specify the mailchimp list');
    }
    else {
      // We need to make sure that this is the only membership tracking group for this list.
      $otherGroups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $fields['mailchimp_list'], TRUE);
      $thisGroup = $form->getVar('_group');
      if ($thisGroup) {
        unset($otherGroups[$thisGroup->id]);
      }
      if (!empty($otherGroups)) {
        $otherGroup = reset($otherGroups);
        $errors['mailchimp_list'] = ts('There is already a CiviCRM group tracking this List, called "'
          . $otherGroup['civigroup_title'].'"');
      }
    }
  }
  elseif ($fields['mc_integration_option'] == 2) {
    // Setting up a group mapped to an interest grouping.
    if (empty($fields['mailchimp_list'])) {
      $errors['mailchimp_list'] = ts('Please specify the mailchimp list');
    }
    else {
      // First we have to ensure that there is a pre-existing membership group
      // set up for this list.
      if (! CRM_Mailchimp_Utils::getGroupsToSync(array(), $fields['mailchimp_list'], TRUE)) {
        $errors['mailchimp_list'] = ts('The list you selected does not have a membership group set up. You must set up a group to track membership of the Mailchimp list before you set up group(s) for the lists\'s interest groupings.');
      }
      else {
        // The List is OK, now let's check the interest grouping...
        if (empty($fields['mailchimp_group'])) {
          // Check a grouping group was selected.
          $errors['mailchimp_group'] = ts('Please select an interest grouping.');
        }
        else {
          // OK, we have a group, let's check we're not duplicating work.
          $otherGroups = CRM_Mailchimp_Utils::getGroupsToSync(array(), $fields['mailchimp_list']);
          $thisGroup = $form->getVar('_group');
          if ($thisGroup) {
            unset($otherGroups[$thisGroup->id]);
          }
          list($mc_grouping_id, $mc_group_id) = explode('|', $fields['mailchimp_group']);
          foreach($otherGroups as $otherGroup) {
            if ($otherGroup['group_id'] == $mc_group_id) {
              $errors['mailchimp_group'] = ts('There is already a CiviCRM group tracking this interest grouping, called "'
                . $otherGroup['civigroup_title'].'"');
            }
          }
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

  if($objectName == 'Email') {
    $email = new CRM_Core_BAO_Email();
    $email->id = $id;
    $email->find(TRUE);

    // If about to delete an email in CiviCRM, we must delete it from Mailchimp
    // because we won't get chance to delete it once it's gone.
    //
    // The other case covered here is changing an email address's status
    // from for-bulk-mail to not-for-bulk-mail.
    // @todo Note: However, this will delete a subscriber and lose reporting
    // info, where what they might have wanted was to change their email
    // address.
    if( ($op == 'delete') ||
        ($op == 'edit' && $params['on_hold'] == 0 && $email->on_hold == 0 && $params['is_bulkmail'] == 0)
    ) {
      CRM_Mailchimp_Utils::deleteMCEmail(array($id));
    }
  }

  // If deleting an individual, delete their (bulk) email address from Mailchimp.
  if ($op == 'delete' && $objectName == 'Individual') {
    $result = civicrm_api('Contact', 'get', $params1);
    foreach ($result['values'] as $key => $value) {
      $emailId  = $value['email_id'];
      if ($emailId) {
        CRM_Mailchimp_Utils::deleteMCEmail(array($emailId));
      }
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

/**
 * Added by Mathavan@vedaconsulting.co.uk to fix the navigation Menu URL
 * Implementation of hook_civicrm_navigationMenu
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_permission
 */
function mailchimp_civicrm_navigationMenu(&$params){
  $parentId = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Navigation', 'Mailings', 'id', 'name');
  $mailchimpSettings  = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Navigation', 'Mailchimp_Settings', 'id', 'name');
  $mailchimpSync      = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Navigation', 'Mailchimp_Sync', 'id', 'name');
  $mailchimpPull      = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Navigation', 'Mailchimp_Pull', 'id', 'name');
  $maxId              = max(array_keys($params));
  $mailChimpMaxId     = empty($mailchimpSettings) ? $maxId+1           : $mailchimpSettings;
  $mailChimpsyncId    = empty($mailchimpSync)     ? $mailChimpMaxId+1  : $mailchimpSync;
  $mailChimpPullId    = empty($mailchimpPull)     ? $mailChimpsyncId+1 : $mailchimpPull;


  $params[$parentId]['child'][$mailChimpMaxId] = array(
        'attributes' => array(
          'label'     => ts('Mailchimp Settings'),
          'name'      => 'Mailchimp_Settings',
          'url'       => CRM_Utils_System::url('civicrm/mailchimp/settings', 'reset=1', TRUE),
          'active'    => 1,
          'parentID'  => $parentId,
          'operator'  => NULL,
          'navID'     => $mailChimpMaxId,
          'permission'=> 'administer CiviCRM',
        ),
  );
  $params[$parentId]['child'][$mailChimpsyncId] = array(
        'attributes' => array(
          'label'     => ts('Sync Civi Contacts To Mailchimp'),
          'name'      => 'Mailchimp_Sync',
          'url'       => CRM_Utils_System::url('civicrm/mailchimp/sync', 'reset=1', TRUE),
          'active'    => 1,
          'parentID'  => $parentId,
          'operator'  => NULL,
          'navID'     => $mailChimpsyncId,
          'permission'=> 'administer CiviCRM',
        ),
  );
  $params[$parentId]['child'][$mailChimpPullId] = array(
        'attributes' => array(
          'label'     => ts('Sync Mailchimp Contacts To Civi‏'),
          'name'      => 'Mailchimp_Pull',
          'url'       => CRM_Utils_System::url('civicrm/mailchimp/pull', 'reset=1', TRUE),
          'active'    => 1,
          'parentID'  => $parentId,
          'operator'  => NULL,
          'navID'     => $mailChimpPullId,
          'permission'=> 'administer CiviCRM',
        ),
  );
}
