<?php

/**
 *
 * @package CiviCRM_Hook
 * @copyright CiviCRM LLC (c) 2004-2011
 * $Id: $
 *
 */

abstract class CRM_Mailchimp_Utils_Hook {

  static $_nullObject = null;

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;


  /**
   * Constructor and getter for the singleton instance
   * @return instance of $config->userHookClass
   */
  static function singleton( ) {
    if (self::$_singleton == null) {
      $config = CRM_Core_Config::singleton( );
      $class = $config->userHookClass;
      require_once( str_replace( '_', DIRECTORY_SEPARATOR, $config->userHookClass ) . '.php' );
      self::$_singleton = new $class();
    }
    return self::$_singleton;
  }

  abstract function invoke( $numParams,
                            &$arg1, &$arg2, &$arg3, &$arg4, &$arg5, &$arg6,
                            $fnSuffix );

  /**
   * This hook allows to modify collected CiviData for Mailchimp sync
   * @param integer $contactID  contact being checked
   * @param string $email
   * @param array $contactData
   * @param array $contactCustomData
   *
   * @access public
   */
  static function alterCiviDataforMailchimp ($contactID,  $email, &$contactData, &$contactCustomData) {
    return self::singleton( )->invoke( 4, $contactID, $email, $contactData, $contactCustomData, self::$_nullObject, self::$_nullObject, 'civicrm_alterCiviDataforMailchimp');
  }
}
