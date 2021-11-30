<?php
declare(strict_types=1);
namespace Simbiat;

use Simbiat\Database\Controller;
use Simbiat\Database\Pool;

class Cron
{
    const dbPrefix = 'cron__';
    #Flag to indicate that we are ready to work with DB
    public static bool $dbReady = false;
    #Cached database controller for performance
    private static ?Controller $dbController = NULL;
    #Flag to indicate, that we are maintenance
    public static bool $enabled = false;
    #Retry time
    public static int $retry = 3600;
    #Maximum job time
    public static int $maxTime = 3600;
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
    private string $sqlNextRun = 'TIMESTAMPADD(SECOND, IF(`frequency` > 0, IF(CEIL(TIMESTAMPDIFF(SECOND, `nextrun`, UTC_TIMESTAMP())/`frequency`) > 0, CEIL(TIMESTAMPDIFF(SECOND, `nextrun`, UTC_TIMESTAMP())/`frequency`), 1)*`frequency`, IF(CEIL(TIMESTAMPDIFF(SECOND, `nextrun`, UTC_TIMESTAMP())/:time) > 0, CEIL(TIMESTAMPDIFF(SECOND, `nextrun`, UTC_TIMESTAMP())/:time), 1)*:time), `nextrun`)';

    /**
     * @throws \Exception
     */
    public function __construct(bool $installed = true)
    {
        #Check if we are in CLI
        if (preg_match('/^cli(-server)?$/i', php_sapi_name()) === 1) {
            self::$CLI = true;
        }
        #Check that database connection is established
        if (self::$dbReady === false) {
            $pool = (new Pool);
            if ($pool::$activeConnection !== NULL) {
                self::$dbReady = true;
                #Cache controller
                self::$dbController = (new Controller);
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

    #Process the items

    /**
     * @throws \Exception
     */
    public function process(int $items = 1): bool
    {
        #ALlow long runs
        set_time_limit(0);
        #Start stream if not in CLI
        if (self::$CLI === false) {
            ignore_user_abort(true);
            @header('Content-Type: text/event-stream');
            @header('Transfer-Encoding: chunked');
            #Forbid caching, since stream is not supposed to be cached
            @header('Cache-Control: no-cache');
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
                @header('Connection: close');
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
                            'UPDATE `'.self::dbPrefix.'schedule` SET `status`=1, `runby`=:runby, `sse`=:sse, `lastrun`=UTC_TIMESTAMP() WHERE `status`<>2 AND `runby` IS NULL AND `nextrun`<=UTC_TIMESTAMP() ORDER BY `priority` DESC, `nextrun` LIMIT :limit;',
                            [
                                ':runby'=>$randomId,
                                ':sse'=>[!self::$CLI, 'bool'],
                                ':limit'=>[$items, 'int']
                            ]
                            ) !== true) {
                            #Notify of end of stream
                            if (self::$CLI === false) {
                                $this->streamEcho('Cron processing failed', 'CronFail');
                                @header('Connection: close');
                            }
                            return false;
                        }
                        #Get tasks
                        $tasks = self::$dbController->SelectAll(
                            'SELECT `'.self::dbPrefix.'schedule`.`task`, `arguments`, `frequency`, `dayofmonth`, `dayofweek`, `message`, `nextrun` FROM `'.self::dbPrefix.'schedule` INNER JOIN `'.self::dbPrefix.'tasks` ON `'.self::dbPrefix.'schedule`.`task`=`'.self::dbPrefix.'tasks`.`task` WHERE `runby`=:runby ORDER BY `priority` DESC, `nextrun`;',
                            [
                                ':runby'=>$randomId,
                            ]
                        );
                        if (is_array($tasks) && !empty($tasks)) {
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
                        } else {
                            if (self::$CLI === false) {
                                $this->streamEcho('Cron list is empty', 'CronEmpty');
                                #Sleep for a bit
                                sleep(self::$sseRetry/20);
                            }
                        }
                        #Additionally reschedule hanged jobs if we're in SSE
                        if (self::$CLI === false && self::$sseLoop === true) {
                            $this->unHang();
                        }
                    } else {
                        if (self::$CLI === false) {
                            $this->streamEcho('Cron threads are exhausted', 'CronNoThreads');
                            #Sleep for a bit
                            sleep(self::$sseRetry/20);
                        }
                    }
                } while ($this->getSettings() === true && self::$enabled === true && self::$CLI === false && self::$sseLoop === true && connection_status() === 0);
                #Notify of end of stream
                if (self::$CLI === false) {
                    $this->streamEcho('Cron processing finished', 'CronEnd');
                    @header('Connection: close');
                }
                return true;
            } catch(\Exception $e) {
                #Attempt to register error
                error_log('General cron cycle failure:'."\r\n".$e->getMessage()."\r\n".$e->getTraceAsString());
                #Notify of end of stream
                if (self::$CLI === false) {
                    $this->streamEcho('Cron processing failed', 'CronEnd');
                    @header('Connection: close');
                }
                return false;
            }
        } else {
            #Notify of end of stream
            if (self::$CLI === false) {
                $this->streamEcho('Cron processing is disabled', 'CronFail');
                @header('Connection: close');
            }
            return false;
        }
    }

    #Run the function based on the task details

    /**
     * @throws \Exception
     */
    public function runTask(string $taskName, null|array|string $arguments = NULL): bool
    {
        if (self::$enabled) {
            try {
                #Sanitize arguments
                $arguments = $this->sanitize($arguments);
                #Get full details
                $task = self::$dbController->SelectRow('SELECT * FROM `'.self::dbPrefix.'schedule` INNER JOIN `'.self::dbPrefix.'tasks` ON `'.self::dbPrefix.'schedule`.`task`=`'.self::dbPrefix.'tasks`.`task` WHERE `status`<>2 AND `'.self::dbPrefix.'schedule`.`task`=:task AND `arguments`=:arguments', [
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
                #Update last run
                self::$dbController->query('UPDATE `'.self::dbPrefix.'schedule` SET `status`=2, `lastrun` = UTC_TIMESTAMP() WHERE `task`=:task AND `arguments`=:arguments;', [
                    ':task' => [$task['task'], 'string'],
                    ':arguments' => [strval($task['arguments']), 'string'],
                ]);
                #Check if object is required
                if (!empty($task['object'])) {
                    #Check if parameters for the object are set
                    if (!empty($task['parameters'])) {
                        $task['parameters'] = $this->json_decode_alt($task['parameters']);
                        if (is_array($task['parameters'])) {
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
                    }
                    #Generate object
                    if (empty($task['parameters'])) {
                        $object = (new $task['object']);
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
                if (!is_callable($function)) {
                    #Register error
                    $this->error('Function is not callable', $task['task'], $task['arguments']);
                    #Reschedule
                    $this->reSchedule($task['task'], $task['arguments'], $task['frequency'], false);
                    return false;
                }
                #Run function
                if (empty($task['arguments'])) {
                    $result = call_user_func($function);
                } else {
                    #Decode arguments
                    $finalArguments = $this->json_decode_alt($task['arguments']);
                    if (is_array($finalArguments)) {
                        $result = call_user_func_array($function, $finalArguments);
                    } else {
                        $result = call_user_func($function);
                    }
                }
                #Decode allowed returns if any
                if (!empty($task['allowedreturns'])) {
                    $task['allowedreturns'] = $this->json_decode_alt($task['allowedreturns']);
                    #Check that it's an array
                    if (!is_array($task['allowedreturns'])) {
                        #Remove it as invalid value
                        unset($task['allowedreturns']);
                    }
                }
            } catch(\Exception $e) {
                $result = $e->getMessage()."\r\n".$e->getTraceAsString();
            }
            #Validate result
            if ($result !== true) {
                #Check if it's an allowed return value
                if (!empty($task['allowedreturns']) && in_array($result, $task['allowedreturns']) === true) {
                    #Override the value
                    $result = true;
                } else {
                    #Register error. strval is silenced to avoid warning in case result is an array or object, that can't be converted
                    $this->error(@strval($result), $taskName, $arguments);
                    $result = false;
                }
            }
            #Reschedule
            $this->reSchedule($taskName, $arguments, (empty($task['frequency']) ? 0 : $task['frequency']), $result);
            #Return
            return $result;
        } else {
            return false;
        }
    }

    #Adjust settings

    /**
     * @throws \Exception
     */
    public function setSetting(string $setting, int $value): self
    {
        #Check setting name
        if (!in_array($setting, $this->settings)) {
            throw new \InvalidArgumentException('Attempt to set unsupported setting');
        }
        #Handle values lower than 0
        if ($value <= 0) {
            $value = match($setting) {
                'enabled', 'sseLoop' => 0,
                'errorLife' => 30,
                'maxTime', 'retry' => 3600,
                'sseRetry' => 10000,
                'maxThreads' => 4,
            };
        }
        #Handle booleans
        if (in_array($setting, ['enabled', 'sseLoop']) && $value > 1) {
            $value = 1;
        }
        if (self::$dbController->query('UPDATE `'.self::dbPrefix.'settings` SET `value`=:value WHERE `setting`=:setting;', [
                ':setting' => [$setting, 'string'],
                ':value' => [$value, 'int'],
            ]) === true) {
            if (in_array($setting, ['enabled', 'sseLoop'])) {
                self::${$setting} = boolval($value);
            } else {
                self::${$setting} = $value;
            }
            return $this;
        } else {
            throw new \UnexpectedValueException('Failed to set setting `'.$setting.'` to '.$value);
        }
    }

    #Schedule a task or update its frequency

    /**
     * @throws \Exception
     */
    public function add(string $task, null|array|string $arguments = '', int|string $frequency = 0, int $priority = 0, ?string $message = NULL, null|array|string $dayofmonth = NULL, null|array|string $dayofweek = NULL, int $time = 0): bool
    {
        if (self::$dbReady) {
            #Sanitize arguments
            $arguments = $this->sanitize($arguments);
            $dayofmonth = $this->sanitize($dayofmonth);
            $dayofweek = $this->sanitize($dayofweek);
            $frequency = intval($frequency);
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
            return self::$dbController->query('INSERT INTO `'.self::dbPrefix.'schedule` (`task`, `arguments`, `frequency`, `dayofmonth`, `dayofweek`, `priority`, `message`, `nextrun`) VALUES (:task, :arguments, :frequency, :dayofmonth, :dayofweek, :priority, :message, :nextrun) ON DUPLICATE KEY UPDATE `frequency`=:frequency, `dayofmonth`=:dayofmonth, `dayofweek`=:dayofweek, `nextrun`=:nextrun, `priority`=:priority, `message`=:message, `updated`=UTC_TIMESTAMP();', [
                ':task' => [$task, 'string'],
                ':arguments' => [$arguments, 'string'],
                ':frequency' => [$frequency, 'int'],
                ':dayofmonth' => [$dayofmonth, (empty($dayofmonth) ? 'null' : 'string')],
                ':dayofweek' => [$dayofweek, (empty($dayofweek) ? 'null' : 'string')],
                ':priority' => [$priority, 'int'],
                ':message' => [$message, (empty($message) ? 'null' : 'string')],
                ':nextrun' => [$time, 'time'],
            ]);
        } else {
            return false;
        }
    }

    #Remove a task from schedule

    /**
     * @throws \Exception
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
        } else {
            return false;
        }
    }

    #Add (or update) task type

    /**
     * @throws \Exception
     */
    public function addTask(string $task, string $function, ?string $object = NULL, null|array|string $parameters = NULL, null|array|string $returns = NULL, ?string $desc = NULL): bool
    {
        if (self::$dbReady) {
            #Sanitize parameters and return
            $parameters = $this->sanitize($parameters);
            $returns = $this->sanitize($returns);
            return self::$dbController->query('INSERT INTO `'.self::dbPrefix.'tasks`(`task`, `function`, `object`, `parameters`, `allowedreturns`, `description`) VALUES (:task, :function, :object, :parameters, :returns, :desc) ON DUPLICATE KEY UPDATE `function`=:function, `object`=:object, `parameters`=:parameters, `allowedreturns`=:returns, `description`=:desc;', [
                ':task' => [$task, 'string'],
                ':function' => [$function, 'string'],
                ':object' => [$object, 'string'],
                ':parameters' => [$parameters, (empty($parameters) ? 'null' : 'string')],
                ':returns' => [$returns, (empty($returns) ? 'null' : 'string')],
                ':desc' => [$desc, 'string'],
            ]);
        } else {
            return false;
        }
    }

    #Delete task type

    /**
     * @throws \Exception
     */
    public function deleteTask(string $task): bool
    {
        if (self::$dbReady) {
            return self::$dbController->query('DELETE FROM `'.self::dbPrefix.'tasks` WHERE `task`=:task;', [
                ':task' => [$task, 'string'],
            ]);
        } else {
            return false;
        }
    }

    #Function to reschedule hanged jobs

    /**
     * @throws \Exception
     */
    public function unHang(): bool
    {
        if (self::$dbReady) {
            return self::$dbController->query('UPDATE `'.self::dbPrefix.'schedule` SET `status`=0, `runby`=NULL, `sse`=0, `nextrun`='.$this->sqlNextRun.', `lastrun`=IF(`lastrun` IS NULL, UTC_TIMESTAMP(), `lastrun`), `lasterror`=UTC_TIMESTAMP() WHERE `status`<>0 AND UTC_TIMESTAMP()>DATE_ADD(IF(`lastrun` IS NOT NULL, `lastrun`, `nextrun`), INTERVAL :maxTime SECOND);', [
                ':time' => [self::$retry, 'int'],
                ':maxTime' => [self::$maxTime, 'int'],
            ]);
        } else {
            return false;
        }
    }

    #Function to clean up errors
    /**
     * @throws \Exception
     */
    public function errorPurge(): bool
    {
        if (self::$dbReady) {
            return self::$dbController->query('DELETE FROM `'.self::dbPrefix.'errors` WHERE `time` <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :errorLife DAY);', [
                ':errorLife' => [self::$errorLife, 'int'],
            ]);
        } else {
            return false;
        }
    }

    #Function to prepare tables
    private function install(): bool|string
    {
        #Get contents from SQL file
        $sql = file_get_contents(__DIR__.'\install.sql');
        #Split file content into queries
        $sql = self::$dbController->stringToQueries($sql);
        try {
            return self::$dbController->query($sql);
        } catch(\Exception $e) {
            return $e->getMessage()."\r\n".$e->getTraceAsString();
        }
    }

    #Reschedule a task (or remove it if it's onetime)

    /**
     * @throws \Exception
     */
    private function reSchedule(string $task, string $arguments = '', int|string $frequency = 0, bool $result = true): void
    {
        if (self::$dbReady) {
            #Ensure schedule is INT
            $frequency = intval($frequency);
            #Check whether this is a successful one-time job
            if ($frequency === 0 && $result === true) {
                #Since this is a one-time task, we can just remove it
                $this->delete($task, $arguments);
            } else {
                #Actually reschedule. One task time task will be rescheduled for the retry time from settings
                /** @noinspection SqlResolve */
                self::$dbController->query('UPDATE `'.self::dbPrefix.'schedule` SET `status`=0, `runby`=NULL, `sse`=0, `nextrun`='.$this->sqlNextRun.', `'.($result === true ? 'lastsuccess' : 'lasterror').'`=UTC_TIMESTAMP() WHERE `task`=:task AND `arguments`=:arguments;', [
                    ':time' => [self::$retry, 'int'],
                    ':task' => [$task, 'string'],
                    ':arguments' => [$arguments, 'string'],
                ]);
            }
        }
    }

    #Register error

    /**
     * @throws \Exception
     */
    private function error(string $text, string $task, string $arguments = ''): void
    {
        if (self::$dbReady) {
            #Insert error
            self::$dbController->query(
                'INSERT INTO `'.self::dbPrefix.'errors` (`time`, `task`, `arguments`, `text`) VALUES (UTC_TIMESTAMP(), :task, :arguments, :text) ON DUPLICATE KEY UPDATE `time`=UTC_TIMESTAMP(), `text`=:text;',
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

    #Helper function to sanitize the arguments/parameters into JSON string or NULL
    private function sanitize(null|array|string $arguments = NULL): string
    {
        #Return NULL if empty
        if (empty($arguments)) {
            return '';
        } else {
            #Check if string
            if (is_string($arguments)) {
                #Check if JSON
                $this->json_decode_alt($arguments);
                return $arguments;
            } else {
                #We have an array
                return json_encode($arguments, JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
            }
        }
    }

    #Helper function to throw better JSON errors
    private function json_decode_alt(string $arguments): bool|array
    {
        try {
            return json_decode($arguments, flags: JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_BIGINT_AS_STRING|JSON_OBJECT_AS_ARRAY);
        } catch(\Exception $e) {
            throw new \InvalidArgumentException('JSON decoding of \''.$arguments.'\' string failed with \''.$e->getMessage().'\' error.');
        }
    }

    #Helper function to output event stream data
    private function streamEcho(?string $message = '', string $event = 'Status'): void
    {
        echo 'retry: '.self::$sseRetry."\n".'id: '.hrtime(true)."\n".(empty($event) ? '' : 'event: '.$event."\n").'data: '.$message."\n\n";
        ob_flush();
		flush();
    }

    #Helper function to validate whether job is allowed to run today
    /**
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
            #If not an array - allow run
            if (is_array($values)) {
                #If empty - allow run
                if (!empty($values)) {
                    #Filter non-integers, negative integers and too large integers
                    $values = array_filter($values, function ($item) use ($maxValue) {
                        return (is_int($item) && $item >= 1 && $item <= $maxValue);
                    });
                    #If empty after filtering - allow run
                    if (!empty($values)) {
                        #If current day is in array - allow run
                        if (!in_array(intval(date($format, time())), $values)) {
                            return false;
                        }
                    }
                }
            }
        }
        return true;
    }

    #Helper function to check number of active threads
    /**
     * @throws \Exception
     */
    private function threadAvailable(): bool
    {
        #Get current count
        $current = self::$dbController->count('SELECT COUNT(DISTINCT(`runby`)) FROM `'.self::dbPrefix.'schedule` WHERE `runby` IS NOT NULL;');
        if ($current < self::$maxThreads) {
            return true;
        } else {
            return false;
        }
    }

    #Helper function to get/update settings
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
            self::$enabled = boolval(intval($settings['enabled']));
        }
        #Update SSE loop flag
        if (isset($settings['sseLoop'])) {
            self::$sseLoop = boolval(intval($settings['sseLoop']));
        }
        #Update retry time
        if (isset($settings['retry'])) {
            $settings['retry'] = intval($settings['retry']);
            if ($settings['retry'] > 0) {
                self::$retry = $settings['retry'];
            }
        }
        #Update SSE retry time
        if (isset($settings['sseRetry'])) {
            $settings['sseRetry'] = intval($settings['sseRetry']);
            if ($settings['sseRetry'] > 0) {
                self::$retry = $settings['sseRetry'];
            }
        }
        #Update maximum time
        if (isset($settings['maxTime'])) {
            $settings['maxTime'] = intval($settings['maxTime']);
            if ($settings['maxTime'] > 0) {
                self::$maxTime = $settings['maxTime'];
            }
        }
        #Update maximum number of threads
        if (isset($settings['maxThreads'])) {
            $settings['maxThreads'] = intval($settings['maxThreads']);
            if ($settings['maxThreads'] > 0) {
                self::$maxThreads = $settings['maxThreads'];
            }
        }
        #Update maximum life of an error
        if (isset($settings['errorLife'])) {
            $settings['errorLife'] = intval($settings['errorLife']);
            if ($settings['errorLife'] > 0) {
                self::$errorLife = $settings['errorLife'];
            }
        }
        return true;
    }
}
