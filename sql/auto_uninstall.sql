-- drop custom value table
DROP TABLE IF EXISTS civicrm_value_mailchimp_settings;

-- drop custom set and their fields
DELETE FROM `civicrm_custom_group` WHERE table_name = 'civicrm_value_mailchimp_settings';

-- drop sync table
DROP TABLE IF EXISTS `civicrm_mc_sync`;

-- delete job entry
DELETE FROM `civicrm_job` WHERE name = 'Mailchimp Sync';
