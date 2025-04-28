<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use JetBrains\PhpStorm\ExpectedValues;
use Simbiat\Database\Manage;
use Simbiat\Database\Query;
use Simbiat\HTTP\SSE;
use Simbiat\SandClock;
use function in_array;

/**
 * Task scheduler that uses MySQL/MariaDB database to store tasks and their schedule.
 */
class Agent
{
    /**
     * Flag to indicate that we are ready to work with DB
     * @var bool
     */
    public static bool $dbReady = false;
    /**
     * Flag to indicate whether Cron is enabled
     * @var bool
     */
    private static bool $enabled = false;
    /**
     * Retry time for one-time jobs
     * @var int
     */
    public static int $retry = 3600;
    /**
     * Days to store errors for
     * @var int
     */
    private static int $logLife = 30;
    /**
     * Flag to indicate whether SSE is looped or not
     * @var bool
     */
    private static bool $sseLoop = false;
    /**
     * Number of milliseconds for connection retry for SSE. Will also be used to determine how long should the loop sleep if no threads or jobs, but will be treated as a number of seconds divided by 20. The default is `10000` (or roughly 8 minutes for empty cycles).
     * @var int
     */
    private static int $sseRetry = 10000;
    /**
     * Maximum threads
     * @var int
     */
    private static int $maxThreads = 4;
    /**
     * Supported settings
     * @var array
     */
    private const array settings = ['enabled', 'logLife', 'retry', 'sseLoop', 'sseRetry', 'maxThreads'];
    /**
     * Logic to calculate task priority. Not sure, I fully understand how this provides the results I expect, but it does. Essentially, `priority` is valued higher, while "overdue" time has a smoother scaling. Rare jobs (with higher value of `frequency`) also have higher weight, but one-time jobs have even higher weight, since they are likely to be quick ones.
     * @var string
     */
    private static string $calculatedPriority = '((CASE WHEN `frequency` = 0 THEN 1 ELSE (4294967295 - `frequency`) / 4294967295 END) + LOG(TIMESTAMPDIFF(SECOND, `nextrun`, CURRENT_TIMESTAMP(6)) + 2) * 100 + `priority` * 1000)';
    /**
     * Random ID
     * @var null|string
     */
    private static ?string $runby = null;
    /**
     * Current task object
     * @var null|TaskInstance
     */
    private static ?TaskInstance $currentTask = null;
    /**
     * List of event types that are allowed to not have TaskInstance object with them
     * @var array
     */
    private const array eventsNoInstance = ['SSEStart', 'CronFail', 'CronEmpty', 'CronNoThreads', 'SSEEnd', 'TaskToSystem', 'TaskToSystemFail', 'TaskAdd', 'TaskAddFail', 'TaskDelete', 'TaskDeleteFail', 'CronDisabled'];
    
    /**
     * Class constructor
     * @param \PDO|null $dbh PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @throws \Exception
     */
    public function __construct(\PDO|null $dbh = null)
    {
        #Check that a database connection is established
        if (!self::$dbReady) {
            #Establish it, if possible
            new Query($dbh);
            self::$dbReady = true;
            $this->getSettings();
        }
    }
    
    /**
     * Set current task instance
     * @param \Simbiat\Cron\TaskInstance|null $currentTask
     */
    public static function setCurrentTask(?TaskInstance $currentTask): void
    {
        self::$currentTask = $currentTask;
    }
    
    /**
     * Generate random ID to be used by threads
     * @return false|string
     */
    public static function generateRunBy(): false|string
    {
        try {
            return bin2hex(random_bytes(15));
        } catch (\Throwable $exception) {
            self::log('Failed to generate random ID', 'CronFail', true, $exception);
            return false;
        }
    }
    
