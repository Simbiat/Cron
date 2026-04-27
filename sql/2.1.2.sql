ALTER TABLE `cron__settings`
    CHANGE `value` `value` VARCHAR(10) NULL DEFAULT NULL COMMENT 'Value of the setting';

INSERT IGNORE INTO `cron__settings` (`setting`, `value`, `description`)
VALUES ('version', '2.1.2', 'Version of cron database, based on release version in which it was last modified');