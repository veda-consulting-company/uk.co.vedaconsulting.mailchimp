<?php
/**
 * @file
 * Bootstrap code common to all integration tests.
 *
 * Include this at the top of all tests.
 *
 * You **must** run it from the doc root dir.
 *
 * Nb.**Only Drupal 7 is supported**. (feel free to write your own bootstrap for
 * other CMSes!)
 */
if (file_exists(getcwd() . DIRECTORY_SEPARATOR . 'sites' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'settings.php')) {
  // Drupal. (may not work on D8)
  define('DRUPAL_ROOT', getcwd());
  require_once DRUPAL_ROOT . '/includes/bootstrap.inc';
  $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

  // Bootstrap Drupal.
  drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);

  // We'll be user 1 to give us full access.
  $user = user_load(1);
}
else {
  throw new Exception("Sorry, tests only include bootstrap for Drupal 7");
}
// Bootstrap CiviCRM.
civicrm_initialize();
require_once __DIR__ . DIRECTORY_SEPARATOR . '../../CRM/Mailchimp/IntegrationTestBase.php';
