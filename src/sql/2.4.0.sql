ALTER TABLE `cron__schedule`
    CHANGE `dayOfMonth` `day_of_month` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.',
    CHANGE `dayOfWeek` `day_of_week` VARCHAR(60) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.',
    CHANGE `runBy` `run_by` VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'If not NULL, indicates, that a task is queued for a run by a process.',
    CHANGE `nextRun` `next_run` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) COMMENT 'Next expected time for the task to be run.',
    CHANGE `lastRun` `last_run` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last run attempt',
    CHANGE `lastSuccess` `last_success` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last successful run',
    CHANGE `lastError` `last_error` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last error';

ALTER TABLE `cron__tasks`
    CHANGE `allowedReturns` `allowed_returns` VARCHAR(5000) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'Optional allowed return values to be treated as \'true\' by Cron processor in JSON string',
    CHANGE `maxTime` `max_time` INT(10) UNSIGNED NOT NULL DEFAULT '3600' COMMENT 'Maximum time allowed for the task to run. If exceeded, it will be terminated by PHP.',
    CHANGE `minFrequency` `min_frequency` INT(10) UNSIGNED NOT NULL DEFAULT '60' COMMENT 'Minimal allowed frequency (in seconds) at which a task instance can run. Does not apply to one-time jobs.';

ALTER TABLE `cron__log`
    CHANGE `runBy` `run_by` VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NULL DEFAULT NULL COMMENT 'Indicates process that was running a task';

ALTER TABLE `cron__settings`
    CHANGE `setting` `setting` VARCHAR(12) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NOT NULL COMMENT 'Name of the setting';

UPDATE `cron__settings`
SET `setting` = 'log_life'
WHERE `cron__settings`.`setting` = 'logLife';

UPDATE `cron__settings`
SET `setting` = 'max_threads'
WHERE `cron__settings`.`setting` = 'maxThreads';

UPDATE `cron__settings`
SET `setting` = 'sse_loop'
WHERE `cron__settings`.`setting` = 'sseLoop';

UPDATE `cron__settings`
SET `setting` = 'sse_retry'
WHERE `cron__settings`.`setting` = 'sseRetry';

ALTER TABLE `cron__schedule`
    ADD `success_total`  BIGINT(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Total of successful runs' AFTER `last_success`,
    ADD `success_streak` BIGINT(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Successful runs since last error' AFTER `success_total`;

ALTER TABLE `cron__schedule`
    ADD `error_total`        BIGINT(20) UNSIGNED                                   NOT NULL DEFAULT '0' COMMENT 'Total of erroneous runs' AFTER `last_error`,
    ADD `error_streak`       BIGINT(20) UNSIGNED                                   NOT NULL DEFAULT '0' COMMENT 'Erroneous runs since last success' AFTER `error_total`,
    ADD `last_error_message` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL     DEFAULT NULL COMMENT 'Message from last error run' AFTER `error_streak`;

ALTER TABLE `cron__schedule`
    CHANGE `status` `status` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '0 - not being processed, 1 - picked up by a job, 2 - running, 3 - scheduled for removal';

ALTER TABLE `cron__schedule`
    ADD `thread_heartbeat` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of last activity of the thread working on the task (if any)' AFTER `run_by`;

ALTER TABLE `cron__schedule`
    ADD INDEX (`thread_heartbeat`);

UPDATE `cron__settings`
SET `value` = '2.4.0'
WHERE `setting` = 'version';