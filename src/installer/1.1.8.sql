ALTER TABLE `simbiatr_simbiat`.`cron__schedule`
    ADD INDEX `arguments` (`arguments`) USING BTREE;

ALTER TABLE `cron__errors`
    ADD CONSTRAINT `errors_to_arguments` FOREIGN KEY (`arguments`) REFERENCES `cron__schedule` (`arguments`) ON DELETE CASCADE ON UPDATE CASCADE;