<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use Simbiat\Database\Manage;
use Simbiat\Database\Query;

/**
 * Installer class for CRON library.
 */
class Installer
{
    use TraitForCron;
    
    /**
     * Supported settings
     * @var array
     */
    private const array settings = ['enabled', 'logLife', 'retry', 'sseLoop', 'sseRetry', 'maxThreads'];
    /**
     * Logic to calculate task priority. Not sure, I fully understand how this provides the results I expect, but it does. Essentially, `priority` is valued higher, while "overdue" time has a smoother scaling. Rare jobs (with higher value of `frequency`) also have higher weight, but one-time jobs have even higher weight, since they are likely to be quick ones.
     * @var string
     */
    private const string calculatedPriority = '((CASE WHEN `frequency` = 0 THEN 1 ELSE (4294967295 - `frequency`) / 4294967295 END) + LOG(TIMESTAMPDIFF(SECOND, `nextrun`, CURRENT_TIMESTAMP(6)) + 2) * 100 + `priority` * 1000)';
    
    
    /**
     * Class constructor
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Cron database prefix.
     */
    public function __construct(\PDO|null $dbh = null, string $prefix = 'cron__')
    {
        $this->init($dbh, $prefix);
    }
    
    /**
     * Install the necessary tables
     * @return bool|string
     */
    public function install(): bool|string
    {
        $version = $this->getVersion();
        #Generate SQL to run
        $sql = '';
        if (version_compare('1.0.0', $version, 'gt')) {
            $sql .= 'CREATE TABLE IF NOT EXISTS `'.$this->prefix.'settings`
                    (
                        `setting`     varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'Name of the setting\',
                        `value`       int(10)                         DEFAULT NULL COMMENT \'Value of the setting\',
                        `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Description of the setting\',
                        PRIMARY KEY (`setting`)
                    ) ENGINE = InnoDB
                      DEFAULT CHARSET = utf8mb4
                      COLLATE = utf8mb4_unicode_ci
                      ROW_FORMAT = DYNAMIC;
                    
                    INSERT IGNORE INTO `'.$this->prefix.'settings` (`setting`, `value`, `description`)
                    VALUES (\'enabled\', 1, \'Whether cron is enabled. Will only affect processing, task management will still be possible.\'),
                           (\'errorlife\', 30, \'Days to keep errors in log. Older records will be removed on next CRON process.\'),
                           (\'maxtime\', 3600, \'Maximum amount of time in seconds to allow jobs to run. If the period elapses, a job will be considered hanged and will be rescheduled on next CRON processing.\'),
                           (\'retry\', 3600, \'Time in seconds to add to failed one-time jobs or hanged jobs, when rescheduling them\'),
                           (\'sseLoop\', 0, \'Whether we need to loop task processing when launched outside of CLI (that is SSE mode).\'),
                           (\'sseRetry\', 10000, \'Milliseconds for retry value of SSE\');
                    
                    CREATE TABLE IF NOT EXISTS `'.$this->prefix.'errors`
                    (
                        `time`      timestamp                       NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT \'Time the error occurred\',
                        `task`      varchar(100) COLLATE utf8mb4_unicode_ci  DEFAULT NULL COMMENT \'Optional task ID\',
                        `arguments` varchar(100) COLLATE utf8mb4_unicode_ci  DEFAULT NULL COMMENT \'Optional task arguments\',
                        `text`      text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'Error for the text\',
                        UNIQUE KEY `task` (`task`, `arguments`),
                        KEY `time` (`time`)
                    ) ENGINE = InnoDB
                      DEFAULT CHARSET = utf8mb4
                      COLLATE = utf8mb4_unicode_ci
                      ROW_FORMAT = DYNAMIC;
                    
                    CREATE TABLE IF NOT EXISTS `'.$this->prefix.'schedule`
                    (
                        `task`        varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'Task ID\',
                        `arguments`   varchar(255) COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT \'Optional arguments in JSON string\',
                        `frequency`   int(10) UNSIGNED                        NOT NULL DEFAULT 0 COMMENT \'Frequency to run a task in seconds\',
                        `dayofmonth`  varchar(255) COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT \'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.\',
                        `dayofweek`   varchar(60) COLLATE utf8mb4_unicode_ci           DEFAULT NULL COMMENT \'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.\',
                        `priority`    tinyint(3) UNSIGNED                     NOT NULL DEFAULT 0 COMMENT \'Priority of the task\',
                        `message`     varchar(100) COLLATE utf8mb4_unicode_ci          DEFAULT NULL COMMENT \'Optional message, that will be shown if launched outside of CLI\',
                        `status`      tinyint(1) UNSIGNED                     NOT NULL DEFAULT 0 COMMENT \'Flag showing whether the job is running or not\',
                        `runby`       varchar(30) COLLATE utf8mb4_unicode_ci           DEFAULT NULL COMMENT \'If not NULL, indicates, that a job is queued for a run by a process.\',
                        `registered`  timestamp                               NOT NULL DEFAULT current_timestamp() COMMENT \'When the job was initially registered\',
                        `updated`     timestamp                               NOT NULL DEFAULT current_timestamp() COMMENT \'When the job schedule was updated\',
                        `nextrun`     timestamp                               NOT NULL DEFAULT current_timestamp() COMMENT \'Next expected time for the job to be run\',
                        `lastrun`     timestamp                               NULL     DEFAULT NULL COMMENT \'Time of the last run attempt\',
                        `lastsuccess` timestamp                               NULL     DEFAULT NULL COMMENT \'Time of the last successful run\',
                        `lasterror`   timestamp                               NULL     DEFAULT NULL COMMENT \'Time of the last error\',
                        UNIQUE KEY `task` (`task`, `arguments`),
                        KEY `nextrun` (`nextrun`),
                        KEY `priority` (`priority`),
                        KEY `status` (`status`),
                        KEY `runby` (`runby`),
                        KEY `lastrun` (`lastrun`)
                    ) ENGINE = InnoDB
                      DEFAULT CHARSET = utf8mb4
                      COLLATE = utf8mb4_unicode_ci
                      ROW_FORMAT = DYNAMIC;
                    
                    CREATE TABLE IF NOT EXISTS `'.$this->prefix.'tasks`
                    (
                        `task`           varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'Function\'\'s internal ID\',
                        `function`       varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT \'Actual function reference, that will be called by Cron processor\',
                        `object`         varchar(255) COLLATE utf8mb4_unicode_ci  DEFAULT NULL COMMENT \'Optional object\',
                        `parameters`     varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Optional parameters used on initial object creation in JSON string\',
                        `allowedreturns` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Optional allowed return values to be treated as \'\'true\'\' by Cron processor in JSON string\',
                        `description`    varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Description of the task\',
                        PRIMARY KEY (`task`)
                    ) ENGINE = InnoDB
                      DEFAULT CHARSET = utf8mb4
                      COLLATE = utf8mb4_unicode_ci
                      ROW_FORMAT = DYNAMIC;
                    
                    ALTER TABLE `'.$this->prefix.'errors`
                        ADD CONSTRAINT `errors_to_tasks` FOREIGN KEY (`task`) REFERENCES `'.$this->prefix.'tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;
                    
                    ALTER TABLE `'.$this->prefix.'schedule`
                        ADD CONSTRAINT `schedule_to_task` FOREIGN KEY (`task`) REFERENCES `'.$this->prefix.'tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;';
        }
        if (version_compare('1.1.0', $version, 'gt')) {
            $sql .= 'INSERT IGNORE INTO `'.$this->prefix.'settings` (`setting`, `value`, `description`) VALUES (\'maxthreads\', 4, \'Maximum number of simultaneous threads to run\');';
        }
        if (version_compare('1.1.7', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'schedule` CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Optional task arguments\';';
        }
        if (version_compare('1.1.8', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'schedule` ADD INDEX `arguments` (`arguments`) USING BTREE;
                    ALTER TABLE `'.$this->prefix.'errors` ADD CONSTRAINT `errors_to_arguments` FOREIGN KEY (`arguments`) REFERENCES `'.$this->prefix.'schedule` (`arguments`) ON DELETE CASCADE ON UPDATE CASCADE;';
        }
        if (version_compare('1.1.12', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'schedule` CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\' COMMENT \'Optional arguments in JSON string\';
                    ALTER TABLE `'.$this->prefix.'schedule` DROP INDEX `task`, ADD PRIMARY KEY (`task`, `arguments`) USING BTREE;
                    ALTER TABLE `'.$this->prefix.'errors` CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\' COMMENT \'Optional task arguments\';
                    ALTER TABLE `'.$this->prefix.'errors` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\' COMMENT \'Optional task ID\';
                    ALTER TABLE `'.$this->prefix.'errors` DROP INDEX `task`, ADD PRIMARY KEY (`task`, `arguments`) USING BTREE;
                    ALTER TABLE `'.$this->prefix.'errors` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\\\'\\\'\' COMMENT \'Optional task ID\' FIRST, CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'\\\'\\\'\' COMMENT \'Optional task arguments\' AFTER `task`;';
        }
        if (version_compare('1.1.14', $version, 'gt')) {
            $sql .= 'UPDATE `'.$this->prefix.'settings` SET `setting`=\'errorLife\' WHERE `setting` = \'errorlife\';
                    UPDATE `'.$this->prefix.'settings` SET `setting`=\'maxTime\' WHERE `setting` = \'maxtime\';
                    UPDATE `'.$this->prefix.'settings` SET `setting`=\'maxThreads\' WHERE `setting` = \'maxthreads\';';
        }
        if (version_compare('1.2.0', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'schedule` ADD `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT \'0\' COMMENT \'Flag to indicate whether job is being ran by SSE call.\' AFTER `runby`;';
        }
        if (version_compare('1.3.0', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'tasks` ADD `maxTime` INT(10) UNSIGNED NOT NULL DEFAULT \'3600\' COMMENT \'Maximum time allowed for the task to run. If exceeded, it will be terminated by PHP.\' AFTER `allowedreturns`;
                    DELETE FROM `'.$this->prefix.'settings` WHERE `setting` = \'maxTime\';';
        }
        if (version_compare('1.5.0', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'errors` CHANGE `time` `time` DATETIME(6) on update CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'Time the error occurred\';
                    ALTER TABLE `'.$this->prefix.'schedule`
                        CHANGE `registered` `registered` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'When the job was initially registered\',
                        CHANGE `updated` `updated` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'When the job schedule was updated\',
                        CHANGE `nextrun` `nextrun` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'Next expected time for the job to be run\',
                        CHANGE `lastrun` `lastrun` DATETIME(6) NULL DEFAULT NULL COMMENT \'Time of the last run attempt\',
                        CHANGE `lastsuccess` `lastsuccess` DATETIME(6) NULL DEFAULT NULL COMMENT \'Time of the last successful run\',
                        CHANGE `lasterror` `lasterror` DATETIME(6) NULL DEFAULT NULL COMMENT \'Time of the last error\';';
        }
        if (version_compare('2.0.0', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'tasks` ADD `system` TINYINT(1) UNSIGNED NOT NULL DEFAULT \'0\' COMMENT \'Flag indicating that task is system and can\\\'t be deleted from Cron\\Task class\' AFTER `maxTime`;
                    ALTER TABLE `'.$this->prefix.'schedule` ADD `system` TINYINT(1) UNSIGNED NOT NULL DEFAULT \'0\' COMMENT \'Flag indicating whether a task instance is system one and can\\\'t be deleted from Cron\\Schedule class\' AFTER `arguments`;
                    ALTER TABLE `'.$this->prefix.'schedule` ADD `instance` INT(10) UNSIGNED NOT NULL DEFAULT \'1\' COMMENT \'Instance number of the task\' AFTER `arguments`;
                    ALTER TABLE `'.$this->prefix.'schedule` DROP PRIMARY KEY, ADD PRIMARY KEY (`task`, `arguments`, `instance`) USING BTREE;
                    ALTER TABLE `'.$this->prefix.'schedule` CHANGE `status` `status` TINYINT(1) UNSIGNED NOT NULL DEFAULT \'0\' COMMENT \'Flag showing whether the task is running or not\';
                    ALTER TABLE `'.$this->prefix.'schedule`
                        CHANGE `runby` `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT \'If not NULL, indicates, that a task is queued for a run by a process.\',
                        CHANGE `sse` `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT \'0\' COMMENT \'Flag to indicate whether task is being ran by SSE call.\',
                        CHANGE `registered` `registered` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT \'When the task was initially registered.\',
                        CHANGE `updated` `updated` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT \'When the task schedule was updated.\',
                        CHANGE `nextrun` `nextrun` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT \'Next expected time for the task to be run.\';
                    RENAME TABLE `'.$this->prefix.'errors` TO `'.$this->prefix.'log`;
                    ALTER TABLE `'.$this->prefix.'log` DROP FOREIGN KEY `errors_to_tasks`;
                    ALTER TABLE `'.$this->prefix.'schedule` DROP FOREIGN KEY `schedule_to_task`;
                    ALTER TABLE `'.$this->prefix.'log` DROP PRIMARY KEY;
                    ALTER TABLE `'.$this->prefix.'log` CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT \'Optional task ID\';
                    ALTER TABLE `'.$this->prefix.'log` CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT \'Optional task arguments\';
                    ALTER TABLE `'.$this->prefix.'log` DROP FOREIGN KEY `errors_to_arguments`;
                    ALTER TABLE `'.$this->prefix.'log` DROP INDEX `errors_to_arguments`;
                    ALTER TABLE `'.$this->prefix.'log` CHANGE `time` `time` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) on update current_timestamp(6) COMMENT \'Time the error occurred\' FIRST;
                    ALTER TABLE `'.$this->prefix.'log` CHANGE `time` `time` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT \'Time the error occurred\';
                    ALTER TABLE `'.$this->prefix.'log`
                        ADD `type`  VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL DEFAULT \'Status\' COMMENT \'Event type\' AFTER `time`,
                        ADD `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL     DEFAULT NULL COMMENT \'Indicates process that was running a task\' AFTER `type`;
                    ALTER TABLE `'.$this->prefix.'log`
                        CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Optional task ID\',
                        CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Optional task arguments\',
                        CHANGE `text` `text` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Error for the text\';
                    ALTER TABLE `'.$this->prefix.'schedule`
                        CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Task ID\',
                        CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Optional arguments in JSON string\',
                        CHANGE `dayofmonth` `dayofmonth` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.\',
                        CHANGE `dayofweek` `dayofweek` VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.\',
                        CHANGE `message` `message` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Optional message, that will be shown if launched outside of CLI\',
                        CHANGE `runby` `runby` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'If not NULL, indicates, that a task is queued for a run by a process.\';
                    ALTER TABLE `'.$this->prefix.'tasks`
                        CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Function\\\'s internal ID\',
                        CHANGE `function` `function` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Actual function reference, that will be called by Cron processor\',
                        CHANGE `object` `object` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Optional object\',
                        CHANGE `parameters` `parameters` VARCHAR(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Optional parameters used on initial object creation in JSON string\',
                        CHANGE `allowedreturns` `allowedreturns` VARCHAR(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Optional allowed return values to be treated as \\\'true\\\' by Cron processor in JSON string\',
                        CHANGE `description` `description` VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Description of the task\';
                    ALTER TABLE `'.$this->prefix.'settings`
                        CHANGE `setting` `setting` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Name of the setting\',
                        CHANGE `description` `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Description of the setting\';
                    ALTER TABLE `'.$this->prefix.'log` ADD CONSTRAINT `errors_to_tasks` FOREIGN KEY (`task`) REFERENCES `'.$this->prefix.'tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;
                    ALTER TABLE `'.$this->prefix.'schedule` ADD CONSTRAINT `schedule_to_task` FOREIGN KEY (`task`) REFERENCES `'.$this->prefix.'tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;
                    ALTER TABLE `'.$this->prefix.'log` ADD `instance` INT(10) UNSIGNED NULL DEFAULT NULL COMMENT \'Instance number of the task\' AFTER `arguments`;
                    ALTER TABLE `'.$this->prefix.'log` CHANGE `text` `message` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Error for the text\';
                    ALTER TABLE `'.$this->prefix.'log` ADD `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT \'0\' COMMENT \'Flag to indicate whether task was being ran by SSE call\' AFTER `runby`;
                    TRUNCATE `'.$this->prefix.'log`;
                    UPDATE `'.$this->prefix.'settings` SET `setting`     = \'logLife\', `description` = \'Days to keep messages in log. Older records will be removed on next CRON process.\' WHERE `setting` = \'errorLife\';
                    ALTER TABLE `'.$this->prefix.'log` ADD INDEX `time_desc` (`time` DESC) USING BTREE;
                    ALTER TABLE `'.$this->prefix.'log` ADD INDEX `type` (`type`) USING BTREE;
                    ALTER TABLE `'.$this->prefix.'log` ADD INDEX `runby` (`runby`) USING BTREE;
                    ALTER TABLE `'.$this->prefix.'log` ADD INDEX `task` (`task`) USING BTREE;';
        }
        if (version_compare('2.1.2', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'settings` CHANGE `value` `value` VARCHAR(10) NULL DEFAULT NULL COMMENT \'Value of the setting\';
                    INSERT IGNORE INTO `'.$this->prefix.'settings` (`setting`, `value`, `description`) VALUES (\'version\', \'2.1.2\', \'Version of cron database, based on release version in which it was last modified\');';
        }
        if (version_compare('2.2.0', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'log` CHANGE `message` `message` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Message provided by the event\';
                    ALTER TABLE `'.$this->prefix.'tasks` ADD `minFrequency` INT(10) UNSIGNED NOT NULL DEFAULT \'60\' COMMENT \'Minimal allowed frequency (in seconds) at which a task instance can run. Does not apply to one-time jobs.\' AFTER `maxTime`;
                    ALTER TABLE `'.$this->prefix.'tasks` ADD `retry` INT UNSIGNED NOT NULL DEFAULT \'0\' COMMENT \'Custom number of seconds to reschedule a failed task instance for. 0 disables the functionality.\' AFTER `minFrequency`;
                    CREATE TABLE IF NOT EXISTS `'.$this->prefix.'event_types` (`type` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Type of the event\' , `description` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NOT NULL COMMENT \'Description of the event\' ) ENGINE = InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_nopad_ci ROW_FORMAT = DYNAMIC COMMENT = \'Different event types for logging and SSE output\';
                    ALTER TABLE `'.$this->prefix.'event_types` ADD PRIMARY KEY(`type`);
                    ALTER TABLE `'.$this->prefix.'log` DROP FOREIGN KEY `errors_to_tasks`;
                    ALTER TABLE `'.$this->prefix.'log` ADD CONSTRAINT `log_to_tasks` FOREIGN KEY (`task`) REFERENCES `'.$this->prefix.'tasks`(`task`) ON DELETE CASCADE ON UPDATE CASCADE;
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CronStart\', \'Start of cron processing.\'), (\'CronFail\', \'Failure of cron processing.\'), (\'CronTaskStart\', \'A task instance was started.\'), (\'CronTaskEnd\', \'A task instance completed successfully.\'), (\'CronTaskFail\', \'A task instance failed.\'), (\'CronEmpty\', \'Empty list of tasks in the cycle.\'), (\'CronNoThreads\', \'No free threads in this cycle.\'), (\'CronEnd\', \'End of cron processing.\'), (\'Reschedule\', \'A task instance was rescheduled.\'), (\'RescheduleFail\', \'A task instance failed to be rescheduled.\'), (\'TaskAdd\', \'A task was added or updated.\'), (\'TaskAddFail\', \'A task failed to be added or updated.\'), (\'TaskDelete\', \'A task was deleted.\'), (\'TaskDeleteFail\', \'A task failed to be deleted.\'), (\'TaskToSystem\', \'A task was marked as system one.\'), (\'TaskToSystemFail\', \'A task failed to be marked as system one.\'), (\'InstanceAdd\', \'A task instance was added or updated.\'), (\'InstanceAddFail\', \'A task instance failed to be added or updated.\'), (\'InstanceDelete\', \'A task instance was deleted.\'), (\'InstanceDeleteFail\', \'A task instance failed to be deleted.\'), (\'InstanceToSystem\', \'A task instance was marked as system one.\'), (\'InstanceToSystemFail\', \'A task instance failed to be marked as system one.\');
                    ALTER TABLE `'.$this->prefix.'log` DROP FOREIGN KEY `log_to_tasks`;
                    ALTER TABLE `'.$this->prefix.'log` ADD CONSTRAINT `cron_log_to_tasks` FOREIGN KEY (`task`) REFERENCES `'.$this->prefix.'tasks`(`task`) ON DELETE CASCADE ON UPDATE CASCADE;
                    ALTER TABLE `'.$this->prefix.'log` ADD CONSTRAINT `cron_log_to_event_type` FOREIGN KEY (`type`) REFERENCES `'.$this->prefix.'event_types`(`type`) ON DELETE CASCADE ON UPDATE CASCADE;
                    UPDATE `'.$this->prefix.'event_types` SET `type` = \'InstanceEnd\' WHERE `'.$this->prefix.'event_types`.`type` = \'CronTaskEnd\';
                    UPDATE `'.$this->prefix.'event_types` SET `type` = \'InstanceFail\' WHERE `'.$this->prefix.'event_types`.`type` = \'CronTaskFail\';
                    UPDATE `'.$this->prefix.'event_types` SET `type` = \'InstanceStart\' WHERE `'.$this->prefix.'event_types`.`type` = \'CronTaskStart\';
                    UPDATE `'.$this->prefix.'event_types` SET `type` = \'SSEEnd\' WHERE `'.$this->prefix.'event_types`.`type` = \'CronEnd\';
                    UPDATE `'.$this->prefix.'event_types` SET `description` = \'End of cron processing in SSE mode.\' WHERE `'.$this->prefix.'event_types`.`type` = \'SSEEnd\';
                    UPDATE `'.$this->prefix.'event_types` SET `type` = \'SSEStart\' WHERE `'.$this->prefix.'event_types`.`type` = \'CronStart\';
                    UPDATE `'.$this->prefix.'event_types` SET `description` = \'Start of cron processing in SSE mode.\' WHERE `'.$this->prefix.'event_types`.`type` = \'SSEStart\';
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CronDisabled\', \'Cron processing is disabled in settings.\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CustomEmergency\', \'Custom event indicating an emergency (SysLog standard level 0).\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CustomAlert\', \'Custom event indicating an alert (SysLog standard level 1).\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CustomCritical\', \'Custom event indicating a critical condition (SysLog standard level 2).\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CustomError\', \'Custom event indicating an error (SysLog standard level 3).\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CustomWarning\', \'Custom event indicating a warning (SysLog standard level 4).\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CustomNotice\', \'Custom event indicating a notice (SysLog standard level 5).\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CustomInformation\', \'Custom event indicating an informative message (SysLog standard level 6).\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'CustomDebug\', \'Custom event indicating a debugging message (SysLog standard level 7).\');
                    ALTER TABLE `'.$this->prefix.'tasks` ADD `enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT \'1\' COMMENT \'Whether a task (and thus all its instances) is enabled and should be run as per schedule\' AFTER `retry`;
                    ALTER TABLE `'.$this->prefix.'schedule` ADD `enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT \'1\' COMMENT \'Whether a task instance is enabled and should be run as per schedule\' AFTER `instance`;
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'TaskEnable\', \'Task was enabled.\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'TaskDisable\', \'Task was disabled.\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'TaskEnableFail\', \'Failed to enable task.\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'TaskDisableFail\', \'Failed to disable task.\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'InstanceEnable\', \'Task instance was enabled.\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'InstanceDisable\', \'Task instance was disabled.\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'InstanceEnableFail\', \'Failed to enable task instance.\');
                    INSERT INTO `'.$this->prefix.'event_types` (`type`, `description`) VALUES (\'InstanceDisableFail\', \'Failed to disable task instance.\');
                    ALTER TABLE `'.$this->prefix.'tasks` ADD INDEX(`enabled`);
                    ALTER TABLE `'.$this->prefix.'schedule` ADD INDEX(`enabled`);
                    UPDATE `'.$this->prefix.'settings` SET `value` = \'2.2.0\' WHERE `setting` = \'version\';';
        }
        if (version_compare('2.2.1', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'settings` CHANGE `description` `description` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_nopad_ci NULL DEFAULT NULL COMMENT \'Description of the setting\';
                    UPDATE `'.$this->prefix.'settings` SET `value` = \'2.2.1\' WHERE `setting` = \'version\';';
        }
        if (version_compare('2.2.2', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'event_types` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
                    ALTER TABLE `'.$this->prefix.'log` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
                    ALTER TABLE `'.$this->prefix.'schedule` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
                    ALTER TABLE `'.$this->prefix.'settings` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
                    ALTER TABLE `'.$this->prefix.'tasks` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs;
                    ALTER TABLE `'.$this->prefix.'event_types` MODIFY `description` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT \'Description of the event\';
                    ALTER TABLE `'.$this->prefix.'settings` MODIFY `value` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Value of the setting\';
                    ALTER TABLE `'.$this->prefix.'settings` MODIFY `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT \'Description of the setting\';
                    ALTER TABLE `'.$this->prefix.'tasks` MODIFY `function` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL COMMENT \'Actual function reference, that will be called by Cron processor\';
                    ALTER TABLE `'.$this->prefix.'tasks` MODIFY `object` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Optional object\';
                    ALTER TABLE `'.$this->prefix.'tasks` MODIFY `parameters` varchar(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Optional parameters used on initial object creation in JSON string\';
                    ALTER TABLE `'.$this->prefix.'tasks` MODIFY `allowedreturns` varchar(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Optional allowed return values to be treated as \\\'true\\\' by Cron processor in JSON string\';
                    ALTER TABLE `'.$this->prefix.'tasks` MODIFY `description` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT \'Description of the task\';
                    ALTER TABLE `'.$this->prefix.'schedule` MODIFY `dayofmonth` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.\';
                    ALTER TABLE `'.$this->prefix.'schedule` MODIFY `dayofweek` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.\';
                    ALTER TABLE `'.$this->prefix.'schedule` MODIFY `message` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT \'Optional message, that will be shown if launched outside of CLI\';
                    ALTER TABLE `'.$this->prefix.'log` MODIFY `arguments` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Optional task arguments\';
                    ALTER TABLE `'.$this->prefix.'log` MODIFY `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT \'Message provided by the event\';
                    ALTER TABLE `'.$this->prefix.'settings` MODIFY `setting` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL COMMENT \'Name of the setting\';
                    ALTER TABLE `'.$this->prefix.'schedule` MODIFY `arguments` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL COMMENT \'Optional arguments in JSON string\';
                    ALTER TABLE `'.$this->prefix.'schedule` MODIFY `runby` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'If not NULL, indicates, that a task is queued for a run by a process.\';
                    ALTER TABLE `'.$this->prefix.'log` MODIFY `runby` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Indicates process that was running a task\';
                    ALTER TABLE `'.$this->prefix.'log` DROP FOREIGN KEY `cron_log_to_event_type`;
                    ALTER TABLE `'.$this->prefix.'log` DROP FOREIGN KEY `cron_log_to_tasks`;
                    ALTER TABLE `'.$this->prefix.'schedule` DROP FOREIGN KEY `schedule_to_task`;
                    ALTER TABLE `'.$this->prefix.'event_types` MODIFY `type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL COMMENT \'Type of the event\';
                    ALTER TABLE `'.$this->prefix.'log` MODIFY `type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL DEFAULT \'Status\' COMMENT \'Event type\';
                    ALTER TABLE `'.$this->prefix.'tasks` MODIFY `task` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL COMMENT \'Function\\\'s internal ID\';
                    ALTER TABLE `'.$this->prefix.'schedule` MODIFY `task` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NOT NULL COMMENT \'Task ID\';
                    ALTER TABLE `'.$this->prefix.'log` MODIFY `task` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs DEFAULT NULL COMMENT \'Optional task ID\';
                    ALTER TABLE `'.$this->prefix.'log` ADD CONSTRAINT `cron_log_to_event_type` FOREIGN KEY (`type`) REFERENCES `'.$this->prefix.'event_types` (`type`) ON DELETE CASCADE ON UPDATE CASCADE;
                    ALTER TABLE `'.$this->prefix.'log` ADD CONSTRAINT `cron_log_to_tasks` FOREIGN KEY (`task`) REFERENCES `'.$this->prefix.'tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;
                    ALTER TABLE `'.$this->prefix.'schedule` ADD CONSTRAINT `schedule_to_task` FOREIGN KEY (`task`) REFERENCES `'.$this->prefix.'tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;
                    UPDATE `'.$this->prefix.'settings` SET `value` = \'2.2.2\' WHERE `setting` = \'version\';';
        }
        if (version_compare('2.3.4', $version, 'gt')) {
            $sql .= 'ALTER TABLE `'.$this->prefix.'log` DROP FOREIGN KEY `cron_log_to_event_type`;
                    ALTER TABLE `'.$this->prefix.'log` DROP FOREIGN KEY `cron_log_to_tasks`;
                    ALTER TABLE `'.$this->prefix.'schedule` DROP FOREIGN KEY `schedule_to_task`;
                    ALTER TABLE `'.$this->prefix.'log` CHANGE `runby` `runBy` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NULL DEFAULT NULL COMMENT \'Indicates process that was running a task\';
                    ALTER TABLE `'.$this->prefix.'tasks` CHANGE `allowedreturns` `allowedReturns` VARCHAR(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NULL DEFAULT NULL COMMENT \'Optional allowed return values to be treated as \\\'true\\\' by Cron processor in JSON string\';
                    ALTER TABLE `'.$this->prefix.'schedule` CHANGE `dayofmonth` `dayOfMonth` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NULL DEFAULT NULL COMMENT \'Optional limit to run only on specific days of the month. Expects array of integers in JSON string.\';
                    ALTER TABLE `'.$this->prefix.'schedule` CHANGE `dayofweek` `dayOfWeek` VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NULL DEFAULT NULL COMMENT \'Optional limit to run only on specific days of the week. Expects array of integers in JSON string.\';
                    ALTER TABLE `'.$this->prefix.'schedule` CHANGE `runby` `runBy` VARCHAR(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_as_cs NULL DEFAULT NULL COMMENT \'If not NULL, indicates, that a task is queued for a run by a process.\';
                    ALTER TABLE `'.$this->prefix.'schedule` CHANGE `nextrun` `nextRun` DATETIME(6) NOT NULL DEFAULT current_timestamp(6) COMMENT \'Next expected time for the task to be run.\';
                    ALTER TABLE `'.$this->prefix.'schedule` CHANGE `lastrun` `lastRun` DATETIME(6) NULL DEFAULT NULL COMMENT \'Time of the last run attempt\';
                    ALTER TABLE `'.$this->prefix.'schedule` CHANGE `lastsuccess` `lastSuccess` DATETIME(6) NULL DEFAULT NULL COMMENT \'Time of the last successful run\';
                    ALTER TABLE `'.$this->prefix.'schedule` CHANGE `lasterror` `lastError` DATETIME(6) NULL DEFAULT NULL COMMENT \'Time of the last error\';
                    UPDATE `'.$this->prefix.'settings` SET `value` = \'2.3.4\' WHERE `setting` = \'version\';';
        }
        #If empty - we are up to date
        if (empty($sql)) {
            return true;
        }
        try {
            return Query::query($sql);
        } catch (\Throwable $e) {
            return $e->getMessage()."\r\n".$e->getTraceAsString();
        }
    }
    
    /**
     * Get the current version of the Agent from the database perspective (can be different from the library version)
     * @return string
     */
    public function getVersion(): string
    {
        #Check if the settings table exists
        if (Manage::checkTable($this->prefix.'settings') === 1) {
            #Assume that we have installed the database, try to get the version
            $version = Query::query('SELECT `value` FROM `'.$this->prefix.'settings` WHERE `setting`=\'version\'', return: 'value');
            #If an empty installer script was run before 2.1.2, we need to determine what version we have based on other things
            if (empty($version)) {
                #If errors' table does not exist, and the log table does - we are on version 2.0.0
                if (Manage::checkTable($this->prefix.'errors') === 0 && Manage::checkTable($this->prefix.'log') === 1) {
                    $version = '2.0.0';
                    #If one of the schedule columns is datetime, it's 1.5.0
                } elseif (Manage::getColumnType($this->prefix.'schedule', 'registered') === 'datetime') {
                    $version = '1.5.0';
                    #If `maxTime` column is present in `tasks` table - 1.3.0
                } elseif (Manage::checkColumn($this->prefix.'tasks', 'maxTime')) {
                    $version = '1.3.0';
                    #If `maxTime` column is present in `tasks` table - 1.2.0
                } elseif (Manage::checkColumn($this->prefix.'schedule', 'sse')) {
                    $version = '1.2.0';
                    #If one of the settings has the name `errorLife` (and not `errorlife`) - 1.1.14
                } elseif (Query::query('SELECT `setting` FROM `'.$this->prefix.'settings` WHERE `setting`=\'errorLife\'', return: 'value') === 'errorLife') {
                    $version = '1.1.14';
                    #If the `arguments` column is not nullable - 1.1.12
                } elseif (!Manage::isNullable($this->prefix.'schedule', 'arguments')) {
                    $version = '1.1.12';
                    #If `errors_to_arguments` Foreign Key exists in `errors` table - 1.1.8
                } elseif (Manage::checkFK($this->prefix.'_errors', 'errors_to_arguments')) {
                    $version = '1.1.8';
                    #It's 1.1.7 if the old column description is used
                } elseif (Manage::getColumnDescription($this->prefix.'schedule', 'arguments') === 'Optional task arguments') {
                    $version = '1.1.7';
                    #If the `maxthreads` setting exists - it's 1.1.0
                } elseif (Query::query('SELECT `setting` FROM `'.$this->prefix.'settings` WHERE `setting`=\'maxthreads\'', return: 'value') === 'maxthreads') {
                    $version = '1.1.0';
                    #Otherwise - version 1.0.0
                } else {
                    $version = '1.0.0';
                }
            }
        } else {
            $version = '0.0.0';
        }
        return $version;
    }
}