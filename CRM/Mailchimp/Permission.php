<?php

/**
 * @file
 * Handle CMS user permissions for Mailchimp extension.
 */

/**
 * Permissions checks for Mailchimp extension.
 */
class CRM_Mailchimp_Permission extends CRM_Core_Permission {

  /**
   * Returns an array of permissions defined by this extension.
   *
   * Modeled on CRM_Core_Permission::getCorePermissions().
   *
   * @return array
   *   Keyed by machine names with human-readable labels for values
   */
  public static function getMailchimpPermissions() {
    $prefix = ts('Mailchimp') . ': ';
    return array(
      'allow webhook posts' => $prefix . ts('allow webhook posts'),
    );
  }

  /**
   * Given a permission string or array, check for access requirements.
   *
   * If this is a permissions-challenged Joomla instance, don't enforce
   * CiviMailchimp-defined permissions.
   *
   * @param mixed $permissions
   *   The permission(s) to check as an array or string. See parent class for
   *   examples.
   *
   * @return bool
   *   True if permitted.
   */
  public static function check($permissions) {
    $permissions = (array) $permissions;
    if (!CRM_Core_Config::singleton()->userPermissionClass->isModulePermissionSupported()) {
      array_walk_recursive($permissions, function (&$v, $k) {
        if (array_key_exists($v, CRM_Mailchimp_Permission::getMailchimpPermissions())) {
          $v = CRM_Core_Permission::ALWAYS_ALLOW_PERMISSION;
        }
      });
    }

    return parent::check($permissions);
  }

}