    /**
     * Process Cron items
     *
     * @param int $items Number of items to process
     *
     * @return bool
     * @throws \JsonException
     * @throws \DateMalformedStringException
     */
    public function process(int $items = 1): bool
    {
        #Start stream if not in CLI
        if (SSE::isPossible()) {
            SSE::open();
        }
        #Generate random ID
        self::$runby = self::generateRunBy();
        if (SSE::$SSE) {
            self::log('Cron processing started in SSE mode', 'SSEStart');
        }
        #Regular maintenance
        if (self::$dbReady) {
            #Reschedule hanged jobs
            $this->unHang();
            #Clean old logs
            $this->logPurge();
        } else {
            #Notify about the end of the stream
            self::log('Cron database not available', 'CronFail', true);
            return false;
        }
        #Check if cron is enabled and process only if it is
        if (!self::$enabled) {
            #Notify about the end of the stream
            self::log('Cron processing is disabled', 'CronDisabled', true);
            return false;
        }
        #Sanitize the number of items
        if ($items < 1) {
            $items = 1;
        }
        do {
            if (!$this->getSettings()) {
                self::log('Failed to get CRON settings', 'CronFail', true);
                return false;
            }
            #Check if enough threads are available
            try {
                if (Query::query('SELECT COUNT(DISTINCT(`runby`)) as `count` FROM `cron__schedule` WHERE `runby` IS NOT NULL;', return: 'count') >= self::$maxThreads) {
                    self::log('Cron threads are exhausted', 'CronNoThreads');
                    if (!SSE::$SSE) {
                        return false;
                    }
                    #Sleep for a bit
                    sleep(self::$sseRetry / 20);
                    continue;
                }
            } catch (\Throwable $exception) {
                self::log('Failed to check for available threads', 'CronFail', true, $exception);
                return false;
            }
            #Queue tasks for this random ID
            $tasks = $this->getTasks($items);
            if (empty($tasks)) {
                self::log('Cron list is empty', 'CronEmpty');
                if (SSE::$SSE) {
                    #Sleep for a bit
                    sleep(self::$sseRetry / 20);
                }
            } else {
                $totalTasks = \count($tasks);
                foreach ($tasks as $number => $task) {
                    $this->runTask($task, $number + 1, $totalTasks);
                }
            }
            #Additionally, reschedule hanged jobs if we're in SSE
            if (SSE::$SSE && self::$sseLoop) {
                $this->unHang();
            }
        } while (self::$enabled && SSE::$SSE && self::$sseLoop && connection_status() === 0);
        #Notify about the end of the stream
        if (SSE::$SSE) {
            self::log('Cron processing finished', 'SSEEnd', true);
        }
        return true;
    }
    
    /**
     * Wrapper for running the task
     * @param array $task       Task object
     * @param int   $number     Current task number
     * @param int   $totalTasks Total number of tasks
     *
     * @return void
     */
    private function runTask(array $task, int $number, int $totalTasks): void
    {
        try {
            $taskInstance = (new TaskInstance($task['task'], $task['arguments'], $task['instance']));
            #Notify of the task starting
            self::log($number.'/'.$totalTasks.' '.(empty($task['message']) ? $task['task'].' starting' : $task['message']), 'InstanceStart', task: $taskInstance);
            #Attemp to run
            $result = $taskInstance->run();
        } catch (\Throwable $exception) {
            self::log('Failed to run task `'.$task['task'].'` ('.$number.'/'.$totalTasks.')', 'InstanceFail', false, $exception, ($taskInstance ?? null));
            return;
        } finally {
            self::$currentTask = null;
        }
        #Notify of the task finishing
        if ($result) {
            self::log($number.'/'.$totalTasks.' '.$task['task'].' finished'.($taskInstance->frequency === 0 ? ' and deleted' : ''), 'InstanceEnd', task: $taskInstance);
        } else {
            self::log($number.'/'.$totalTasks.' '.$task['task'].' failed', 'InstanceFail', task: $taskInstance);
        }
    }
    
