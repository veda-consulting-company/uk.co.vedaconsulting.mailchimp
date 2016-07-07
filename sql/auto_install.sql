-- drop custom value table
DROP TABLE IF EXISTS civicrm_value_mailchimp_settings;

-- drop custom set and their fields
DELETE FROM `civicrm_custom_group` WHERE table_name = 'civicrm_value_mailchimp_settings';

DROP TABLE IF EXISTS mailchimp_civicrm_account;

CREATE TABLE `mailchimp_civicrm_account` (
 `id` int(10) NOT NULL AUTO_INCREMENT,
 `api_key` varchar(255) DEFAULT NULL,
 `security_key` varchar(255) DEFAULT NULL,
 `account_name` varchar(255) DEFAULT NULL,
 PRIMARY KEY (`id`),
 UNIQUE KEY `ApiKey` (`api_key`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=latin1;





