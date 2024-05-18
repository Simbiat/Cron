CREATE TABLE IF NOT EXISTS `cron__errors` (
  `time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Time the error occurred',
  `task` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional task ID',
  `arguments` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional task arguments',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Error for the text',
  UNIQUE KEY `task` (`task`,`arguments`),
  KEY `time` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED;

CREATE TABLE IF NOT EXISTS `cron__schedule` (
  `task` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Task ID',
  `arguments` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional arguments in JSON string',
  `frequency` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Frequency to run a task in seconds',
  `dayofmonth` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.',
  `dayofweek` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.',
  `priority` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Priority of the task',
  `message` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional message, that will be shown if launched outside of CLI',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Flag showing whether the job is running or not',
  `runby` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'If not NULL, indicates, that a job is queued for a run by a process.',
  `registered` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When the job was initially registered',
  `updated` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When the job schedule was updated',
  `nextrun` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Next expected time for the job to be run',
  `lastrun` timestamp NULL DEFAULT NULL COMMENT 'Time of the last run attempt',
  `lastsuccess` timestamp NULL DEFAULT NULL COMMENT 'Time of the last successful run',
  `lasterror` timestamp NULL DEFAULT NULL COMMENT 'Time of the last error',
  UNIQUE KEY `task` (`task`,`arguments`),
  KEY `nextrun` (`nextrun`),
  KEY `priority` (`priority`),
  KEY `status` (`status`),
  KEY `runby` (`runby`),
  KEY `lastrun` (`lastrun`),
  KEY `arguments` (`arguments`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED;

CREATE TABLE IF NOT EXISTS `cron__settings` (
  `setting` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name of the setting',
  `value` int(10) DEFAULT NULL COMMENT 'Value of the setting',
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Description of the setting',
  PRIMARY KEY (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED;

INSERT IGNORE INTO `cron__settings` (`setting`, `value`, `description`) VALUES
('enabled', 1, 'Whether cron is enabled. Will only affect processing, task management will still be possible.'),
('errorLife', 30, 'Days to keep errors in log. Older records will be removed on next CRON process.'),
('maxTime', 3600, 'Maximum amount of time in seconds to allow jobs to run. If the period elapses, a job will be considered hanged and will be rescheduled on next CRON processing.'),
('retry', 3600, 'Time in seconds to add to failed one-time jobs or hanged jobs, when rescheduling them'),
('sseLoop', 0, 'Whether we need to loop task processing when launched outside of CLI (that is SSE mode).'),
('sseRetry', 10000, 'Milliseconds for retry value of SSE'),
('maxThreads', 4, 'Maximum number of simultaneous threads to run');

CREATE TABLE IF NOT EXISTS `cron__tasks` (
  `task` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Function''s internal ID',
  `function` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Actual function reference, that will be called by Cron processor',
  `object` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional object',
  `parameters` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional parameters used on initial object creation in JSON string',
  `allowedreturns` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional allowed return values to be treated as ''true'' by Cron processor in JSON string',
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Description of the task',
  PRIMARY KEY (`task`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED;

ALTER TABLE `cron__errors`
  ADD CONSTRAINT `errors_to_tasks` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `cron__errors`
  ADD CONSTRAINT `errors_to_arguments` FOREIGN KEY (`arguments`) REFERENCES `cron__schedule`(`arguments`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `cron__schedule`
  ADD CONSTRAINT `schedule_to_task` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `cron__schedule` CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Optional arguments in JSON string';
ALTER TABLE `cron__schedule` DROP INDEX `task`, ADD PRIMARY KEY (`task`, `arguments`) USING BTREE;
ALTER TABLE `cron__errors` CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Optional task arguments';
ALTER TABLE `cron__errors` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Optional task ID';
ALTER TABLE `cron__errors` DROP INDEX `task`, ADD PRIMARY KEY (`task`, `arguments`) USING BTREE;
ALTER TABLE `cron__errors` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '\'\'' COMMENT 'Optional task ID' FIRST, CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '\'\'' COMMENT 'Optional task arguments' AFTER `task`;
ALTER TABLE `cron__schedule` ADD `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag to indicate whether job is being ran by SSE call.' AFTER `runby`;
UPDATE `cron__settings` SET `setting` = 'maxTime' WHERE `cron__settings`.`setting` = 'maxtime';
UPDATE `cron__settings` SET `setting` = 'maxThreads' WHERE `cron__settings`.`setting` = 'maxthreads';
UPDATE `cron__settings` SET `setting` = 'errorLife' WHERE `cron__settings`.`setting` = 'errorlife';
ALTER TABLE `cron__tasks` ADD `maxTime` INT(10) UNSIGNED NOT NULL DEFAULT '3600' COMMENT 'Maximum time allowed for the task to run. If exceeded, it will be terminated by PHP.' AFTER `allowedreturns`;
DELETE FROM `cron__settings` WHERE `cron__settings`.`setting` = 'maxTime';
ALTER TABLE `cron__errors` CHANGE `time` `time` DATETIME(6) on update CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Time the error occurred';
ALTER TABLE `cron__schedule` CHANGE `registered` `registered` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the job was initially registered', CHANGE `updated` `updated` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the job schedule was updated', CHANGE `nextrun` `nextrun` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Next expected time for the job to be run', CHANGE `lastrun` `lastrun` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last run attempt', CHANGE `lastsuccess` `lastsuccess` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last successful run', CHANGE `lasterror` `lasterror` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last error';

ALTER TABLE `cron__tasks` ADD `system` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag indicating that task is system and can\'t be deleted from Cron\\Task class' AFTER `maxTime`;
ALTER TABLE `cron__schedule` ADD `system` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag indicating whether a task instance is system one and can\'t be deleted from Cron\\Schedule class' AFTER `arguments`;
ALTER TABLE `cron__schedule` ADD `instance` INT(10) UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Instance number of the task' AFTER `arguments`;
ALTER TABLE `simbiatr_simbiat`.`cron__schedule` DROP PRIMARY KEY, ADD PRIMARY KEY (`task`, `arguments`, `instance`) USING BTREE;
ALTER TABLE `cron__schedule` CHANGE `status` `status` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag showing whether the task is running or not';
ALTER TABLE `cron__schedule` CHANGE `runby` `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'If not NULL, indicates, that a task is queued for a run by a process.', CHANGE `sse` `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag to indicate whether task is being ran by SSE call.', CHANGE `registered` `registered` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT 'When the task was initially registered.', CHANGE `updated` `updated` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT 'When the task schedule was updated.', CHANGE `nextrun` `nextrun` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT 'Next expected time for the task to be run.';
RENAME TABLE `cron__errors` TO `cron__log`;
ALTER TABLE `cron__log` DROP FOREIGN KEY `errors_to_tasks`;
ALTER TABLE `cron__schedule` DROP FOREIGN KEY `schedule_to_task`;
ALTER TABLE `cron__log` DROP PRIMARY KEY;
ALTER TABLE `cron__log` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'Optional task ID';
ALTER TABLE `cron__log` CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'Optional task arguments';
ALTER TABLE `simbiatr_simbiat`.`cron__log` DROP FOREIGN KEY errors_to_arguments;
ALTER TABLE `cron__log` DROP INDEX `errors_to_arguments`;
ALTER TABLE `cron__log` CHANGE `time` `time` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) on update current_timestamp(6) COMMENT 'Time the error occurred' FIRST;
ALTER TABLE `cron__log` CHANGE `time` `time` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT 'Time the error occurred';
ALTER TABLE `cron__log` ADD `type` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL DEFAULT 'Status' COMMENT 'Event type' AFTER `time`, ADD `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Indicates process that was running a task' AFTER `type`;
ALTER TABLE `cron__log` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional task ID', CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional task arguments', CHANGE `text` `text` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Error for the text';
ALTER TABLE `cron__schedule` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Task ID', CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Optional arguments in JSON string', CHANGE `dayofmonth` `dayofmonth` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.', CHANGE `dayofweek` `dayofweek` VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.', CHANGE `message` `message` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional message, that will be shown if launched outside of CLI', CHANGE `runby` `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'If not NULL, indicates, that a task is queued for a run by a process.';
ALTER TABLE `cron__tasks` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Function\'s internal ID', CHANGE `function` `function` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Actual function reference, that will be called by Cron processor', CHANGE `object` `object` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional object', CHANGE `parameters` `parameters` VARCHAR(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional parameters used on initial object creation in JSON string', CHANGE `allowedreturns` `allowedreturns` VARCHAR(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Optional allowed return values to be treated as \'true\' by Cron processor in JSON string', CHANGE `description` `description` VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Description of the task';
ALTER TABLE `cron__settings` CHANGE `setting` `setting` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Name of the setting', CHANGE `description` `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT 'Description of the setting';
ALTER TABLE `cron__log` ADD CONSTRAINT `errors_to_tasks` FOREIGN KEY (`task`) REFERENCES `cron__tasks`(`task`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `cron__schedule` ADD CONSTRAINT `schedule_to_task` FOREIGN KEY (`task`) REFERENCES `cron__tasks`(`task`) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE `cron__log` ADD `instance` INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'Instance number of the task' AFTER `arguments`;
ALTER TABLE `cron__log` CHANGE `text` `message` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT 'Error for the text';
ALTER TABLE `cron__log` ADD `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag to indicate whether task was being ran by SSE call' AFTER `runby`;
TRUNCATE `cron__log`;
UPDATE `cron__settings` SET `setting` = 'logLife', `description` = 'Days to keep messages in log. Older records will be removed on next CRON process.' WHERE `cron__settings`.`setting` = 'errorLife';
ALTER TABLE `simbiatr_simbiat`.`cron__log` ADD INDEX `time_desc` (`time` DESC) USING BTREE;
ALTER TABLE `simbiatr_simbiat`.`cron__log` ADD INDEX `type` (`type`) USING BTREE;
ALTER TABLE `simbiatr_simbiat`.`cron__log` ADD INDEX `runby` (`runby`) USING BTREE;
ALTER TABLE `simbiatr_simbiat`.`cron__log` ADD INDEX `task` (`task`) USING BTREE;