    /**
     * Schedule and get a list of tasks using a previously generated random ID
     * @param int $items Number of items to select
     *
     * @return bool|array
     */
    private function getTasks(int $items): bool|array
    {
        try {
            Query::query('UPDATE `cron__schedule` AS `toUpdate`
                        INNER JOIN
                        (
                            SELECT `task`, `arguments`, `instance` FROM (
                                SELECT `task`, `arguments`, `instance`, `nextrun`, '.self::$calculatedPriority.' AS `calculated` FROM `cron__schedule` AS `instances`
                                WHERE `enabled`=1 AND `runby` IS NULL AND `nextrun`<=CURRENT_TIMESTAMP() AND (SELECT `enabled` FROM `cron__tasks` `tasks` WHERE `tasks`.`task`=`instances`.`task`)=1
                                ORDER BY `calculated` DESC, `nextrun`
                                LIMIT :innerlimit
                            ) `instances` GROUP BY `task`, `arguments` ORDER BY `calculated` DESC, `nextrun` LIMIT :limit FOR UPDATE SKIP LOCKED
                        ) `toSelect`
                        ON `toUpdate`.`task`=`toSelect`.`task`
                            AND `toUpdate`.`arguments`=`toSelect`.`arguments`
                            AND `toUpdate`.`instance`=`toSelect`.`instance`
                        SET `status`=1, `runby`=:runby, `sse`=:sse;',
                [
                    ':runby' => self::$runby,
                    ':sse' => [SSE::$SSE, 'bool'],
                    ':limit' => [$items, 'int'],
                    #Using this approach seems to be the best solution so far, so that no temporary tables are used (or smaller ones, at least), and it is still relatively performant.
                    #In the worst case scenario tested with 8mil+ records in schedule, the query took 1.5 minutes, which was happening while there are other queries running on the same table at the same time.
                    #On smaller (and more realistic) data sets performance hit is negligible.
                    ':innerlimit' => [$items * 2, 'int']
                ]);
        } catch (\Throwable $exception) {
            #Notify about the end of the stream
            self::log('Failed to queue job', 'CronFail', true, $exception);
        }
        #Get tasks
        try {
            return Query::query(
                'SELECT `task`, `arguments`, `instance` FROM `cron__schedule` WHERE `runby`=:runby ORDER BY '.self::$calculatedPriority.' DESC, `nextrun`;',
                [
                    ':runby' => self::$runby,
                ], return: 'all'
            );
        } catch (\Throwable $exception) {
            #Notify about the end the stream
            self::log('Failed to get queued tasks', 'CronFail', true, $exception);
        }
        return [];
    }
    
