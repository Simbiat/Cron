ALTER TABLE `cron__tasks`
    ADD `system` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag indicating that task is system and can\'t be deleted from Cron\\Task class' AFTER `maxTime`;

ALTER TABLE `cron__schedule`
    ADD `system` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag indicating whether a task instance is system one and can\'t be deleted from Cron\\Schedule class' AFTER `arguments`;

ALTER TABLE `cron__schedule`
    ADD `instance` INT(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Instance number of the task' AFTER `arguments`;

ALTER TABLE `simbiatr_simbiat`.`cron__schedule`
    DROP PRIMARY KEY,
    ADD PRIMARY KEY (`task`, `arguments`, `instance`) USING BTREE;

ALTER TABLE `cron__schedule`
    CHANGE `status` `status` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag showing whether the task is running or not';

ALTER TABLE `cron__schedule`
    CHANGE `runby` `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'If not NULL, indicates, that a task is queued for a run by a process.',
    CHANGE `sse` `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag to indicate whether task is being ran by SSE call.',
    CHANGE `registered` `registered` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT 'When the task was initially registered.',
    CHANGE `updated` `updated` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT 'When the task schedule was updated.',
    CHANGE `nextrun` `nextrun` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT 'Next expected time for the task to be run.';

RENAME TABLE `cron__errors` TO `cron__log`;

ALTER TABLE `cron__log`
    DROP FOREIGN KEY `errors_to_tasks`;

ALTER TABLE `cron__schedule`
    DROP FOREIGN KEY `schedule_to_task`;

ALTER TABLE `cron__log`
    DROP PRIMARY KEY;

ALTER TABLE `cron__log`
    CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'Optional task ID';

ALTER TABLE `cron__log`
    CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'Optional task arguments';

ALTER TABLE `simbiatr_simbiat`.`cron__log`
    DROP FOREIGN KEY errors_to_arguments;

ALTER TABLE `cron__log`
    DROP INDEX `errors_to_arguments`;

ALTER TABLE `cron__log`
    CHANGE `time` `time` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) on update current_timestamp(6) COMMENT 'Time the error occurred' FIRST;

ALTER TABLE `cron__log`
    CHANGE `time` `time` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT 'Time the error occurred';

ALTER TABLE `cron__log`
    ADD `type`  VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL DEFAULT 'Status' COMMENT 'Event type' AFTER `time`,
    ADD `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL     DEFAULT NULL COMMENT 'Indicates process that was running a task' AFTER `type`;

ALTER TABLE `cron__log`
    CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional task ID',
    CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional task arguments',
    CHANGE `text` `text` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Error for the text';

ALTER TABLE `cron__schedule`
    CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Task ID',
    CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Optional arguments in JSON string',
    CHANGE `dayofmonth` `dayofmonth` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.',
    CHANGE `dayofweek` `dayofweek` VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.',
    CHANGE `message` `message` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional message, that will be shown if launched outside of CLI',
    CHANGE `runby` `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'If not NULL, indicates, that a task is queued for a run by a process.';

ALTER TABLE `cron__tasks`
    CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Function\'s internal ID',
    CHANGE `function` `function` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Actual function reference, that will be called by Cron processor',
    CHANGE `object` `object` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional object',
    CHANGE `parameters` `parameters` VARCHAR(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional parameters used on initial object creation in JSON string',
    CHANGE `allowedreturns` `allowedreturns` VARCHAR(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional allowed return values to be treated as \'true\' by Cron processor in JSON string',
    CHANGE `description` `description` VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Description of the task';

ALTER TABLE `cron__settings`
    CHANGE `setting` `setting` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Name of the setting',
    CHANGE `description` `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Description of the setting';

ALTER TABLE `cron__log`
    ADD CONSTRAINT `errors_to_tasks` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `cron__schedule`
    ADD CONSTRAINT `schedule_to_task` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `cron__log`
    ADD `instance` INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'Instance number of the task' AFTER `arguments`;

ALTER TABLE `cron__log`
    CHANGE `text` `message` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Error for the text';

ALTER TABLE `cron__log`
    ADD `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag to indicate whether task was being ran by SSE call' AFTER `runby`;

TRUNCATE `cron__log`;

UPDATE `cron__settings`
SET `setting`     = 'logLife',
    `description` = 'Days to keep messages in log. Older records will be removed on next CRON process.'
WHERE `cron__settings`.`setting` = 'errorLife';

ALTER TABLE `simbiatr_simbiat`.`cron__log`
    ADD INDEX `time_desc` (`time` DESC) USING BTREE;

ALTER TABLE `simbiatr_simbiat`.`cron__log`
    ADD INDEX `type` (`type`) USING BTREE;

ALTER TABLE `simbiatr_simbiat`.`cron__log`
    ADD INDEX `runby` (`runby`) USING BTREE;

ALTER TABLE `simbiatr_simbiat`.`cron__log`
    ADD INDEX `task` (`task`) USING BTREE;