ALTER TABLE `cron__settings`
    CHANGE `description` `description` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_nopad_ci` NULL DEFAULT NULL COMMENT 'Description of the setting';

UPDATE `cron__settings`
SET `value` = '2.2.1'
WHERE `cron__settings`.`setting` = 'version';