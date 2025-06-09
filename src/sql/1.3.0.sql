ALTER TABLE `cron__tasks`
    ADD `maxTime` INT(10) UNSIGNED NOT NULL DEFAULT '3600' COMMENT 'Maximum time allowed for the task to run. If exceeded, it will be terminated by PHP.' AFTER `allowedreturns`;

DELETE
FROM `cron__settings`
WHERE `cron__settings`.`setting` = 'maxTime';