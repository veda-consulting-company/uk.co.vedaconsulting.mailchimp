<?php

use CRM_Mailchimp_ExtensionUtil as E;
use Civi\Test\EndToEndInterface;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - The global variable $_CV has some properties which may be useful, such as:
 *    CMS_URL, ADMIN_USER, ADMIN_PASS, ADMIN_EMAIL, DEMO_USER, DEMO_PASS, DEMO_EMAIL.
 *  - To spawn a new CiviCRM thread and execute an API call or PHP code, use cv(), e.g.
 *      cv('api system.flush');
 *      $data = cv('eval "return Civi::settings()->get(\'foobar\')"');
 *      $dashboardUrl = cv('url civicrm/dashboard');
 *  - This template uses the most generic base-class, but you may want to use a more
 *    powerful base class, such as \PHPUnit_Extensions_SeleniumTestCase or
 *    \PHPUnit_Extensions_Selenium2TestCase.
 *    See also: https://phpunit.de/manual/4.8/en/selenium.html
 *
 * @group e2e
 * @see cv
 */
class CRM_Mailchimp_WebhookSecurityTest extends \CivixPhar\PHPUnit\Framework\TestCase implements EndToEndInterface {
  
  protected static $security_key;
  protected static $user_role_id;
  protected static $user_permission;
  protected static $roleObj;

  public static function setUpBeforeClass() {
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md

    // Example: Install this extension. Don't care about anything else.
    //\Civi\Test::e2e()->installMe(__DIR__)->apply();

    // Example: Uninstall all extensions except this one.
    // \Civi\Test::e2e()->uninstall('*')->installMe(__DIR__)->apply();

    // Example: Install only core civicrm extensions.
    // \Civi\Test::e2e()->uninstall('*')->install('org.civicrm.*')->apply();
  }

  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  // /**
  //  * Example: Test that we're using a real CMS (Drupal, WordPress, etc).
  //  */
  // public function testWellFormedUF() {
  //   $this->assertRegExp('/^(Drupal|Backdrop|WordPress|Joomla)/', CIVICRM_UF);
  // }

  public static function getSecurityKey(){
    $skey = Civi::settings()->get('mailchimp_security_key');
    if (empty($skey)) {
      $skey = CRM_Mailchimp_Utils::generateWebhookKey();
    }
    static::$security_key = $skey;
  }

  public static function getUserRoleAndPermission(){
    $user = user_role_load_by_name('anonymous user');
    $permissions = user_roles(FALSE, 'allow webhook posts');
    static::$user_role_id = $user->rid;
    static::$user_permission = !empty($permissions[$user->rid]) ? TRUE : FALSE;
  }

  public static function userPermissionAndEnableOrDisable($setPermission = TRUE) {
    $permissionParams = array(
      'allow webhook posts' => $setPermission,
    );

    user_role_change_permissions(static::$user_role_id, $permissionParams);
  }  
  
  public static function wordpressUserRoleAndPermission(){
    global $wp_roles;
    if (!isset($wp_roles)) {
      $wp_roles = new WP_Roles();
    }

    foreach ($wp_roles->role_names as $role => $name) {
      if ($role == 'anonymous_user') {
        static::$roleObj = $wp_roles->get_role($role);
        static::$user_permission = !empty($roleObj->capabilities['allow_webhook_posts']) ? 1 : 0;
      }
    }    
  }

  public static function wpUserPermissionAndEnableOrDisable($setPermission = TRUE) {
    if ($setPermission) {
      static::$roleObj->add_cap('allow_webhook_posts');
    }
    else{
      static::$roleObj->remove_cap('allow_webhook_posts');
    }
  }

  public function testWebhookWithoutPermission() {
    static::getSecurityKey();
    if(CIVICRM_UF == "WordPress") {
      static::wordpressUserRoleAndPermission();
      static::wpUserPermissionAndEnableOrDisable(FALSE);
    }elseif (CIVICRM_UF == "Drupal") {
      static::getUserRoleAndPermission();
      static::userPermissionAndEnableOrDisable(FALSE);      
    }

    //Failure Case 
    //check the Permission is enabled ? if enabled then unset permission and call webhook url should return error
    $webHookStatus = CRM_Mailchimp_Form_Setting::checkMailchimpPermission(static::$security_key);

    $this->assertEquals(FALSE, $webHookStatus);
  }

  public function testWebhookWithPermission() {
    //Success Case
    //check the Permission is enabled ? set Permission
    if(CIVICRM_UF == "WordPress") {
      static::wpUserPermissionAndEnableOrDisable();  
    }elseif (CIVICRM_UF == "Drupal") {
      static::userPermissionAndEnableOrDisable();      
    }    
    $webHookStatus = CRM_Mailchimp_Form_Setting::checkMailchimpPermission(static::$security_key);
    $this->assertEquals(TRUE, $webHookStatus);
  }

  public function testRevertBackUserPermission() {
    if(CIVICRM_UF == "WordPress") {
      static::wpUserPermissionAndEnableOrDisable(static::$user_permission);
    }elseif (CIVICRM_UF == "Drupal") {
      static::userPermissionAndEnableOrDisable(static::$user_permission);
    }       
  }  
}
