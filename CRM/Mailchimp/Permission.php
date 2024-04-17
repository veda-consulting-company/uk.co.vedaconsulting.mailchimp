<?php

use CRM_Mailchimp_ExtensionUtil as E;

class CRM_Mailchimp_Permission extends CRM_Core_Permission {

  /**
   * Returns an array of permissions defined by this extension. Modeled off of
   * CRM_Core_Permission::getCorePermissions().
   *
   * @return array Keyed by machine names with human-readable labels for values
   */
  public static function getMailchimpPermissions() {
    $prefix = E::ts('Mailchimp') . ': ';
    return [
      'allow webhook posts' => [
        'label' => $prefix . E::ts('allow webhook posts'),
        'description' => E::ts('allow webhook posts'),
      ],
      'allow Mailchimp sync' => [
        'label' => $prefix . E::ts('allow Mailchimp sync'),
        'description' => E::ts('allow Mailchimp sync'),
      ],
    ];
  }

  /**
   * Given a permission string or array, check for access requirements.
   * if this is a permissions-challenged Joomla instance, don't enforce
   * CiviMailchimp-defined permissions.
   *
   * @param mixed $permissions The permission(s) to check as an array or string.
   *        See parent class for examples.
   * @return boolean
   */
  public static function check($permissions, $contactId = NULL) {
    $permissions = (array) $permissions;

    if (!CRM_Core_Config::singleton()->userPermissionClass->isModulePermissionSupported()) {
      array_walk_recursive($permissions, function(&$v, $k) {
        if (array_key_exists($v, CRM_Mailchimp_Permission::getMailchimpPermissions())) {
          $v = CRM_Core_Permission::ALWAYS_ALLOW_PERMISSION;
        }
      });
    }
    return parent::check($permissions);
  }
}
