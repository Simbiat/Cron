<?php
declare(strict_types=1);
namespace Simbiat;

class Cron
{
    #Flag to indicate that we are ready to work with DB
    public static bool $dbReady = false;
    #Cached database controller for performance
    private static ?\Simbiat\Database\Controller $dbController = NULL;
    #Database prefix
    private static $prefix = 'cron__';
    #Flag to indicate, that we are maintenance
    public static bool $enabled = false;
    #Retry time
    public static int $retryTime = 3600;
    #Maximum job time
    public static int $maxTime = 3600;
    #Errors life
    public static int $errorLife = 30;
    #CLI mode flag
    private static $CLI = false;
    
    public function __construct(string $prefix = 'cron__')
    {
        #Update prefix
        self::$prefix = $prefix;
        #Check that database connection is established
        if (self::$dbReady === false) {
            if ((new \Simbiat\Database\Pool)::$activeconnection !== NULL) {
                self::$dbReady = true;
                #Cache controller
                self::$dbController = (new \Simbiat\Database\Controller);
                #Get settings
                $settings = self::$dbController->selectPair('SELECT `setting`, `value` FROM `'.self::$prefix.'settings`');
                #Update enabled flag
                if (!empty($settings['enabled'])) {
                    self::$enabled = boolval($settings['enabled']);
                }
                #Update retry time
                if (!empty($settings['retry'])) {
                    $settings['retry'] = intval($settings['retry']);
                    if ($settings['retry'] > 0) {
                        self::$retryTime = $settings['retry'];
                    }
                }
                #Update maximum time
                if (!empty($settings['maxtime'])) {
                    $settings['maxtime'] = intval($settings['maxtime']);
                    if ($settings['maxtime'] > 0) {
                        self::$maxTime = $settings['maxtime'];
                    }
                }
                #Update maximum time
                if (!empty($settings['errorlife'])) {
                    $settings['errorlife'] = intval($settings['errorlife']);
                    if ($settings['errorlife'] > 0) {
                        self::$errorLife = $settings['errorlife'];
                    }
                }
            }
        }
        #Check if we are in CLI
        if (preg_match('/^cli(-server)?$/i', php_sapi_name()) === 1) {
            self::$CLI = true;
        }
    }
    
    #Process the items
    public function process(int $items = 1): bool
    {
        #Regular maintenance
        if (self::$dbReady) {
            #Reschedule hanged jobs
            $this->unHang();
            #Clean old errors
            $this->errorPurge();
        }
        #Check if cron is enabled and process only if it is
        if (self::$enabled) {
            #Sanitize number of items
            if ($items < 1) {
                $items = 1;
            }
            #Get tasks
            $tasks = self::$dbController->SelectAll('SELECT `'.self::$prefix.'schedule`.`task`, `arguments` FROM `'.self::$prefix.'schedule` INNER JOIN `'.self::$prefix.'tasks` ON `'.self::$prefix.'schedule`.`task`=`'.self::$prefix.'tasks`.`task` WHERE `status`=0 AND `function`<>\'\' AND `function` IS NOT NULL AND `nextrun`<=UTC_TIMESTAMP() ORDER BY `priority` DESC, `nextrun` ASC LIMIT '.$items);
            if (is_array($tasks) && !empty($tasks)) {
                foreach ($tasks as $task) {
                    $this->runTask($task['task'], $task['arguments']);
                }
            }
            return true;
        } else {
            return false;
        }
    }
    
