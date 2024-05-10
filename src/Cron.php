<?php
declare(strict_types = 1);

namespace Simbiat;

use Simbiat\Database\Controller;
use Simbiat\Database\Pool;

use function in_array, is_array, is_string;

/**
 * Task scheduler, that utilizes MySQL/MariaDB database to store tasks and their schedule.
 */
class Cron
{
    public const string dbPrefix = 'cron__';
    #Flag to indicate that we are ready to work with DB
    public static bool $dbReady = false;
    #Cached database controller for performance
    private static ?Controller $dbController = NULL;
    #Flag to indicate, that we are maintenance
    public static bool $enabled = false;
    #Retry time
    public static int $retry = 3600;
    #Errors life
    public static int $errorLife = 30;
    #SSE settings
    public static bool $sseLoop = false;
    public static int $sseRetry = 10000;
    #Maximum threads
    public static int $maxThreads = 4;
    #CLI mode flag
    private static bool $CLI = false;
    #Supported settings
    public array $settings = ['enabled', 'errorLife', 'maxTime', 'retry', 'sseLoop', 'sseRetry', 'maxThreads'];
    #Logic for next time calculation
    private string $sqlNextRun = 'TIMESTAMPADD(SECOND, IF(`frequency` > 0, IF(CEIL(TIMESTAMPDIFF(SECOND, `nextrun`, CURRENT_TIMESTAMP())/`frequency`) > 0, CEIL(TIMESTAMPDIFF(SECOND, `nextrun`, CURRENT_TIMESTAMP())/`frequency`), 1)*`frequency`, IF(CEIL(TIMESTAMPDIFF(SECOND, `nextrun`, CURRENT_TIMESTAMP())/:time) > 0, CEIL(TIMESTAMPDIFF(SECOND, `nextrun`, CURRENT_TIMESTAMP())/:time), 1)*:time), `nextrun`)';
    
    /**
     * Class constructor
     * @throws \Exception
     */
    public function __construct(bool $installed = true)
    {
        #Check if we are in CLI
        if (preg_match('/^cli(-server)?$/i', PHP_SAPI) === 1) {
            self::$CLI = true;
        }
        #Check that database connection is established
        if (self::$dbReady === false) {
            $pool = (new Pool());
            if ($pool::$activeConnection !== NULL) {
                self::$dbReady = true;
                #Cache controller
                self::$dbController = (new Controller());
                #Install tables
                if ($installed === false) {
                    $install = $this->install();
                    if ($install !== true) {
                        throw new \UnexpectedValueException('Failed to install tables for CRON');
                    }
                }
                $this->getSettings();
            }
        }
    }
    
