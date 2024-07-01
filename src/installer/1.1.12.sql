ALTER TABLE `cron__schedule`
    CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Optional arguments in JSON string';
ALTER TABLE `cron__schedule`
    DROP INDEX `task`,
    ADD PRIMARY KEY (`task`, `arguments`) USING BTREE;

ALTER TABLE `cron__errors`
    CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Optional task arguments';

ALTER TABLE `cron__errors`
    CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'Optional task ID';

ALTER TABLE `cron__errors`
    DROP INDEX `task`,
    ADD PRIMARY KEY (`task`, `arguments`) USING BTREE;

ALTER TABLE `cron__errors`
    CHANGE `task` `task` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '\'\'' COMMENT 'Optional task ID' FIRST,
    CHANGE `arguments` `arguments` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '\'\'' COMMENT 'Optional task arguments' AFTER `task`;