    /**
     * Adjust settings
     * @param string $setting Setting to change
     * @param int    $value   Value to set
     *
     * @return $this
     */
    public function setSetting(#[ExpectedValues(self::settings)] string $setting, int $value): self
    {
        #Check setting name
        if (!in_array($setting, self::settings, true)) {
            throw new \InvalidArgumentException('Attempt to set unsupported setting');
        }
        #Handle values lower than 0
        if ($value <= 0) {
            $value = match ($setting) {
                'enabled', 'sseLoop' => 0,
                'logLife' => 30,
                'retry' => 3600,
                'sseRetry' => 10000,
                'maxThreads' => 4,
            };
        }
        if (Query::query('UPDATE `cron__settings` SET `value`=:value WHERE `setting`=:setting;', [
            ':setting' => [$setting, 'string'],
            ':value' => [$value, in_array($setting, ['enabled', 'sseLoop']) ? 'bool' : 'int'],
        ])) {
            switch ($setting) {
                case 'enabled':
                    self::$enabled = (bool)$value;
                    break;
                case 'sseLoop':
                    self::$sseLoop = (bool)$value;
                    break;
                case 'logLife':
                    self::$logLife = $value;
                    break;
                case 'retry':
                    self::$retry = $value;
                    break;
                case 'sseRetry':
                    self::$sseRetry = $value;
                    break;
                case 'maxThreads':
                    self::$maxThreads = $value;
                    break;
            }
            return $this;
        }
        throw new \UnexpectedValueException('Failed to set setting `'.$setting.'` to '.$value);
    }
    
    /**
     * Helper function to get settings
     */
    private function getSettings(): bool
    {
        #Get settings
        try {
            $settings = Query::query('SELECT `setting`, `value` FROM `cron__settings`', return: 'pair');
        } catch (\Throwable) {
            #Implies that DB went away, for example
            self::$dbReady = false;
            return false;
        }
        #Update enabled flag
        if (isset($settings['enabled'])) {
            self::$enabled = (bool)(int)$settings['enabled'];
        }
        #Update SSE loop flag
        if (isset($settings['sseLoop'])) {
            self::$sseLoop = (bool)(int)$settings['sseLoop'];
        }
        #Update retry time
        if (isset($settings['retry'])) {
            $settings['retry'] = (int)$settings['retry'];
            if ($settings['retry'] > 0) {
                self::$retry = $settings['retry'];
            }
        }
        #Update SSE retry time
        if (isset($settings['sseRetry'])) {
            $settings['sseRetry'] = (int)$settings['sseRetry'];
            if ($settings['sseRetry'] > 0) {
                self::$sseRetry = $settings['sseRetry'];
            }
        }
        #Update maximum number of threads
        if (isset($settings['maxThreads'])) {
            $settings['maxThreads'] = (int)$settings['maxThreads'];
            if ($settings['maxThreads'] > 0) {
                self::$maxThreads = $settings['maxThreads'];
            }
        }
        #Update maximum life of an error
        if (isset($settings['logLife'])) {
            $settings['logLife'] = (int)$settings['logLife'];
            if ($settings['logLife'] > 0) {
                self::$logLife = $settings['logLife'];
            }
        }
        return true;
    }
    
    /**
     * Function to reschedule hanged jobs
     *
     * @return bool
     * @throws \JsonException
     * @throws \DateMalformedStringException
     */
    public function unHang(): bool
    {
        if (self::$dbReady) {
            #Delete tasks that were marked as `For removal` (`status` was set to `3`), which means they failed to be removed initially, but succeeded to be updated.
            $tasks = Query::query('SELECT `task`, `arguments`, `instance` FROM `cron__schedule` as `a` WHERE `status` = 3;', return: 'all');
            foreach ($tasks as $task) {
                new TaskInstance($task['task'], $task['arguments'], $task['instance'])->delete();
            }
            $tasks = Query::query('SELECT `task`, `arguments`, `instance`, `frequency` FROM `cron__schedule` as `a` WHERE `runby` IS NOT NULL AND CURRENT_TIMESTAMP()>DATE_ADD(IF(`lastrun` IS NOT NULL, `lastrun`, `nextrun`), INTERVAL (SELECT `maxTime` FROM `cron__tasks` WHERE `cron__tasks`.`task`=`a`.`task`) SECOND);', return: 'all');
            foreach ($tasks as $task) {
                #If this was a one-time task, schedule it for right now, to avoid delaying it for double the time.
                new TaskInstance($task['task'], $task['arguments'], $task['instance'])->reSchedule(false);
            }
        } else {
            return false;
        }
        return true;
    }
    
    /**
     * Function to clean up log
     * @return bool
     */
    public function logPurge(): bool
    {
        if (self::$dbReady) {
            try {
                return Query::query('DELETE FROM `cron__log` WHERE `time` <= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL :logLife DAY);', [
                    ':logLife' => [self::$logLife, 'int'],
                ]);
            } catch (\Throwable) {
                return false;
            }
        } else {
            return false;
        }
    }
    
    /**
     * Install the necessary tables
     * @return bool|string
     */
    public function install(): bool|string
    {
        if (!class_exists(Manage::class)) {
            throw new \RuntimeException('Cron requires `\Simbiat\Database\Manage` class to be automatically installed or updated.');
        }
        #Check if the settings table exists
        if (Manage::checkTable('cron__settings') === 1) {
            #Assume that we have installed the database, try to get the version
            $version = Query::query('SELECT `value` FROM `cron__settings` WHERE `setting`=\'version\'', return: 'value');
            #If an empty installer script was run before 2.1.2, we need to determine what version we have based on other things
            if (empty($version)) {
                #If errors' table does not exist, and the log table does - we are on version 2.0.0
                if (Manage::checkTable('cron__errors') === 0 && Manage::checkTable('cron__log') === 1) {
                    $version = '2.0.0';
                    #If one of the schedule columns is datetime, it's 1.5.0
                } elseif (Manage::getColumnType('cron__schedule', 'registered') === 'datetime') {
                    $version = '1.5.0';
                    #If `maxTime` column is present in `tasks` table - 1.3.0
                } elseif (Manage::checkColumn('cron__tasks', 'maxTime')) {
                    $version = '1.3.0';
                    #If `maxTime` column is present in `tasks` table - 1.2.0
                } elseif (Manage::checkColumn('cron__schedule', 'sse')) {
                    $version = '1.2.0';
                    #If one of the settings has the name `errorLife` (and not `errorlife`) - 1.1.14
                } elseif (Query::query('SELECT `setting` FROM `cron__settings` WHERE `setting`=\'errorLife\'', return: 'value') === 'errorLife') {
                    $version = '1.1.14';
                    #If the `arguments` column is not nullable - 1.1.12
                } elseif (!Manage::isNullable('cron__schedule', 'arguments')) {
                    $version = '1.1.12';
                    #If `errors_to_arguments` Foreign Key exists in `errors` table - 1.1.8
                } elseif (Manage::checkFK('cron__errors', 'errors_to_arguments')) {
                    $version = '1.1.8';
                    #It's 1.1.7 if the old column description is used
                } elseif (Manage::getColumnDescription('cron__schedule', 'arguments') === 'Optional task arguments') {
                    $version = '1.1.7';
                    #If the `maxthreads` setting exists - it's 1.1.0
                } elseif (Query::query('SELECT `setting` FROM `cron__settings` WHERE `setting`=\'maxthreads\'', return: 'value') === 'maxthreads') {
                    $version = '1.1.0';
                    #Otherwise - version 1.0.0
                } else {
                    $version = '1.0.0';
                }
            }
        } else {
            $version = '0.0.0';
        }
        #Get SQL from all files. Sorting is required since we need a specific order of execution.
        /** @noinspection LowPerformingFilesystemOperationsInspection */
        $sqlFiles = glob(__DIR__.'/installer/*.sql');
        $sql = '';
        foreach ($sqlFiles as $file) {
            #Compare version and take only newer ones
            if (version_compare(basename($file, '.sql'), $version, 'gt')) {
                #Get contents from the SQL file
                $sql .= file_get_contents($file);
            }
        }
        #If empty - we are up to date
        if (empty($sql)) {
            return true;
        }
        #Split file content into queries
        $sql = Query::stringToQueries($sql);
        try {
            return Query::query($sql);
        } catch (\Throwable $e) {
            return $e->getMessage()."\r\n".$e->getTraceAsString();
        }
    }
    
    /**
     * Function to end SSE stream and rethrow an error, if it was provided
     *
     * @param string                          $message   SSE message
     * @param string                          $event     SSE type
     * @param bool                            $endStream Flag to indicate whether we end the stream
     * @param \Throwable|null                 $error     Error object
     * @param \Simbiat\Cron\TaskInstance|null $task      TaskInstance object
     *
     * @return void
     */
    public static function log(string $message, string $event, bool $endStream = false, ?\Throwable $error = null, ?TaskInstance $task = null): void
    {
        #In case log is called from the outside of Agent, attempt to use the current task instance in Agent, if available (set by TaskInstance's `run` method)
        $currentTask = $task ?? self::$currentTask;
        if ($currentTask === null && !in_array($event, self::eventsNoInstance, true)) {
            #Something is trying to use Cron log to write a custom message and does not have associated TaskInstance with it, so probably was called outside Cron classes.
            #We do not want to flood DB with unsupported logs, and for SSE a separate function can be used
            return;
        }
        if (self::$dbReady) {
            $skipInsert = false;
            #If $task was passed, use its value for runBy
            $runBy = $currentTask?->runby ?? self::$runby;
            #To reduce amount of NoThreads, Empty and Disabled events in the DB log, we check if the latest event is the same we want to write
            if (in_array($event, ['CronDisabled', 'CronEmpty', 'CronNoThreads'])) {
                #Reset runby value to null, since these entries can belong to multiple threads, and we don't really care about which one was the last one
                $runBy = null;
                #Get last event time and type
                $lastEvent = Query::query('SELECT `time`, `type` FROM `cron__log` ORDER BY `time` DESC LIMIT 1', return: 'row');
                #Checking for empty, in case there are no logs in the table
                if (!empty($lastEvent['type']) && $lastEvent['type'] === $event) {
                    #Update the message of last event with current time
                    Query::query(
                        'UPDATE `cron__log` SET `message`=:message WHERE `time`=:time AND `type`=:type;',
                        [
                            ':type' => $event,
                            ':time' => [$lastEvent['time'], 'datetime'],
                            ':message' => [$message.' (last check at '.SandClock::format(0, 'c').')', 'string'],
                        ]
                    );
                    $skipInsert = true;
                }
            }
            #Insert log entry only if we did not update last log on previous check
            if (!$skipInsert) {
                Query::query(
                    'INSERT INTO `cron__log` (`type`, `runby`, `sse`, `task`, `arguments`, `instance`, `message`) VALUES (:type,:runby,:sse,:task, :arguments, :instance, :message);',
                    [
                        ':type' => $event,
                        ':runby' => [empty($runBy) ? null : $runBy, empty($runBy) ? 'null' : 'string'],
                        ':sse' => [SSE::$SSE, 'bool'],
                        ':task' => [$currentTask?->taskName, $currentTask === null ? 'null' : 'string'],
                        ':arguments' => [$currentTask?->arguments, $currentTask === null ? 'null' : 'string'],
                        ':instance' => [$currentTask?->instance, $currentTask === null ? 'null' : 'int'],
                        ':message' => [$message.($error !== null ? "\r\n".$error->getMessage()."\r\n".$error->getTraceAsString() : ''), 'string'],
                    ]
                );
            }
        }
        if (SSE::$SSE) {
            SSE::send($message, $event, ((($endStream || $error !== null)) ? 0 : self::$sseRetry));
        }
        if ($endStream) {
            if (SSE::$SSE) {
                SSE::close();
            }
            if ($error !== null) {
                throw new \RuntimeException($message, previous: $error);
            }
            exit(0);
        }
    }
}