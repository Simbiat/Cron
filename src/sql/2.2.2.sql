ALTER TABLE `cron__event_types`
    CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs`;

ALTER TABLE `cron__log`
    CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs`;

ALTER TABLE `cron__schedule`
    CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs`;

ALTER TABLE `cron__settings`
    CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs`;

ALTER TABLE `cron__tasks`
    CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs`;

ALTER TABLE `cron__event_types`
    MODIFY `description` VARCHAR(100) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_ai_ci` NOT NULL COMMENT 'Description of the event';

ALTER TABLE `cron__settings`
    MODIFY `value` VARCHAR(10) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Value of the setting';

ALTER TABLE `cron__settings`
    MODIFY `description` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_ai_ci` DEFAULT NULL COMMENT 'Description of the setting';

ALTER TABLE `cron__tasks`
    MODIFY `function` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NOT NULL COMMENT 'Actual function reference, that will be called by Cron processor';

ALTER TABLE `cron__tasks`
    MODIFY `object` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Optional object';

ALTER TABLE `cron__tasks`
    MODIFY `parameters` VARCHAR(5000) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Optional parameters used on initial object creation in JSON string';

ALTER TABLE `cron__tasks`
    MODIFY `allowedreturns` VARCHAR(5000) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Optional allowed return values to be treated as \'true\' by Cron processor in JSON string';

ALTER TABLE `cron__tasks`
    MODIFY `description` VARCHAR(1000) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_ai_ci` DEFAULT NULL COMMENT 'Description of the task';

ALTER TABLE `cron__schedule`
    MODIFY `dayofmonth` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.';

ALTER TABLE `cron__schedule`
    MODIFY `dayofweek` VARCHAR(60) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.';

ALTER TABLE `cron__schedule`
    MODIFY `message` VARCHAR(100) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_ai_ci` DEFAULT NULL COMMENT 'Optional message, that will be shown if launched outside of CLI';

ALTER TABLE `cron__log`
    MODIFY `arguments` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Optional task arguments';

ALTER TABLE `cron__log`
    MODIFY `message` TEXT CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_ai_ci` NOT NULL COMMENT 'Message provided by the event';

ALTER TABLE `cron__settings`
    MODIFY `setting` VARCHAR(10) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NOT NULL COMMENT 'Name of the setting';

ALTER TABLE `cron__schedule`
    MODIFY `arguments` VARCHAR(255) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NOT NULL COMMENT 'Optional arguments in JSON string';

ALTER TABLE `cron__schedule`
    MODIFY `runby` VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'If not NULL, indicates, that a task is queued for a run by a process.';

ALTER TABLE `cron__log`
    MODIFY `runby` VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Indicates process that was running a task';

ALTER TABLE `cron__log`
    DROP FOREIGN KEY `cron_log_to_event_type`;

ALTER TABLE `cron__log`
    DROP FOREIGN KEY `cron_log_to_tasks`;

ALTER TABLE `cron__schedule`
    DROP FOREIGN KEY `schedule_to_task`;

ALTER TABLE `cron__event_types`
    MODIFY `type` VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NOT NULL COMMENT 'Type of the event';

ALTER TABLE `cron__log`
    MODIFY `type` VARCHAR(30) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NOT NULL DEFAULT 'Status' COMMENT 'Event type';

ALTER TABLE `cron__tasks`
    MODIFY `task` VARCHAR(100) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NOT NULL COMMENT 'Function\'s internal ID';

ALTER TABLE `cron__schedule`
    MODIFY `task` VARCHAR(100) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` NOT NULL COMMENT 'Task ID';

ALTER TABLE `cron__log`
    MODIFY `task` VARCHAR(100) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_0900_as_cs` DEFAULT NULL COMMENT 'Optional task ID';

ALTER TABLE `cron__log`
    ADD CONSTRAINT `cron_log_to_event_type` FOREIGN KEY (`type`) REFERENCES `cron__event_types` (`type`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `cron__log`
    ADD CONSTRAINT `cron_log_to_tasks` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `cron__schedule`
    ADD CONSTRAINT `schedule_to_task` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

UPDATE `cron__settings`
SET `value` = '2.2.2'
WHERE `cron__settings`.`setting` = 'version';