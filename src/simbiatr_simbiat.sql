-- phpMyAdmin SQL Dump
-- version 5.1.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Apr 14, 2021 at 04:38 AM
-- Server version: 10.5.9-MariaDB
-- PHP Version: 8.0.3

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `simbiatr_simbiat`
--

-- --------------------------------------------------------

--
-- Table structure for table `cron__errors`
--

CREATE TABLE IF NOT EXISTS `cron__errors` (
  `time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Time the error occured',
  `task` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional task ID',
  `arguments` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional task arguments',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Error for the text',
  UNIQUE KEY `task` (`task`,`arguments`),
  KEY `time` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `cron__errors`:
--   `task`
--       `cron__tasks` -> `task`
--

-- --------------------------------------------------------

--
-- Table structure for table `cron__schedule`
--

CREATE TABLE IF NOT EXISTS `cron__schedule` (
  `task` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Task ID',
  `arguments` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional arguments in JSON string',
  `schedule` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Time period in seconds',
  `priority` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Priority of the task',
  `message` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional message, that will be shown if launched outside of CLI',
  `status` tinyint(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Flag showing whether the job is running or not',
  `registered` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When the job was initially registered',
  `updated` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'When the job schedule was updated',
  `nextrun` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Next expected time for the job to be run',
  `lastrun` timestamp NULL DEFAULT NULL COMMENT 'Time of the last run attempt',
  `lastsuccess` timestamp NULL DEFAULT NULL COMMENT 'Time of the last successful run',
  `lasterror` timestamp NULL DEFAULT NULL COMMENT 'Time of the last error',
  UNIQUE KEY `task` (`task`,`arguments`),
  KEY `nextrun` (`nextrun`),
  KEY `priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED;

--
-- RELATIONSHIPS FOR TABLE `cron__schedule`:
--   `task`
--       `cron__tasks` -> `task`
--

-- --------------------------------------------------------

--
-- Table structure for table `cron__settings`
--

CREATE TABLE IF NOT EXISTS `cron__settings` (
  `setting` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name of the setting',
  `value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Value of the setting',
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Description of the setting',
  PRIMARY KEY (`setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `cron__settings`:
--

--
-- Dumping data for table `cron__settings`
--

INSERT IGNORE INTO `cron__settings` (`setting`, `value`, `description`) VALUES
('enabled', '1', 'Whether cron is enabled. Will only affect processing, task management will still be possible.'),
('errorlife', '30', 'Days to keep errors in log. Older records will be removed on next CRON process.'),
('maxtime', '3600', 'Maximum amount of time in seconds to allow jobs to run. If the period elapses, a job will be considered hanged and will be rescheduled on next CRON processing.'),
('retry', '3600', 'Time in seconds to add to failed one-time jobs or hanged jobs, when rescheduling them');

-- --------------------------------------------------------

--
-- Table structure for table `cron__tasks`
--

CREATE TABLE IF NOT EXISTS `cron__tasks` (
  `task` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Function''s internal ID',
  `function` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Actual function reference, that will be called by Cron processor',
  `object` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional object',
  `parameters` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional parameters used on initial object creation in JSON string',
  `allowedreturns` varchar(5000) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Optional allowed return values to be treated as ''true'' by Cron processor in JSON string',
  `description` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Description of the task',
  PRIMARY KEY (`task`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- RELATIONSHIPS FOR TABLE `cron__tasks`:
--

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cron__errors`
--
ALTER TABLE `cron__errors`
  ADD CONSTRAINT `errors_to_tasks` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `cron__schedule`
--
ALTER TABLE `cron__schedule`
  ADD CONSTRAINT `schedule_to_task` FOREIGN KEY (`task`) REFERENCES `cron__tasks` (`task`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
