<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use JetBrains\PhpStorm\ExpectedValues;
use Simbiat\Database\Controller;
use Simbiat\Database\Pool;
use Simbiat\http\SSE;

use function in_array;

/**
 * Task scheduler, that utilizes MySQL/MariaDB database to store tasks and their schedule.
 */
class Agent
{
    /**
     * Database prefix for Cron classes
     * @var string
     */
    public const string dbPrefix = 'cron__';
    /**
     * Flag to indicate that we are ready to work with DB
     * @var bool
     */
    public static bool $dbReady = false;
    /**
     * Cached database controller for performance
     * @var Controller|null
     */
    public static ?Controller $dbController = NULL;
    /**
     * Flag to indicate, whether Cron is enabled
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
     * Flag to indicate, whether SSE is looped or not
     * @var bool
     */
    private static bool $sseLoop = false;
    /**
     * Number of milliseconds for connection retry for SSE. Will also be used to determine how long should the loop sleep if no threads or jobs, but will be treated as number of seconds divided by 20. Default is `10000` (or roughly 8 minutes for empty cycles).
     * @var int
     */
    private static int $sseRetry = 10000;
    /**
     * Maximum threads
     * @var int
     */
    private static int $maxThreads = 4;
    /**
     * CLI mode flag
     * @var bool
     */
    private static bool $CLI = false;
    /**
     * Supported settings
     * @var array
     */
    private const array settings = ['enabled', 'logLife', 'retry', 'sseLoop', 'sseRetry', 'maxThreads'];
    /**
     * ORDER BY clause for tasks
     * @var string
     */
    private static string $sqlOrderBy = 'ORDER BY IF(`frequency`=0, IF(TIMESTAMPDIFF(day, `nextrun`, CURRENT_TIMESTAMP(6))>0, TIMESTAMPDIFF(day, `nextrun`, CURRENT_TIMESTAMP(6)), 0), CEIL(IF(TIMESTAMPDIFF(second, `nextrun`, CURRENT_TIMESTAMP(6))/`frequency`>1, TIMESTAMPDIFF(second, `nextrun`, CURRENT_TIMESTAMP(6))/`frequency`, 0)))+`priority` DESC, `nextrun`';
    /**
     * Random ID
     * @var string
     */
    private static string $runby = '';
    /**
     * List of allowed SSE statuses
     * @var array
     */
    private const array sseStatuses = ['CronStart', 'CronFail', 'CronTaskSkip', 'CronTaskStart', 'CronTaskEnd', 'CronTaskFail', 'CronEmpty', 'CronNoThreads', 'CronEnd', 'Reschedule', 'RescheduleFail', 'TaskToSystem', 'TaskToSystemFail', 'InstanceDelete', 'InstanceDeleteFail', 'InstanceAdd', 'InstanceAddFail', 'InstanceToSystem', 'InstanceToSystemFail', 'TaskAdd', 'TaskAddFail', 'TaskDelete', 'TaskDeleteFail'];
    
    /**
     * Class constructor
     * @throws \Exception
     */
    public function __construct()
    {
        #Check if we are in CLI
        if (preg_match('/^cli(-server)?$/i', PHP_SAPI) === 1) {
            self::$CLI = true;
        }
        #Check that database connection is established
        if (self::$dbReady === false) {
            #Establish, if possible
            $pool = (new Pool());
            if ($pool::$activeConnection !== NULL) {
                self::$dbReady = true;
                #Cache controller
                self::$dbController = (new Controller());
                $this->getSettings();
            }
        }
    }
    
