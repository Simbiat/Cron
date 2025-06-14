ALTER TABLE `cron__log`
    CHANGE `message` `message` TEXT CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_nopad_ci` NOT NULL COMMENT 'Message provided by the event';

ALTER TABLE `cron__tasks`
    ADD `minFrequency` INT(10) UNSIGNED NOT NULL DEFAULT '60' COMMENT 'Minimal allowed frequency (in seconds) at which a task instance can run. Does not apply to one-time jobs.' AFTER `maxTime`;

ALTER TABLE `cron__tasks`
    ADD `retry` INT UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Custom number of seconds to reschedule a failed task instance for. 0 disables the functionality.' AFTER `minFrequency`;

CREATE TABLE IF NOT EXISTS `cron__event_types`
(
    `type`        VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_nopad_ci`  NOT NULL COMMENT 'Type of the event',
    `description` VARCHAR(100) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_nopad_ci` NOT NULL COMMENT 'Description of the event'
) ENGINE = InnoDB
  CHARSET = `utf8mb4`
  COLLATE `utf8mb4_unicode_nopad_ci`
  ROW_FORMAT = DYNAMIC COMMENT = 'Different event types for logging and SSE output';

ALTER TABLE `cron__event_types`
    ADD PRIMARY KEY (`type`);

ALTER TABLE `cron__log`
    DROP FOREIGN KEY `errors_to_tasks`;

ALTER TABLE `cron__log`
    ADD CONSTRAINT `log_to_tasks` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CronStart', 'Start of cron processing.'),
       ('CronFail', 'Failure of cron processing.'),
       ('CronTaskStart', 'A task instance was started.'),
       ('CronTaskEnd', 'A task instance completed successfully.'),
       ('CronTaskFail', 'A task instance failed.'),
       ('CronEmpty', 'Empty list of tasks in the cycle.'),
       ('CronNoThreads', 'No free threads in this cycle.'),
       ('CronEnd', 'End of cron processing.'),
       ('Reschedule', 'A task instance was rescheduled.'),
       ('RescheduleFail', 'A task instance failed to be rescheduled.'),
       ('TaskAdd', 'A task was added or updated.'),
       ('TaskAddFail', 'A task failed to be added or updated.'),
       ('TaskDelete', 'A task was deleted.'),
       ('TaskDeleteFail', 'A task failed to be deleted.'),
       ('TaskToSystem', 'A task was marked as system one.'),
       ('TaskToSystemFail', 'A task failed to be marked as system one.'),
       ('InstanceAdd', 'A task instance was added or updated.'),
       ('InstanceAddFail', 'A task instance failed to be added or updated.'),
       ('InstanceDelete', 'A task instance was deleted.'),
       ('InstanceDeleteFail', 'A task instance failed to be deleted.'),
       ('InstanceToSystem', 'A task instance was marked as system one.'),
       ('InstanceToSystemFail', 'A task instance failed to be marked as system one.');

ALTER TABLE `cron__log`
    DROP FOREIGN KEY `log_to_tasks`;

ALTER TABLE `cron__log`
    ADD CONSTRAINT `cron_log_to_tasks` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `cron__log`
    ADD CONSTRAINT `cron_log_to_event_type` FOREIGN KEY (`type`) REFERENCES `cron__event_types` (`type`) ON DELETE CASCADE ON UPDATE CASCADE;

UPDATE `cron__event_types`
SET `type` = 'InstanceEnd'
WHERE `cron__event_types`.`type` = 'CronTaskEnd';

UPDATE `cron__event_types`
SET `type` = 'InstanceFail'
WHERE `cron__event_types`.`type` = 'CronTaskFail';

UPDATE `cron__event_types`
SET `type` = 'InstanceStart'
WHERE `cron__event_types`.`type` = 'CronTaskStart';

UPDATE `cron__event_types`
SET `type` = 'SSEEnd'
WHERE `cron__event_types`.`type` = 'CronEnd';

UPDATE `cron__event_types`
SET `description` = 'End of cron processing in SSE mode.'
WHERE `cron__event_types`.`type` = 'SSEEnd';

UPDATE `cron__event_types`
SET `type` = 'SSEStart'
WHERE `cron__event_types`.`type` = 'CronStart';

UPDATE `cron__event_types`
SET `description` = 'Start of cron processing in SSE mode.'
WHERE `cron__event_types`.`type` = 'SSEStart';

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CronDisabled', 'Cron processing is disabled in settings.');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CustomEmergency', 'Custom event indicating an emergency (SysLog standard level 0).');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CustomAlert', 'Custom event indicating an alert (SysLog standard level 1).');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CustomCritical', 'Custom event indicating a critical condition (SysLog standard level 2).');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CustomError', 'Custom event indicating an error (SysLog standard level 3).');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CustomWarning', 'Custom event indicating a warning (SysLog standard level 4).');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CustomNotice', 'Custom event indicating a notice (SysLog standard level 5).');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CustomInformation', 'Custom event indicating an informative message (SysLog standard level 6).');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('CustomDebug', 'Custom event indicating a debugging message (SysLog standard level 7).');

ALTER TABLE `cron__tasks`
    ADD `enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Whether a task (and thus all its instances) is enabled and should be run as per schedule' AFTER `retry`;

ALTER TABLE `cron__schedule`
    ADD `enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Whether a task instance is enabled and should be run as per schedule' AFTER `instance`;

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('TaskEnable', 'Task was enabled.');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('TaskDisable', 'Task was disabled.');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('TaskEnableFail', 'Failed to enable task.');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('TaskDisableFail', 'Failed to disable task.');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('InstanceEnable', 'Task instance was enabled.');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('InstanceDisable', 'Task instance was disabled.');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('InstanceEnableFail', 'Failed to enable task instance.');

INSERT INTO `cron__event_types` (`type`, `description`)
VALUES ('InstanceDisableFail', 'Failed to disable task instance.');

ALTER TABLE `cron__tasks`
    ADD INDEX (`enabled`);

ALTER TABLE `cron__schedule`
    ADD INDEX (`enabled`);

UPDATE `cron__settings`
SET `value` = '2.2.0'
WHERE `cron__settings`.`setting` = 'version';