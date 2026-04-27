ALTER TABLE `cron__errors`
    CHANGE `time` `time` DATETIME(6) on update CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Time the error occurred';

ALTER TABLE `cron__schedule`
    CHANGE `registered` `registered` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the job was initially registered',
    CHANGE `updated` `updated` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the job schedule was updated',
    CHANGE `nextrun` `nextrun` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Next expected time for the job to be run',
    CHANGE `lastrun` `lastrun` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last run attempt',
    CHANGE `lastsuccess` `lastsuccess` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last successful run',
    CHANGE `lasterror` `lasterror` DATETIME(6) NULL DEFAULT NULL COMMENT 'Time of the last error';