    /**
     * Process Cron items
     * @param int $items Number of items to process
     *
     * @return bool
     */
    public function process(int $items = 1): bool
    {
        #Start stream if not in CLI
        if (self::$CLI === false) {
            ignore_user_abort(true);
            if (!headers_sent()) {
                header('Content-Type: text/event-stream');
                header('Transfer-Encoding: chunked');
                #Forbid caching, since stream is not supposed to be cached
                header('Cache-Control: no-cache');
            }
            $this->streamEcho('Cron processing started', 'CronStart');
        }
        #Regular maintenance
        if (self::$dbReady) {
            #Reschedule hanged jobs
            $this->unHang();
            #Clean old errors
            $this->errorPurge();
        } else {
            #Notify of end of stream
            if (self::$CLI === false) {
                $this->streamEcho('Cron database not available', 'CronFail');
                if (!headers_sent()) {
                    header('Connection: close');
                }
            }
            return false;
        }
        #Check if cron is enabled and process only if it is
        if (self::$enabled) {
            try {
                #Sanitize number of items
                if ($items < 1) {
                    $items = 1;
                }
                #Generate random ID
                $randomId = bin2hex(random_bytes(15));
                do {
                    #Check if enough threads are available
                    if ($this->threadAvailable() === true) {
                        #Queue tasks for this random ID
                        if (self::$dbController->query(
                                'UPDATE `'.self::dbPrefix.'schedule` SET `status`=1, `runby`=:runby, `sse`=:sse, `lastrun`=CURRENT_TIMESTAMP() WHERE `status`<>2 AND `runby` IS NULL AND `nextrun`<=CURRENT_TIMESTAMP() ORDER BY `priority` DESC, `nextrun` LIMIT :limit;',
                                [
                                    ':runby' => $randomId,
                                    ':sse' => [!self::$CLI, 'bool'],
                                    ':limit' => [$items, 'int']
                                ]
                            ) !== true) {
                            #Notify of end of stream
                            if (self::$CLI === false) {
                                $this->streamEcho('Cron processing failed', 'CronFail');
                                if (!headers_sent()) {
                                    header('Connection: close');
                                }
                            }
                            return false;
                        }
                        #Get tasks
                        $tasks = self::$dbController->selectAll(
                            'SELECT `'.self::dbPrefix.'schedule`.`task`, `arguments`, `frequency`, `dayofmonth`, `dayofweek`, `message`, `nextrun` FROM `'.self::dbPrefix.'schedule` INNER JOIN `'.self::dbPrefix.'tasks` ON `'.self::dbPrefix.'schedule`.`task`=`'.self::dbPrefix.'tasks`.`task` WHERE `runby`=:runby ORDER BY IF(`frequency`=0, IF(DATEDIFF(CURRENT_TIMESTAMP, `nextrun`)>0, DATEDIFF(CURRENT_TIMESTAMP, `nextrun`), 0), CEIL(IF(TIMEDIFF(CURRENT_TIMESTAMP, `nextrun`)/`frequency`>1, TIMEDIFF(CURRENT_TIMESTAMP, `nextrun`)/`frequency`, 0)))+`priority` DESC, `nextrun`;',
                            [
                                ':runby' => $randomId,
                            ]
                        );
                        if (!empty($tasks)) {
                            foreach ($tasks as $task) {
                                #Check for day restrictions
                                if ((!empty($task['dayofmonth']) || !empty($task['dayofweek'])) && ($this->dayOfCheck($task['dayofmonth']) === false || $this->dayOfCheck($task['dayofweek'], false))) {
                                    #Reschedule
                                    $this->reSchedule($task['task'], $task['arguments'], $task['frequency'], false);
                                    #Notify of the task skipping
                                    if (self::$CLI === false) {
                                        $this->streamEcho($task['task'].' skipped due to day restrictions', 'CronTaskSkip');
                                    }
                                    #Skip
                                    continue;
                                }
                                #Notify of the task starting
                                if (self::$CLI === false) {
                                    $this->streamEcho((empty($task['message']) ? $task['task'].' starting' : $task['message']), 'CronTaskStart');
                                }
                                $result = $this->runTask($task['task'], $task['arguments']);
                                #Notify of the task finishing
                                if (self::$CLI === false) {
                                    if ($result) {
                                        $this->streamEcho($task['task'].' finished', 'CronTaskEnd');
                                    } else {
                                        $this->streamEcho($task['task'].' failed', 'CronTaskFail');
                                    }
                                }
                            }
                        } elseif (self::$CLI === false) {
                            $this->streamEcho('Cron list is empty', 'CronEmpty');
                            #Sleep for a bit
                            sleep(self::$sseRetry / 20);
                        }
                        #Additionally reschedule hanged jobs if we're in SSE
                        if (self::$CLI === false && self::$sseLoop === true) {
                            $this->unHang();
                        }
                    } elseif (self::$CLI === false) {
                        $this->streamEcho('Cron threads are exhausted', 'CronNoThreads');
                        #Sleep for a bit
                        sleep(self::$sseRetry / 20);
                    }
                } while ($this->getSettings() === true && self::$enabled === true && self::$CLI === false && self::$sseLoop === true && connection_status() === 0);
                #Notify of end of stream
                if (self::$CLI === false) {
                    $this->streamEcho('Cron processing finished', 'CronEnd');
                    if (!headers_sent()) {
                        header('Connection: close');
                    }
                }
                return true;
            } catch (\Exception $e) {
                #Notify of end of stream
                if (self::$CLI === false) {
                    $this->streamEcho('Cron processing failed', 'CronEnd');
                    if (!headers_sent()) {
                        header('Connection: close');
                    }
                }
                #Re-throw the error
                throw new \RuntimeException('General cron cycle failure', previous: $e);
            }
        } else {
            #Notify of end of stream
            if (self::$CLI === false) {
                $this->streamEcho('Cron processing is disabled', 'CronFail');
                if (!headers_sent()) {
                    header('Connection: close');
                }
            }
            return false;
        }
    }
    
