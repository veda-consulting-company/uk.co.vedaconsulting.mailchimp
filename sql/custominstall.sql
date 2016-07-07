DROP TABLE IF EXISTS mailchimp_civicrm_account;

CREATE TABLE `mailchimp_civicrm_account` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `api_key` varchar(255) DEFAULT NULL,
 `security_key` varchar(255) DEFAULT NULL,
 `account_name` varchar(255) DEFAULT NULL,
 PRIMARY KEY (`id`),
 UNIQUE KEY `ApiKey` (`api_key`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=latin1;


ALTER TABLE civicrm_value_mailchimp_settings
ADD CONSTRAINT FK_civicrm_value_mailchimp_settings_account_id
FOREIGN KEY (`account_id`) REFERENCES `mailchimp_civicrm_account`(`id`) ON DELETE CASCADE;