    /**
     * Process Cron items
     *
     * @param int $items Number of items to process
     *
     * @return bool
     * @throws \JsonException
     */
    public function process(int $items = 1): bool
    {
        #Start stream if not in CLI
        if (self::$CLI === false) {
            SSE::open();
        }
        #Generate random ID
        try {
            self::$runby = bin2hex(random_bytes(15));
        } catch (\Throwable $exception) {
            self::log('Failed to generate random ID', 'CronFail', true, $exception);
            return false;
        }
        self::log('Cron processing started', 'CronStart');
        #Regular maintenance
        if (self::$dbReady) {
            #Reschedule hanged jobs
            $this->unHang();
            #Clean old logs
            $this->logPurge();
        } else {
            #Notify of end of stream
            self::log('Cron database not available', 'CronFail', true);
            return false;
        }
        #Check if cron is enabled and process only if it is
        if (self::$enabled) {
            #Sanitize number of items
            if ($items < 1) {
                $items = 1;
            }
            do {
                if ($this->getSettings() === false) {
                    self::log('Failed to get CRON settings', 'CronFail', true);
                    return false;
                }
                #Check if enough threads are available
                try {
                    if (self::$dbController->count('SELECT COUNT(DISTINCT(`runby`)) as `count` FROM `'.self::dbPrefix.'schedule` WHERE `runby` IS NOT NULL;') < self::$maxThreads === false) {
                        self::log('Cron threads are exhausted', self::$CLI ? 'CronEnd' : 'CronNoThreads');
                        if (self::$CLI) {
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
                    if (self::$CLI === false) {
                        #Sleep for a bit
                        sleep(self::$sseRetry / 20);
                    }
                } else {
                    $totalTasks = \count($tasks);
                    foreach ($tasks as $number => $task) {
                        $this->runTask($task, $number + 1, $totalTasks);
                    }
                }
                #Additionally reschedule hanged jobs if we're in SSE
                if (self::$CLI === false && self::$sseLoop === true) {
                    $this->unHang();
                }
            } while (self::$enabled === true && self::$CLI === false && self::$sseLoop === true && connection_status() === 0);
            #Notify of end of stream
            self::log('Cron processing finished', 'CronEnd', true);
            return true;
        }
        #Notify of end of stream
        self::log('Cron processing is disabled', 'CronFail', true);
        return false;
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
            self::log($number.'/'.$totalTasks.' '.(empty($task['message']) ? $task['task'].' starting' : $task['message']), 'CronTaskStart', task: $taskInstance);
            #Attemp to run
            $result = $taskInstance->run();
        } catch (\Throwable $exception) {
            self::log('Failed to run task `'.$task['task'].'` ('.$number.'/'.$totalTasks.')', 'CronTaskFail', false, $exception, ($taskInstance ?? null));
            return;
        }
        #Notify of the task finishing
        if ($result) {
            self::log($number.'/'.$totalTasks.' '.$task['task'].' finished'.($taskInstance->frequency === 0 ? ' and deleted' : ''), 'CronTaskEnd', task: $taskInstance);
        } else {
            self::log($number.'/'.$totalTasks.' '.$task['task'].' failed', 'CronTaskFail', task: $taskInstance);
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
        if (empty(self::$runby)) {
            throw new \UnexpectedValueException('Empty `runby` ID');
        }
        try {
            self::$dbController->query('UPDATE `'.self::dbPrefix.'schedule` AS `toUpdate`
                        INNER JOIN
                        (
                            SELECT `task`, `arguments`, `instance` FROM `'.self::dbPrefix.'schedule`
                            WHERE `runby` IS NULL AND `nextrun`<=CURRENT_TIMESTAMP() GROUP BY `task`, `arguments` '.self::$sqlOrderBy.'
                            LIMIT :limit
                        ) `toSelect`
                        ON `toUpdate`.`task`=`toSelect`.`task`
                            AND `toUpdate`.`arguments`=`toSelect`.`arguments`
                            AND `toUpdate`.`instance`=`toSelect`.`instance`
                        SET `status`=1, `runby`=:runby, `sse`=:sse, `lastrun`=CURRENT_TIMESTAMP();',
                [
                    ':runby' => self::$runby,
                    ':sse' => [!self::$CLI, 'bool'],
                    ':limit' => [$items, 'int']
                ]);
        } catch (\Throwable $exception) {
            #Notify of end of stream
            self::log('Failed to queue job', 'CronFail', true, $exception);
        }
        #Get tasks
        try {
            return self::$dbController->selectAll(
                'SELECT `task`, `arguments`, `instance` FROM `'.self::dbPrefix.'schedule` WHERE `runby`=:runby '.self::$sqlOrderBy.';',
                [
                    ':runby' => self::$runby,
                ]
            );
        } catch (\Throwable $exception) {
            #Notify of end of stream
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
        if (self::$dbController->query('UPDATE `'.self::dbPrefix.'settings` SET `value`=:value WHERE `setting`=:setting;', [
                ':setting' => [$setting, 'string'],
                ':value' => [$value, in_array($setting, ['enabled', 'sseLoop']) ? 'bool' : 'int'],
            ]) === true) {
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
            $settings = self::$dbController->selectPair('SELECT `setting`, `value` FROM `'.self::dbPrefix.'settings`');
        } catch (\Throwable) {
            #Implies, that DB went away, for example
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
                self::$retry = $settings['sseRetry'];
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
     */
    public function unHang(): bool
    {
        if (self::$dbReady) {
            $tasks = self::$dbController->selectAll('SELECT `task`, `arguments`, `instance`, `frequency` FROM `'.self::dbPrefix.'schedule` as `a` WHERE `runby` IS NOT NULL AND CURRENT_TIMESTAMP()>DATE_ADD(IF(`lastrun` IS NOT NULL, `lastrun`, `nextrun`), INTERVAL (SELECT `maxTime` FROM `'.self::dbPrefix.'tasks` WHERE `'.self::dbPrefix.'tasks`.`task`=`a`.`task`) SECOND);');
            foreach ($tasks as $task) {
                #If this was a one-time task, schedule it for right now, to avoid delaying it for double the time
                (new TaskInstance($task['task'], $task['arguments'], $task['instance']))->reSchedule(false, ($task['frequency'] === 0 ? time() : null));
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
                return self::$dbController->query('DELETE FROM `'.self::dbPrefix.'log` WHERE `time` <= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL :logLife DAY);', [
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
        #Check if settings table exists
        if (self::$dbController->checkTable(self::dbPrefix.'settings') === 1) {
            #Assume that we have installed the database, try to get version
            $version = self::$dbController->selectValue('SELECT `value` FROM `'.self::dbPrefix.'settings` WHERE `setting`=\'version\'');
            #If empty installer script was ran before 2.1.2, so need to determine what version we have based on other things
            if (empty($version)) {
                #If errors table does not exist, and log table does - we are on version 2.0.0
                if (self::$dbController->checkTable(self::dbPrefix.'errors') === 0 && self::$dbController->checkTable(self::dbPrefix.'log') === 1) {
                    $version = '2.0.0';
                    #If one of schedule columns is datetime, it's 1.5.0
                } elseif (self::$dbController->getColumnType(self::dbPrefix.'schedule', 'registered') === 'datetime') {
                    $version = '1.5.0';
                    #If `maxTime` column is present in `tasks` table - 1.3.0
                } elseif (self::$dbController->checkColumn(self::dbPrefix.'tasks', 'maxTime')) {
                    $version = '1.3.0';
                    #If `maxTime` column is present in `tasks` table - 1.2.0
                } elseif (self::$dbController->checkColumn(self::dbPrefix.'schedule', 'sse')) {
                    $version = '1.2.0';
                    #If one of the settings has name `errorLife` (and not `errorlife`) - 1.1.14
                } elseif (self::$dbController->selectValue('SELECT `setting` FROM `'.self::dbPrefix.'settings` WHERE `setting`=\'errorLife\'') === 'errorLife') {
                    $version = '1.1.14';
                    #If `arguments` column is not nullable - 1.1.12
                } elseif (self::$dbController->isNullable(self::dbPrefix.'schedule', 'arguments') === false) {
                    $version = '1.1.12';
                    #If `errors_to_arguments` Foreign Key exists in `errors` table - 1.1.8
                } elseif (self::$dbController->checkFK(self::dbPrefix.'errors', 'errors_to_arguments')) {
                    $version = '1.1.8';
                    #It's 1.1.7 if old column description is used
                } elseif (self::$dbController->getColumnDescription(self::dbPrefix.'schedule', 'arguments') === 'Optional task arguments') {
                    $version = '1.1.7';
                    #If `maxthreads` setting exists - it's 1.1.0
                } elseif (self::$dbController->selectValue('SELECT `setting` FROM `'.self::dbPrefix.'settings` WHERE `setting`=\'maxthreads\'') === 'maxthreads') {
                    $version = '1.1.0';
                    #Otherwise - version 1.0.0
                } else {
                    $version = '1.0.0';
                }
            }
        } else {
            $version = '0.0.0';
        }
        #Get SQL from all files
        $sqlFiles = glob(__DIR__.'/installer/*.sql');
        $sql = '';
        foreach ($sqlFiles as $file) {
            #Compare version and take only newer ones
            if (version_compare(basename($file, '.sql'), $version, 'gt')) {
                #Get contents from SQL file
                $sql .= file_get_contents($file);
            }
        }
        #If empty - we are up-to-date
        if (empty($sql)) {
            return true;
        }
        #Split file content into queries
        $sql = self::$dbController->stringToQueries($sql);
        try {
            return self::$dbController->query($sql);
        } catch (\Exception $e) {
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
    public static function log(string $message, #[ExpectedValues(self::sseStatuses)] string $event = 'CronFail', bool $endStream = false, ?\Throwable $error = null, ?TaskInstance $task = null): void
    {
        if (!in_array($event, self::sseStatuses, true)) {
            $event = 'CronFail';
        }
        if (self::$dbReady) {
            #Insert error
            self::$dbController->query(
                'INSERT INTO `'.self::dbPrefix.'log` (`type`, `runby`, `sse`, `task`, `arguments`, `instance`, `message`) VALUES (:type,:runby,:sse,:task, :arguments, :instance, :message);',
                [
                    ':type' => $event,
                    ':runby' => [empty(self::$runby) ? null : self::$runby, empty(self::$runby) ? 'null' : 'string'],
                    ':sse' => [!self::$CLI, 'bool'],
                    ':task' => [$task?->taskName, $task === null ? 'null' : 'string'],
                    ':arguments' => [$task?->arguments, $task === null ? 'null' : 'string'],
                    ':instance' => [$task?->instance, $task === null ? 'null' : 'int'],
                    ':message' => [$message.($error !== null ? "\r\n".$error->getMessage()."\r\n".$error->getTraceAsString() : ''), 'string'],
                ]
            );
        }
        if (self::$CLI === false) {
            SSE::send($message, $event, ((($endStream || $error !== null)) ? 0 : self::$sseRetry));
        }
        if ($endStream) {
            if (self::$CLI === false) {
                SSE::close();
            }
            if ($error !== null) {
                throw new \RuntimeException($message, previous: $error);
            }
            exit;
        }
    }
}