    /**
     * Run the function based on the task details
     *
     * @param string            $taskName  Task name
     * @param array|string|null $arguments Arguments
     *
     * @return bool
     * @throws \JsonException|\Exception
     */
    public function runTask(string $taskName, null|array|string $arguments = NULL): bool
    {
        if (self::$enabled) {
            try {
                #Sanitize arguments
                $arguments = $this->sanitize($arguments);
                #Get full details
                $task = self::$dbController->selectRow('SELECT * FROM `'.self::dbPrefix.'schedule` INNER JOIN `'.self::dbPrefix.'tasks` ON `'.self::dbPrefix.'schedule`.`task`=`'.self::dbPrefix.'tasks`.`task` WHERE `status`<>2 AND `'.self::dbPrefix.'schedule`.`task`=:task AND `arguments`=:arguments', [
                    ':task' => [$taskName, 'string'],
                    ':arguments' => [$arguments, 'string']
                ]);
                if (empty($task)) {
                    #Assume that it was a one-time job, that has already been run
                    return true;
                }
                if (empty($task['function'])) {
                    #Register error
                    $this->error('Task has no assigned function', $task['task'], $task['arguments']);
                    #Reschedule
                    $this->reSchedule($task['task'], $task['arguments'], $task['frequency'], false);
                    return false;
                }
                #Set time limit for the task
                set_time_limit((int)$task['maxTime']);
                #Update last run
                self::$dbController->query('UPDATE `'.self::dbPrefix.'schedule` SET `status`=2, `lastrun` = CURRENT_TIMESTAMP() WHERE `task`=:task AND `arguments`=:arguments;', [
                    ':task' => [$task['task'], 'string'],
                    ':arguments' => [(string)$task['arguments'], 'string'],
                ]);
                #Check if object is required
                if (!empty($task['object'])) {
                    #Check if parameters for the object are set
                    if (!empty($task['parameters']) && json_validate($task['parameters'])) {
                        $task['parameters'] = json_decode($task['parameters'], flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY);
                        #Check if extra methods are set
                        if (!empty($task['parameters']['extramethods'])) {
                            #Separate extra methods
                            $extramethods = $task['parameters']['extramethods'];
                            #Remove them from original
                            unset($task['parameters']['extramethods']);
                        }
                    } else {
                        $task['parameters'] = NULL;
                    }
                    #Generate object
                    if (empty($task['parameters'])) {
                        $object = (new $task['object']());
                    } else {
                        $object = (new $task['object'](...$task['parameters']));
                    }
                    #Call the extra methods
                    if (!empty($extramethods)) {
                        foreach ($extramethods as $method) {
                            #Check if method value is present, skip the method, if not
                            if (empty($method['method']) || !is_string($method['method'])) {
                                continue;
                            }
                            #Check for arguments for the method
                            if (empty($method['arguments']) || !is_array($method['arguments'])) {
                                #Call without arguments
                                $object = $object->{$method['method']}();
                            } else {
                                #Call with arguments
                                $object = $object->{$method['method']}(...$method['arguments']);
                            }
                        }
                    }
                }
                #Set function
                if (empty($object)) {
                    $function = $task['function'];
                } else {
                    $function = [$object, $task['function']];
                }
                #Check if callable
                if (!\is_callable($function)) {
                    #Register error
                    $this->error('Function is not callable', $task['task'], $task['arguments']);
                    #Reschedule
                    $this->reSchedule($task['task'], $task['arguments'], $task['frequency'], false);
                    return false;
                }
                #Run function
                if (empty($task['arguments'])) {
                    $result = $function();
                } else {
                    if (!json_validate($task['arguments'])) {
                        throw new \InvalidArgumentException('Invalid JSON found in arguments string `'.$task['arguments'].'`.');
                    }
                    #Decode arguments
                    $finalArguments = json_decode($task['arguments'], flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY);
                    $result = \call_user_func_array($function, $finalArguments);
                }
                #Decode allowed returns if any
                if (!empty($task['allowedreturns'])) {
                    if (!json_validate($task['allowedreturns'])) {
                        throw new \InvalidArgumentException('Invalid JSON found in allowed returns string `'.$task['arguments'].'`.');
                    }
                    $task['allowedreturns'] = json_decode($task['allowedreturns'], flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY);
                }
            } catch (\Exception $e) {
                $result = $e->getMessage()."\r\n".$e->getTraceAsString();
            }
            #Validate result
            if ($result !== true) {
                #Check if it's an allowed return value
                if (!empty($task['allowedreturns'])) {
                    if (in_array($result, $task['allowedreturns'], true) === true) {
                        #Override the value
                        $result = true;
                    } else {
                        $this->error('Unexpected return `'.json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION).'`.', $taskName, $arguments);
                        $result = false;
                    }
                } else {
                    #Register error.
                    $this->error('Function call returned `false`.', $taskName, $arguments);
                    $result = false;
                }
            }
            #Reschedule
            $this->reSchedule($taskName, $arguments, (empty($task['frequency']) ? 0 : $task['frequency']), $result);
            #Return
            return $result;
        }
        return false;
    }
    
    /**
     * Adjust settings
     * @param string $setting Setting to change
     * @param int    $value   Value to set
     *
     * @return $this
     */
    public function setSetting(string $setting, int $value): self
    {
        #Check setting name
        if (!in_array($setting, $this->settings, true)) {
            throw new \InvalidArgumentException('Attempt to set unsupported setting');
        }
        #Handle values lower than 0
        if ($value <= 0) {
            $value = match ($setting) {
                'enabled', 'sseLoop' => 0,
                'errorLife' => 30,
                'retry' => 3600,
                'sseRetry' => 10000,
                'maxThreads' => 4,
            };
        }
        #Handle booleans
        if ($value > 1 && in_array($setting, ['enabled', 'sseLoop'])) {
            $value = 1;
        }
        if (self::$dbController->query('UPDATE `'.self::dbPrefix.'settings` SET `value`=:value WHERE `setting`=:setting;', [
                ':setting' => [$setting, 'string'],
                ':value' => [$value, 'int'],
            ]) === true) {
            if (in_array($setting, ['enabled', 'sseLoop'])) {
                self::${$setting} = (bool)$value;
            } else {
                self::${$setting} = $value;
            }
            return $this;
        }
        throw new \UnexpectedValueException('Failed to set setting `'.$setting.'` to '.$value);
    }
    
    /**
     * Schedule or update a task
     * @param string            $task       Task name
     * @param array|string|null $arguments  Arguments
     * @param int|string        $frequency  Frequency of the task
     * @param int               $priority   Priority of the task
     * @param string|null       $message    Message to show in SSE
     * @param array|string|null $dayofmonth Day of month
     * @param array|string|null $dayofweek  Day of week
     * @param int               $time       Time of next run
     *
     * @return bool
     * @throws \JsonException
     */
    public function add(string $task, null|array|string $arguments = null, int|string $frequency = 0, int $priority = 0, ?string $message = NULL, null|array|string $dayofmonth = NULL, null|array|string $dayofweek = NULL, int $time = 0): bool
    {
        if (self::$dbReady) {
            #Sanitize arguments
            $arguments = $this->sanitize($arguments);
            $dayofmonth = $this->sanitize($dayofmonth);
            $dayofweek = $this->sanitize($dayofweek);
            $frequency = (int)$frequency;
            if ($frequency < 0) {
                $frequency = 0;
            }
            if ($priority < 0) {
                $priority = 0;
            } elseif ($priority > 255) {
                $priority = 255;
            }
            if ($time <= 0) {
                $time = time();
            }
            return self::$dbController->query('INSERT INTO `'.self::dbPrefix.'schedule` (`task`, `arguments`, `frequency`, `dayofmonth`, `dayofweek`, `priority`, `message`, `nextrun`) VALUES (:task, :arguments, :frequency, :dayofmonth, :dayofweek, :priority, :message, :nextrun) ON DUPLICATE KEY UPDATE `frequency`=:frequency, `dayofmonth`=:dayofmonth, `dayofweek`=:dayofweek, `nextrun`=IF(:frequency=0, `nextrun`, :nextrun), `priority`=IF(:frequency=0, IF(`priority`>:priority, `priority`, :priority), :priority), `message`=:message, `updated`=CURRENT_TIMESTAMP();', [
                ':task' => [$task, 'string'],
                ':arguments' => [$arguments, 'string'],
                ':frequency' => [$frequency, 'int'],
                ':dayofmonth' => [$dayofmonth, (empty($dayofmonth) ? 'null' : 'string')],
                ':dayofweek' => [$dayofweek, (empty($dayofweek) ? 'null' : 'string')],
                ':priority' => [$priority, 'int'],
                ':message' => [$message, (empty($message) ? 'null' : 'string')],
                ':nextrun' => [$time, 'datetime'],
            ]);
        }
        return false;
    }
    
    /**
     * Remove a task from schedule
     * @param string $task      Task name
     * @param string $arguments Arguments
     *
     * @return bool
     * @throws \JsonException
     */
    public function delete(string $task, string $arguments = ''): bool
    {
        if (self::$dbReady) {
            #Sanitize arguments
            $arguments = $this->sanitize($arguments);
            return self::$dbController->query('DELETE FROM `'.self::dbPrefix.'schedule` WHERE `task`=:task AND `arguments`=:arguments;', [
                ':task' => [$task, 'string'],
                ':arguments' => [$arguments, 'string'],
            ]);
        }
        return false;
    }
    
    /**
     * Add (or update) task type
     * @param string            $task       Task name
     * @param string            $function   Function to run
     * @param string|null       $object     Optional object
     * @param array|string|null $parameters Parameters to set for the object
     * @param array|string|null $returns    Expected (and allowed) return values
     * @param int               $maxTime    Maximum execution time
     * @param string|null       $desc
     *
     * @return bool
     * @throws \JsonException
     */
    public function addTask(string $task, string $function, ?string $object = NULL, null|array|string $parameters = NULL, null|array|string $returns = NULL, int $maxTime = 3600, ?string $desc = NULL): bool
    {
        if (self::$dbReady) {
            #Sanitize parameters and return
            $parameters = $this->sanitize($parameters);
            $returns = $this->sanitize($returns);
            return self::$dbController->query('INSERT INTO `'.self::dbPrefix.'tasks`(`task`, `function`, `object`, `parameters`, `allowedreturns`, `maxTime`, `description`) VALUES (:task, :function, :object, :parameters, :returns, :maxTime, :desc) ON DUPLICATE KEY UPDATE `function`=:function, `object`=:object, `parameters`=:parameters, `allowedreturns`=:returns, `maxTime`=:maxTime, `description`=:desc;', [
                ':task' => [$task, 'string'],
                ':function' => [$function, 'string'],
                ':object' => [$object, 'string'],
                ':parameters' => [$parameters, (empty($parameters) ? 'null' : 'string')],
                ':returns' => [$returns, (empty($returns) ? 'null' : 'string')],
                ':maxTime' => [$maxTime, 'int'],
                ':desc' => [$desc, 'string'],
            ]);
        }
        return false;
    }
    
    /**
     * Delete task type
     * @param string $task Task name
     *
     * @return bool
     */
    public function deleteTask(string $task): bool
    {
        if (self::$dbReady) {
            try {
                return self::$dbController->query('DELETE FROM `'.self::dbPrefix.'tasks` WHERE `task`=:task;', [
                    ':task' => [$task, 'string'],
                ]);
            } catch (\Throwable) {
                return false;
            }
        } else {
            return false;
        }
    }
    
    /**
     * Function to reschedule hanged jobs
     * @return bool
     */
    public function unHang(): bool
    {
        if (self::$dbReady) {
            try {
                return self::$dbController->query('UPDATE `'.self::dbPrefix.'schedule` AS `a` SET `status`=0, `runby`=NULL, `sse`=0, `nextrun`='.$this->sqlNextRun.', `lastrun`=IF(`lastrun` IS NULL, CURRENT_TIMESTAMP(), `lastrun`), `lasterror`=CURRENT_TIMESTAMP() WHERE `status`<>0 AND CURRENT_TIMESTAMP()>DATE_ADD(IF(`lastrun` IS NOT NULL, `lastrun`, `nextrun`), INTERVAL (SELECT `maxTime` FROM `'.self::dbPrefix.'tasks` WHERE `'.self::dbPrefix.'tasks`.`task`=`a`.`task`) SECOND);', [
                    ':time' => [self::$retry, 'int'],
                ]);
            } catch (\Throwable) {
                return false;
            }
        } else {
            return false;
        }
    }
    
    /**
     * Function to clean up errors
     * @return bool
     */
    public function errorPurge(): bool
    {
        if (self::$dbReady) {
            try {
                return self::$dbController->query('DELETE FROM `'.self::dbPrefix.'errors` WHERE `time` <= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL :errorLife DAY);', [
                    ':errorLife' => [self::$errorLife, 'int'],
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
    private function install(): bool|string
    {
        #Get contents from SQL file
        $sql = file_get_contents(__DIR__.'\install.sql');
        #Split file content into queries
        $sql = self::$dbController->stringToQueries($sql);
        try {
            return self::$dbController->query($sql);
        } catch (\Exception $e) {
            return $e->getMessage()."\r\n".$e->getTraceAsString();
        }
    }
    
    /**
     * Reschedule a task (or remove it if it's onetime)
     * @param string     $task      Task name
     * @param string     $arguments Arguments
     * @param int|string $frequency Frequency of the task
     * @param bool       $result    Whether task was successful
     *
     * @return void
     * @throws \JsonException
     */
    private function reSchedule(string $task, string $arguments = '', int|string $frequency = 0, bool $result = true): void
    {
        if (self::$dbReady) {
            #Ensure schedule is INT
            $frequency = (int)$frequency;
            #Check whether this is a successful one-time job
            if ($frequency === 0 && $result === true) {
                #Since this is a one-time task, we can just remove it
                $this->delete($task, $arguments);
            } else {
                #Actually reschedule. One task time task will be rescheduled for the retry time from settings
                self::$dbController->query('UPDATE `'.self::dbPrefix.'schedule` SET `status`=0, `runby`=NULL, `sse`=0, `nextrun`='.$this->sqlNextRun.', `'.($result === true ? 'lastsuccess' : 'lasterror').'`=CURRENT_TIMESTAMP() WHERE `task`=:task AND `arguments`=:arguments;', [
                    ':time' => [self::$retry, 'int'],
                    ':task' => [$task, 'string'],
                    ':arguments' => [$arguments, 'string'],
                ]);
            }
        }
    }
    
    /**
     * Register error
     * @param string $text      Error text
     * @param string $task      Task that failed
     * @param string $arguments Arguments of the failed task
     *
     * @return void
     */
    private function error(string $text, string $task, string $arguments = ''): void
    {
        if (self::$dbReady) {
            #Insert error
            self::$dbController->query(
                'INSERT INTO `'.self::dbPrefix.'errors` (`task`, `arguments`, `text`) VALUES (:task, :arguments, :text) ON DUPLICATE KEY UPDATE `time`=CURRENT_TIMESTAMP(), `text`=:text;',
                [
                    ':task' => [
                        $task, 'string'
                    ],
                    ':arguments' => [$arguments, 'string'],
                    ':text' => [$text, 'string'],
                ]
            );
        }
    }
    
    /**
     * Helper function to sanitize the arguments/parameters into JSON string or empty string
     * @param array|string|null $arguments
     *
     * @return string
     * @throws \JsonException
     */
    private function sanitize(null|array|string $arguments = NULL): string
    {
        #Return NULL if empty
        if (empty($arguments)) {
            return '';
        }
        #Check if string
        if (is_string($arguments)) {
            #Check if JSON
            if (json_validate($arguments)) {
                return $arguments;
            }
            throw new \InvalidArgumentException('Invalid JSON found in arguments string `'.$arguments.'`.');
        }
        #We have an array
        return json_encode($arguments, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
    
    /**
     * Helper function to output event stream data
     * @param string|null $message Message
     * @param string      $event   Event type
     *
     * @return void
     */
    private function streamEcho(?string $message = null, string $event = 'Status'): void
    {
        echo 'retry: '.self::$sseRetry."\n".'id: '.hrtime(true)."\n".(empty($event) ? '' : 'event: '.$event."\n").'data: '.$message."\n\n";
        ob_flush();
        flush();
    }
    
    /**
     * Helper function to validate whether job is allowed to run today
     * @param string|null $values Values
     * @param bool        $month  Whether it's checking for day of month or day of week
     *
     * @return bool
     * @throws \JsonException
     */
    private function dayOfCheck(?string $values = NULL, bool $month = true): bool
    {
        #Set the settings
        if ($month) {
            $format = 'j';
            $maxValue = 31;
        } else {
            $format = 'N';
            $maxValue = 7;
        }
        #Sanitize
        $values = $this->sanitize($values);
        #Actually decode
        if (!empty($values)) {
            $values = json_decode($values, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY);
            #If empty - allow run
            if (!empty($values)) {
                #Filter non-integers, negative integers and too large integers
                $values = array_filter($values, static function ($item) use ($maxValue) {
                    return (\is_int($item) && $item >= 1 && $item <= $maxValue);
                });
                #If empty after filtering or if current day is in array - allow run
                if (!empty($values) && !in_array((int)date($format), $values, true)) {
                    return false;
                }
            }
        }
        return true;
    }
    
    /**
     * Helper function to check number of active threads
     * @throws \Exception
     */
    private function threadAvailable(): bool
    {
        #Get current count
        $current = self::$dbController->count('SELECT COUNT(DISTINCT(`runby`)) as `count` FROM `'.self::dbPrefix.'schedule` WHERE `runby` IS NOT NULL;');
        return $current < self::$maxThreads;
    }
    
    /**
     * Helper function to get settings
     * @throws \Exception
     */
    private function getSettings(): bool
    {
        #Get settings
        try {
            $settings = self::$dbController->selectPair('SELECT `setting`, `value` FROM `'.self::dbPrefix.'settings`');
        } catch (\Exception) {
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
        if (isset($settings['errorLife'])) {
            $settings['errorLife'] = (int)$settings['errorLife'];
            if ($settings['errorLife'] > 0) {
                self::$errorLife = $settings['errorLife'];
            }
        }
        return true;
    }
}
