ALTER TABLE `cron__log`
    DROP FOREIGN KEY `cron_log_to_event_type`;

ALTER TABLE `cron__log`
    DROP FOREIGN KEY `cron_log_to_tasks`;

ALTER TABLE `cron__schedule`
    DROP FOREIGN KEY `schedule_to_task`;

ALTER TABLE `cron__log`
    CHANGE `runby` `runBy` VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'Indicates process that was running a task';

ALTER TABLE `cron__tasks`
    CHANGE `allowedreturns` `allowedReturns` VARCHAR(5000) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'Optional allowed return values to be treated as \'true\' by Cron processor in JSON string';

ALTER TABLE `cron__schedule`
    CHANGE `dayofmonth` `dayOfMonth` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.';

ALTER TABLE `cron__schedule`
    CHANGE `dayofweek` `dayOfWeek` VARCHAR(60) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.';

ALTER TABLE `cron__schedule`
    CHANGE `runby` `runBy` VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'If not NULL, indicates, that a task is queued for a run by a process.';

ALTER TABLE `cron__schedule`
    CHANGE `nextrun` `nextRun` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'Next expected time for the task to be run.';

ALTER TABLE `cron__schedule`
    CHANGE `lastrun` `lastRun` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last run attempt';

ALTER TABLE `cron__schedule`
    CHANGE `lastsuccess` `lastSuccess` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last successful run';

ALTER TABLE `cron__schedule`
    CHANGE `lasterror` `lastError` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last error';

UPDATE `cron__settings`
SET `value` = '2.3.4'
WHERE `setting` = 'version';