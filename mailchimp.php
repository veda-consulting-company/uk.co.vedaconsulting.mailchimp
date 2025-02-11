<?php

require_once 'mailchimp.civix.php';
require_once 'vendor/mailchimp/Mailchimp.php';
require_once 'vendor/mailchimp/Mailchimp/Lists.php';

// Limit the size of a request batch to mailchimp, to avoid memory
// problems.
define("MAILCHIMP_MAX_REQUEST_BATCH_SIZE", 500);

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function mailchimp_civicrm_config(&$config) {
  _mailchimp_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function mailchimp_civicrm_install() {

  // Create a cron job to do sync data between CiviCRM and MailChimp.
  $params = [
    'sequential' => 1,
    'name'          => 'Mailchimp Push Sync',
    'description'   => 'Sync contacts between CiviCRM and MailChimp, assuming CiviCRM to be correct. Please understand the implications before using this.',
    'run_frequency' => 'Daily',
    'api_entity'    => 'Mailchimp',
    'api_action'    => 'pushsync',
    'is_active'     => 0,
  ];
  $result = civicrm_api3('job', 'create', $params);


  // Create Pull Sync job.
  $params = [
    'sequential' => 1,
    'name'          => 'Mailchimp Pull Sync',
    'description'   => 'Sync contacts between CiviCRM and MailChimp, assuming Mailchimp to be correct. Please understand the implications before using this.',
    'run_frequency' => 'Daily',
    'api_entity'    => 'Mailchimp',
    'api_action'    => 'pullsync',
    'is_active'     => 0,
  ];
  $result = civicrm_api3('job', 'create', $params);

  _mailchimp_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function mailchimp_civicrm_enable() {
  _mailchimp_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_buildForm.
 *
 * Alter the group settings form to add in our offer of Mailchimp integration.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm
 */
function mailchimp_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Group_Form_Edit' AND ($form->getAction() == CRM_Core_Action::ADD OR $form->getAction() == CRM_Core_Action::UPDATE)) {
    // Get all the Mailchimp lists
    $lists = [];
    $params = [
      'version' => 3,
      'sequential' => 1,
    ];
    $lists = civicrm_api('Mailchimp', 'getlists', $params);
    if(!$lists['is_error']){
      // Add form elements
      $form->add('select', 'mailchimp_list', ts('Mailchimp List'), ['' => '- select -'] + $lists['values'] , FALSE );
      $form->add('select', 'mailchimp_group', ts('Mailchimp Group'), ['' => '- select -'], FALSE );

      $options = [
        ts('Subscribers are NOT able to update this grouping using Mailchimp'),
        ts('Subscribers are able to update this grouping using Mailchimp')
      ];
      $form->addRadio('is_mc_update_grouping', '', $options, NULL, '<br/>');

      $options = [
        ts('No integration'),
        ts('Membership Sync: Contacts in this group should be subscribed to a Mailchimp List'),
        ts('Interest Sync: Contacts in this group should have an "interest" set at Mailchimp')
      ];
      $form->addRadio('mc_integration_option', '', $options, NULL, '<br/>');

      $form->addElement('checkbox', 'mc_fixup',
        ts('Ensure list\'s webhook settings are correct at Mailchimp when saved.'));

      // Prepopulate details if 'edit' action
      $groupId = $form->getVar('_id');
      if ($form->getAction() == CRM_Core_Action::UPDATE AND !empty($groupId)) {

        $mcDetails  = CRM_Mailchimp_Utils::getGroupsToSync([$groupId]);

        $defaults['mc_fixup'] = 1;
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
      $otherGroups = CRM_Mailchimp_Utils::getGroupsToSync([], $fields['mailchimp_list'], TRUE);
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
      if (! CRM_Mailchimp_Utils::getGroupsToSync([], $fields['mailchimp_list'], TRUE)) {
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
          $otherGroups = CRM_Mailchimp_Utils::getGroupsToSync([], $fields['mailchimp_list']);
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
 * When the group settings form is saved, configure the mailchimp list if
 * appropriate.
 *
 * Implements hook_civicrm_postProcess($formName, &$form)
 *
 * @link https://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postProcess
 */
function mailchimp_civicrm_postProcess($formName, &$form) {
  if ($formName == 'CRM_Group_Form_Edit') {
    $vals = $form->_submitValues;
    if (!empty($vals['mc_fixup']) && !empty($vals['mailchimp_list'])
      && !empty($vals['mc_integration_option']) && $vals['mc_integration_option'] == 1) {
      // This group is supposed to have Mailchimp integration and the user wants
      // us to check the Mailchimp list is properly configured.
      $messages = CRM_Mailchimp_Utils::configureList($vals['mailchimp_list']);
      foreach ($messages as $message) {
        CRM_Core_Session::setStatus($message);
      }
    }
  }
}
/**
 * Implementation of hook_civicrm_pageRun.
 *
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_pageRun
 */
function mailchimp_civicrm_pageRun( &$page ) {
  if ($page->getVar('_name') == 'CRM_Group_Page_Group') {
    // Manage Groups page at /civicrm/group?reset=1

    // Some implementations of javascript don't like using integers for object
    // keys. Prefix with 'id'.
    // This combined with templates/CRM/Group/Page/Group.extra.tpl provides js
    // with a mailchimp_lists variable like: {
    //   'id12345': 'Membership sync to list Foo',
    //   'id98765': 'Interest sync to Bar on list Foo',
    // }
    $js_safe_object = [];
    foreach (CRM_Mailchimp_Utils::getGroupsToSync() as $group_id => $group) {
      if ($group['interest_id']) {
        if ($group['interest_name']) {
          $val = strtr(ts("Interest sync to %interest_name on list %list_name"),
            [
              '%interest_name' => htmlspecialchars($group['interest_name']),
              '%list_name'     => htmlspecialchars($group['list_name']),
            ]);
        }
        else {
          $val = ts("BROKEN interest sync. (perhaps list was deleted?)");
        }
      }
      else {
        if ($group['list_name']) {
          $val = strtr(ts("Membership sync to list %list_name"),
            [ '%list_name'     => htmlspecialchars($group['list_name']), ]);
        }
        else {
          $val = ts("BROKEN membership sync. (perhaps list was deleted?)");
        }
      }
      $js_safe_object['id' . $group_id] = $val;
    }
    $page->assign('mailchimp_groups', json_encode($js_safe_object));
  }
}

/**
 * Implementation of hook_civicrm_pre
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_pre
 */
function mailchimp_civicrm_pre( $op, $objectName, $id, &$params ) {
  $params1 = [
    'version' => 3,
    'sequential' => 1,
    'contact_id' => $id,
    'id' => $id,
  ];

  if($objectName == 'Email') {
    return; // @todo
    // If about to delete an email in CiviCRM, we must delete it from Mailchimp
    // because we won't get chance to delete it once it's gone.
    //
    // The other case covered here is changing an email address's status
    // from for-bulk-mail to not-for-bulk-mail.
    // @todo Note: However, this will delete a subscriber and lose reporting
    // info, where what they might have wanted was to change their email
    // address.
    $on_hold     = CRM_Utils_Array::value('on_hold', $params);
    $is_bulkmail = CRM_Utils_Array::value('is_bulkmail', $params);
    if( ($op == 'delete') ||
      ($op == 'edit' && $on_hold == 0 && $is_bulkmail == 0)
    ) {
      $email = new CRM_Core_BAO_Email();
      $email->id = $id;
      $email->find(TRUE);

      if ($op == 'delete' || $email->on_hold == 0) {
        CRM_Mailchimp_Utils::deleteMCEmail([$id]);
      }
    }
  }

  // If deleting an individual, delete their (bulk) email address from Mailchimp.
  if ($op == 'delete' && $objectName == 'Individual') {
    return; // @todo
    $result = civicrm_api('Contact', 'get', $params1);
    foreach ($result['values'] as $key => $value) {
      $emailId  = $value['email_id'];
      if ($emailId) {
        CRM_Mailchimp_Utils::deleteMCEmail([$emailId]);
      }
    }
  }
}

/**
 * Implementation of hook_civicrm_permission
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_permission
 */
function mailchimp_civicrm_permission(&$permissions) {
  //Until the Joomla/Civi integration is fixed, don't declare new perms
  // for Joomla installs
  if (CRM_Core_Config::singleton()->userPermissionClass->isModulePermissionSupported()) {
    $permissions = array_merge($permissions, CRM_Mailchimp_Permission::getMailchimpPermissions());
  }
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
  $mailchimpCheck     = CRM_Core_DAO::getFieldValue('CRM_Core_DAO_Navigation', 'Mailchimp_Check', 'id', 'name');
  $maxId              = max(array_keys($params));
  $mailChimpMaxId     = empty($mailchimpSettings) ? $maxId+1           : $mailchimpSettings;
  $mailChimpsyncId    = empty($mailchimpSync)     ? $mailChimpMaxId+1  : $mailchimpSync;
  $mailChimpPullId    = empty($mailchimpPull)     ? $mailChimpsyncId+1 : $mailchimpPull;
  $mailChimpCheckId    = empty($mailchimpCheck)     ? $mailChimpPullId+1 : $mailchimpCheck;


  $params[$parentId]['child'][$mailChimpMaxId] = [
    'attributes' => [
      'label'     => ts('Mailchimp Settings'),
      'name'      => 'Mailchimp_Settings',
      'url'       => CRM_Utils_System::url('civicrm/mailchimp/settings', 'reset=1', TRUE),
      'active'    => 1,
      'parentID'  => $parentId,
      'operator'  => NULL,
      'navID'     => $mailChimpMaxId,
      'permission'=> 'administer CiviCRM',
    ],
  ];
  $params[$parentId]['child'][$mailChimpsyncId] = [
    'attributes' => [
      'label'     => ts('Sync Civi Contacts To Mailchimp'),
      'name'      => 'Mailchimp_Sync',
      'url'       => CRM_Utils_System::url('civicrm/mailchimp/sync', 'reset=1', TRUE),
      'active'    => 1,
      'parentID'  => $parentId,
      'operator'  => NULL,
      'navID'     => $mailChimpsyncId,
      'permission'=> 'administer CiviCRM,allow Mailchimp sync',
    ],
  ];
  $params[$parentId]['child'][$mailChimpPullId] = [
    'attributes' => [
      'label'     => ts('Sync Mailchimp Contacts To Civi‏'),
      'name'      => 'Mailchimp_Pull',
      'url'       => CRM_Utils_System::url('civicrm/mailchimp/pull', 'reset=1', TRUE),
      'active'    => 1,
      'parentID'  => $parentId,
      'operator'  => NULL,
      'navID'     => $mailChimpPullId,
      'permission'=> 'administer CiviCRM,allow Mailchimp sync',
    ],
  ];
  $params[$parentId]['child'][$mailChimpCheckId] = [
    'attributes' => [
      'label'     => ts('Mailchimp Group Stats'),
      'name'      => 'Mailchimp_Pull',
      'url'       => CRM_Utils_System::url('civicrm/mailchimp/check', 'reset=1', TRUE),
      'active'    => 1,
      'parentID'  => $parentId,
      'operator'  => NULL,
      'navID'     => $mailChimpCheckId,
      'permission'=> 'administer CiviCRM',
    ],
  ];
}

/**
 * Implementation of hook_civicrm_post
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_post
 */
function mailchimp_civicrm_post( $op, $objectName, $objectId, &$objectRef ) {

  if (!CRM_Mailchimp_Utils::$post_hook_enabled) {
    // Post hook is disabled at this point in the running.
    return;
  }

  /***** NO BULK EMAILS (User Opt Out) *****/
  if ($objectName == 'Individual' || $objectName == 'Organization' || $objectName == 'Household') {
    // Contact Edited
    // @todo artfulrobot: I don't understand the cases this is dealing with.
    //                    Perhaps it was trying to check that if someone's been
    //                    marked as 'opt out' then they're unsubscribed from all
    //                    mailings. I could not follow the logic though -
    //                    without tests in place I thought it was better
    //                    disabled.
    if (FALSE) {
      if ($op == 'edit' || $op == 'create') {
        if($objectRef->is_opt_out == 1) {
          $action = 'unsubscribe';
        } else {
          $action = 'subscribe';
        }

        // Get all groups, the contact is subscribed to
        $civiGroups = CRM_Contact_BAO_GroupContact::getGroupList($objectId);
        $civiGroups = array_keys($civiGroups);

        if (empty($civiGroups)) {
          return;
        }

        // Get mailchimp details
        $groups = CRM_Mailchimp_Utils::getGroupsToSync($civiGroups);

        if (!empty($groups)) {
          // Loop through all groups and unsubscribe the email address from mailchimp
          foreach ($groups as $groupId => $groupDetails) {
            // method removed. CRM_Mailchimp_Utils::subscribeOrUnsubsribeToMailchimpList($groupDetails, $objectId, $action);
          }
        }
      }

    }
  }

  /***** Contacts added/removed/deleted from CiviCRM group *****/
  if ($objectName == 'GroupContact') {
    // Determine if the action being taken needs to affect Mailchimp at all.

    if ($op == 'view') {
      // Nothing changed; nothing to do.
      return;
    }

    // Get mailchimp details for the group.
    // $objectId here means CiviCRM group Id.
    $groups = CRM_Mailchimp_Utils::getGroupsToSync([$objectId]);
    if (empty($groups[$objectId])) {
      // This group has nothing to do with Mailchimp.
      return;
    }

    // The updates we need to make can be complex.
    // If someone left/joined a group synced as the membership group for a
    // Mailchimp list, then that's a subscribe/unsubscribe option.
    // If however it was a group synced to an interest in Mailchimp, then
    // the join/leave on the CiviCRM side only means updating interests on the
    // Mailchimp side, not a subscribe/unsubscribe.
    // There is also the case that somone's been put into an interest group, but
    // is not in the membership group, which should not result in them being
    // subscribed at MC.

    if ($groups[$objectId]['interest_id']) {
      // This is a change to an interest grouping.
      // We only need update Mailchimp about this if the contact is in the
      // membership group.
      $list_id = $groups[$objectId]['list_id'];
      // find membership group, then find out if the contact is in that group.
      $membership_group_details = CRM_Mailchimp_Utils::getGroupsToSync([], $list_id, TRUE);
      $result = civicrm_api3('Contact', 'getsingle', ['return'=>'group','contact_id'=>$objectRef[0]]);
      $group_ids = CRM_Mailchimp_Utils::getGroupIds($result['groups'], $membership_group_details);
      $contact_is_in_membership_group = FALSE;
      foreach ($membership_group_details as $group_id => $_) {
        if (in_array($group_id, $group_ids)) {
          $contact_is_in_membership_group = TRUE;
          break;
        }
      }
      if (!$contact_is_in_membership_group) {
        // This contact is not in the membership group, so don't bother telling
        // Mailchimp about a change in their interests.
        return;
      }
    }

    // Finally this hook is useful for small changes only; if you just added
    // thousands of people to a group then this is NOT the way to tell Mailchimp
    // about it as it would require thousands of separate API calls. This would
    // probably cause big problems (like hitting the API rate limits, or
    // crashing CiviCRM due to PHP max execution times etc.). Such updates must
    // happen in the more controlled bulk update (push).
    if (count($objectRef) > 1) {
      // Limit application to one contact only.
      CRM_Core_Session::setStatus(
        ts('You have made a bulk update that means CiviCRM contacts and Mailchimp are no longer in sync. You should do an "Update Mailchimp from CiviCRM" sync to ensure the changes you have made are applied at Mailchimp.'),
        ts('Update Mailchimp from CiviCRM required.')
      );
      return;
    }

    // Trigger mini sync for this person and this list.
    $sync = new CRM_Mailchimp_Sync($groups[$objectId]['list_id']);
    $sync->updateMailchimpFromCiviSingleContact($objectRef[0]);
    CRM_Mailchimp_Utils::checkDebug('SyncSingleContact', $objectRef[0]);
  }
}
