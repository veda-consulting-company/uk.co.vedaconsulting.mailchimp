DROP TABLE IF EXISTS `civicrm_mc_sync`;

-- /*******************************************************
-- *
-- * civicrm_mc_sync
-- *
-- * Mailchimp sync mapping.
-- *
-- *******************************************************/
CREATE TABLE `civicrm_mc_sync` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Unique mailchimp sync id',
  `email_id`   int(10) unsigned NOT NULL COMMENT 'FK to civi email id',
  `mc_list_id` varchar(64)      NOT NULL COMMENT 'The mailchimp List ID email is sync\'d to',
  `mc_group`   varchar(128) DEFAULT NULL COMMENT 'The mailchimp group email is sync\'d to',
  `mc_euid`    varchar(64)  DEFAULT NULL COMMENT 'Email id in mailchimp',
  `mc_leid`    varchar(64)  DEFAULT NULL COMMENT 'Email id for the list in mailchimp',
  `sync_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last date when sync happened',
  `sync_status` enum('Added', 'Updated', 'Removed', 'Error') COMMENT 'Sync action e.g: Added, Updated, Removed, Error'    ,
  PRIMARY KEY ( `id` ),
  CONSTRAINT `FK_civicrm_mc_sync_email_id` FOREIGN KEY (`email_id`) REFERENCES `civicrm_email`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