    #Run the function based on the task details
    public function runTask(string $task, mixed $arguments = NULL): bool
    {
        if (self::$enabled) {
            #Sanitize arguments
            $arguments = $this->sanitize($arguments);
            #Set negative value by default
            $result = false;
            try {
                #Get full details
                $task = self::$dbController->SelectRow('SELECT * FROM `'.self::$prefix.'schedule` INNER JOIN `'.self::$prefix.'tasks` ON `'.self::$prefix.'schedule`.`task`=`'.self::$prefix.'tasks`.`task` WHERE `status`=0 AND `function`<>\'\' AND `function` IS NOT NULL AND `'.self::$prefix.'schedule`.`task`=:task AND `arguments` '.(empty($arguments) ? 'IS' : '=').' :arguments', [
                    ':task' => [$task, 'string'],
                    ':arguments' => [$arguments, (empty($arguments) ? 'null' : 'string')]
                ]);
                if (empty($task)) {
                    return false;
                }
                #Update last run
                self::$dbController->query('UPDATE `'.self::$prefix.'schedule` SET `status`= 1, `lastrun` = UTC_TIMESTAMP() WHERE `task`=:task AND `arguments` '.(empty($task['arguments']) ? 'IS' : '=').' :arguments', [
                    ':task' => [$task['task'], 'string'],
                    ':arguments' => [$task['arguments'], (empty($task['arguments']) ? 'null' : 'string')],
                ]);
                #Check if object is required
                if (!empty($task['object'])) {
                    #Check if parameters for the object are set
                    if (!empty($task['parameters'])) {
                        $task['parameters'] = json_decode($task['parameters']);
                        #Check if extra methods are set
                        if (!empty($task['parameters']['extramethods'])) {
                            #Separate extra methods
                            $extramethods = $task['parameters']['extramethods'];
                            #Remove them from original
                            unset($task['parameters']['extramethods']);
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
                            
                            #Check for arguments for the method
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
                    $this->reSchedule($task['task'], $task['arguments'], $task['schedule'], false);
                    return false;
                }
                #Run function
                if (empty($task['arguments'])) {
                    $result = call_user_func($function);
                } else {
                    #Decode arguments
                    $arguments = json_decode($task['arguments']);
                    if (is_array($arguments)) {
                        $result = call_user_func_array($function, $arguments);
                    } else {
                        $result = call_user_func($function);
                    }
                }
                #Decode allowed returns if any
                if (!empty($task['allowedreturns'])) {
                    $task['allowedreturns'] = json_decode($task['allowedreturns']);
                    #Check that it's an array
                    if (!is_array($task['allowedreturns'])) {
                        #Remove it as invalid value
                        unset($task['allowedreturns']);
                    }
                }
            } catch(Exception $e) {
                $result = $e->getMessage()."\r\n".$e->getTraceAsString();
            }
            #Validate result
            if ($result !== true) {
                #Check if it's an allowed return value
                if (!empty($task['allowedreturns']) && in_array($result, $task['allowedreturns']) === true) {
                    #Override the value
                    $result = true;
                } else {
                    #Register error
                    $this->error(strval($result), $task['task'], $task['arguments']);
                    $result = false;
                }
            }
            if ($result === true) {
                #Update last successful run time
                self::$dbController->query('UPDATE `'.self::$prefix.'schedule` SET `lastsuccess` = UTC_TIMESTAMP() WHERE `task`=:task AND `arguments` '.(empty($task['arguments']) ? 'IS' : '=').' :arguments', [
                    ':task' => [$task['task'], 'string'],
                    ':arguments' => [$task['arguments'], (empty($task['arguments']) ? 'null' : 'string')],
                ]);
            }
            #Reschedule
            $this->reSchedule($task['task'], $task['arguments'], $task['schedule'], $result);
            #Return
            return $result;
        } else {
            return false;
        }
    }
    
    #Schedule a task or update its schedule
    public function add(string $task, mixed $arguments = NULL, int|string $schedule = 0, int $priority = 0, ?string $message = NULL, int $time = 0): bool
    {
        if (self::$dbReady) {
            #Sanitize arguments
            $arguments = $this->sanitize($arguments);
            $schedule = intval($schedule);
            if ($schedule < 0) {
                $schedule = 0;
            }
            if ($priority < 0) {
                $priority = 0;
            } elseif ($priority > 255) {
                $priority = 255;
            }
            if ($time <= 0) {
                $time = time();
            }
            return self::$dbController->query('INSERT INTO `'.self::$prefix.'schedule`(`task`, `arguments`, `schedule`, `priority`, `message`, `nextrun`) VALUES (:task, :arguments, :schedule, :priority, :message, :nextrun) ON DUPLICATE KEY UPDATE `schedule`=:schedule, `nextrun`=:nextrun, `priority`=:priority, `message`=:message, `updated`=UTC_TIMESTAMP();', [
                ':task' => [$task, 'string'],
                ':arguments' => [$arguments, (empty($arguments) ? 'null' : 'string')],
                ':schedule' => [$schedule, 'int'],
                ':priority' => [$priority, 'int'],
                ':message' => [$message, (empty($message) ? 'null' : 'string')],
                ':nextrun' => [$time, 'time'],
            ]);
        } else {
            return false;
        }
    }
    
    #Remove a task from schedule
    public function delete(string $task, mixed $arguments = NULL): bool
    {
        if (self::$dbReady) {
            #Sanitize arguments
            $arguments = $this->sanitize($arguments);
            return self::$dbController->query('DELETE FROM `'.self::$prefix.'schedule` WHERE `task`=:task AND `arguments` '.(empty($arguments) ? 'IS' : '=').' :arguments', [
                ':task' => [$task, 'string'],
                ':arguments' => [$arguments, (empty($arguments) ? 'null' : 'string')],
            ]);
        } else {
            return false;
        }
    }
    
    #Add task type
    public function addTask(): bool
    {
        if (self::$dbReady) {
            #Sanitize arguments
            $arguments = $this->sanitize($arguments);
        } else {
            return false;
        }
    }
    
    #Delete task type
    public function deleteTask(): bool
    {
        if (self::$dbReady) {
            #Sanitize arguments
            $arguments = $this->sanitize($arguments);
        } else {
            return false;
        }
    }
    
    #Function to reschedule hanged jobs
    public function unHang(): bool
    {
        if (self::$dbReady) {
            return self::$dbController->query('UPDATE `'.self::$prefix.'schedule` SET `status`=0, `nextrun`=TIMESTAMPADD(SECOND, IF(`schedule`>0, `schedule`, :retry), CONCAT(UTC_DATE(), DATE_FORMAT(`nextrun`, \' %H:%i:%S.%u\'))) WHERE `status`=1 AND UTC_TIMESTAMP()>DATE_ADD(`lastrun`, INTERVAL :maxtime SECOND);', [
                ':retry' => [self::$retryTime, 'int'],
                ':maxtime' => [self::$maxTime, 'int'],
            ]);
        } else {
            return false;
        }
    }
    
    #Function to cleanup errors
    public function errorPurge(): bool
    {
        if (self::$dbReady) {
            return self::$dbController->query('DELETE FROM `'.self::$prefix.'errors` WHERE `time` <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :errorlife DAY);', [
                ':errorlife' => [self::$errorLife, 'int'],
            ]);
        } else {
            return false;
        }
    }
    
    #Reschedule a task (or remove it if it's onetime)
    private function reSchedule(string $task, mixed $arguments = NULL, int|string $schedule = 0, bool $result = true): bool
    {
        if (self::$dbReady) {
            #Ensure schedule is INT
            $schedule = intval($schedule);
            #Check whether this is a successful one-time job
            if ($schedule === 0 && $result === true) {
                #Since this is a one-time task, we can just remove it
                return $this->delete($task, $arguments);
            } else {
                #Actually reschedule. One task time task will be rescheduled for the retry time from settings
                return self::$dbController->query('UPDATE `'.self::$prefix.'schedule` SET `status`=0, `nextrun`=TIMESTAMPADD(SECOND, IF(`schedule`>0, `schedule`, :time), CONCAT(UTC_DATE(), DATE_FORMAT(`nextrun`, \' %H:%i:%S.%u\'))) WHERE `task`=:task AND `arguments` '.(empty($arguments) ? 'IS' : '=').' :arguments', [
                    ':time' => [self::$retryTime, 'int'],
                    ':task' => [$task, 'string'],
                    ':arguments' => [$arguments, (empty($arguments) ? 'null' : 'string')],
                ]);
            }
        } else {
            return false;
        }
    }
    
    #Register error
    private function error(string $text, string $task, mixed $arguments = NULL): void
    {
        if (self::$dbReady) {
            #Set time, so that we will have identical value
            $time = time();
            #Insert error
            self::$dbController->query(
                'INSERT INTO `'.self::$prefix.'errors` (`time`, `task`, `arguments`, `text`) VALUES (:time, :task, :arguments, :text) ON DUPLICATE KEY UPDATE `time`=:time, `text`=:text;',
                [
                    ':time' => [$time, 'time'],
                    ':task' => [$task, (empty($task) ? 'null' : 'string')],
                    ':arguments' => [$arguments, (empty($arguments) ? 'null' : 'string')],
                    ':text' => [$text, 'string'],
                ]
            );
            #Update error if task is not empty
            if (!empty($task)) {
                self::$dbController->query('UPDATE `'.self::$prefix.'schedule` SET `lasterror`=:time WHERE `task`=:task AND `arguments` '.(empty($arguments) ? 'IS' : '=').' :arguments', [
                    ':time' => [$time, 'time'],
                    ':task' => [$task, 'string'],
                    ':arguments' => [$arguments, (empty($arguments) ? 'null' : 'string')],
                ]);
            }
        }
    }
    
    #Helper function to sanitize the arguments/parameters into JSON string or NULL
    private function sanitize(mixed $arguments = NULL): ?string
    {
        #Return NULL if empty
        if (empty($arguments)) {
            return NULL;
        } else {
            #Check if string
            if (is_string($arguments)) {
                #Check if JSON
                $json = json_decode($arguments);
                #If it is a JSON - return it as is
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $arguments;
                }
            }
            #Encode to JSON and return it
            return json_encode($arguments, JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
        }
    }
    
    public function streamEcho(string $data = '', string $event = 'Status'): void
    {
        header('Content-Type: text/event-stream');
        echo "retry: 10000\nid: ".time()."\n".(empty($event) ? '' : 'event: '.$event."\n").'data: '.$data."\n\n";
        ob_flush();
		flush();
    }
}
?>