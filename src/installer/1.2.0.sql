ALTER TABLE `cron__schedule`
    ADD `sse` TINYINT(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Flag to indicate whether job is being ran by SSE call.' AFTER `runby`;