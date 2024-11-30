<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use Simbiat\SandClock;
use function is_string, is_array, in_array;

/**
 * Scheduled task instance object
 */
class TaskInstance
{
    /**
     * @var string Unique name of the task
     */
    public private(set) string $taskName = '';
    /**
     * @var string Optional object reference
     */
    public private(set) string $arguments = '';
    /**
     * @var int Task instance
     */
    public private(set) int $instance = 1;
    /**
     * @var bool Whether task instance is system one or not
     */
    public private(set) bool $system = false;
    /**
     * @var int Task instance frequency
     */
    public private(set) int $frequency = 0;
    /**
     * @var string|null Day of month limitation
     */
    public private(set) ?string $dayofmonth = null;
    /**
     * @var string|null Day of week limitation
     */
    public private(set) ?string $dayofweek = null;
    /**
     * @var int Task instance priority
     */
    public private(set) int $priority = 0;
    /**
     * @var string|null Message to show in SSE mode
     */
    public private(set) ?string $message = null;
    /**
     * @var int Time of the next run
     */
    public private(set) int $nextTime = 0;
    /**
     * @var bool Whether task was found in database
     */
    public private(set) bool $foundInDB = false;
    /**
     * @var Task|null Task object
     */
    public private(set) ?Task $taskObject = null;
    
    /**
     * Create a Cron task object
     *
     * @param string $taskName If name is not empty, settings will be attempts to be loaded from database
     *
     * @throws \JsonException
     */
    public function __construct(string $taskName = '', string|array|null $arguments = null, int $instance = 1)
    {
        #Ensure that Cron management is created to establish DB connection and settings
        new Agent();
        if (!empty($taskName) && Agent::$dbReady) {
            $this->taskName = $taskName;
            $this->setArguments($arguments);
            $this->setInstance($instance);
            #Attempt to get settings from DB
            $this->getFromDB();
        }
    }
    
    /**
     * Get task settings from database
     *
     * @return void
     * @throws \JsonException
     */
    private function getFromDB(): void
    {
        if (Agent::$dbReady) {
            $settings = Agent::$dbController->selectRow('SELECT * FROM `'.Agent::dbPrefix.'schedule` WHERE `task`=:name AND `arguments`=:arguments AND `instance`=:instance;',
                [
                    ':name' => $this->taskName,
                    ':arguments' => $this->arguments,
                    ':instance' => [$this->instance, 'int'],
                ]
            );
            if (!empty($settings)) {
                #Get task object
                $this->taskObject = (new Task($this->taskName));
                #Process settings
                $this->settingsFromArray($settings);
                #If nothing failed at this point, set the flag to `true`
                $this->foundInDB = $this->taskObject->foundInDB;
            }
        }
    }
    
    /**
     * Set task settings from associative array
     *
     * @return $this
     * @throws \JsonException
     */
    public function settingsFromArray(array $settings): self
    {
        #If we are creating a task instance from outside the class (for example new instance), we may not have all details, so ensure we get them
        if ($this->taskObject === null && !empty($settings['task'])) {
            $this->taskObject = (new Task($settings['task']));
        }
        #We need to process system status first, since frequency depends on it
        if (isset($settings['system'])) {
            $this->system = (bool)$settings['system'];
        }
        foreach ($settings as $setting => $value) {
            switch ($setting) {
                case 'task':
                    $this->taskName = $value;
                    break;
                case 'arguments':
                    $this->setArguments($value);
                    break;
                case 'instance':
                    $this->setInstance($value);
                    break;
                case 'frequency':
                    $this->setFrequency($value);
                    break;
                case 'priority':
                    $this->setPriority($value);
                    break;
                case 'dayofmonth':
                    $this->setDayOfMonth($value);
                    break;
                case 'dayofweek':
                    $this->setDayOfWeek($value);
                    break;
                case 'nextrun':
                    $this->nextTime = strtotime($value);
                    break;
                case 'message':
                    if (empty($value)) {
                        $this->message = null;
                    } else {
                        $this->message = $value;
                    }
                    break;
                default:
                    #Do nothing
                    break;
            }
        }
        return $this;
    }
    
    /**
     * Set task instance arguments
     * @param mixed $value
     *
     * @return void
     * @throws \JsonException
     */
    private function setArguments(mixed $value): void
    {
        if (empty($value)) {
            $this->arguments = '';
        } elseif (is_array($value)) {
            $this->arguments = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_string($value) && json_validate($value)) {
            $this->arguments = $value;
        } else {
            throw new \UnexpectedValueException('`arguments` is not an array or a valid JSON string');
        }
    }
    
    /**
     * Set task instance day of month limitation
     * @param mixed $value
     *
     * @return void
     * @throws \JsonException
     */
    private function setDayOfMonth(mixed $value): void
    {
        if (empty($value)) {
            $this->dayofmonth = null;
        } elseif (is_array($value)) {
            $this->dayofmonth = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_string($value) && json_validate($value)) {
            $this->dayofmonth = $value;
        } else {
            throw new \UnexpectedValueException('`dayofmonth` is not an array or a valid JSON string');
        }
    }
    
    /**
     * Set task instance day of week limitation
     * @param mixed $value
     *
     * @return void
     * @throws \JsonException
     */
    private function setDayOfWeek(mixed $value): void
    {
        if (empty($value)) {
            $this->dayofweek = null;
        } elseif (is_array($value)) {
            $this->dayofweek = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_string($value) && json_validate($value)) {
            $this->dayofweek = $value;
        } else {
            throw new \UnexpectedValueException('`dayofweek` is not an array or a valid JSON string');
        }
    }
    
    /**
     * Set task instance number
     * @param mixed $value
     *
     * @return void
     */
    private function setInstance(mixed $value): void
    {
        if (empty($value)) {
            $this->instance = 1;
        } elseif (is_numeric($value)) {
            $this->instance = (int)$value;
            if ($this->instance < 1) {
                $this->instance = 1;
            }
        } else {
            throw new \UnexpectedValueException('`instance` is not a valid numeric value');
        }
    }
    
    /**
     * Set task instance frequency
     * @param mixed $value
     *
     * @return void
     */
    private function setFrequency(mixed $value): void
    {
        if (empty($value)) {
            $this->frequency = 0;
        } elseif (is_numeric($value)) {
            $frequency = (int)$value;
            if ($frequency < 0) {
                $frequency = 0;
            }
            if ($frequency > 0 && $frequency < $this->taskObject->minFrequency) {
                throw new \UnexpectedValueException('`frequency` for `'.$this->taskName.'` should be either 0 (one-time job) or equal or more than '.$this->taskObject->minFrequency.' seconds');
            }
            if ($frequency === 0 && $this->system) {
                throw new \UnexpectedValueException('`frequency` cannot be set to 0 (one-time job), if task instance is system one');
            }
            $this->frequency = $frequency;
        } else {
            throw new \UnexpectedValueException('`frequency` is not a valid numeric value');
        }
    }
    
    /**
     * Set task instance priority
     * @param mixed $value
     *
     * @return void
     */
    private function setPriority(mixed $value): void
    {
        if (empty($value)) {
            $this->priority = 0;
        } elseif (is_numeric($value)) {
            $this->priority = (int)$value;
            if ($this->priority < 0) {
                $this->priority = 0;
            } elseif ($this->priority > 255) {
                $this->priority = 255;
            }
        } else {
            throw new \UnexpectedValueException('`priority` is not a valid numeric value');
        }
    }
    
    /**
     * Schedule or update a task
     *
     * @return bool
     */
    public function add(): bool
    {
        if (empty($this->taskName)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        if (Agent::$dbReady) {
            $result = false;
            try {
                $result = Agent::$dbController->query('INSERT INTO `'.Agent::dbPrefix.'schedule` (`task`, `arguments`, `instance`, `system`, `frequency`, `dayofmonth`, `dayofweek`, `priority`, `message`, `nextrun`) VALUES (:task, :arguments, :instance, :system, :frequency, :dayofmonth, :dayofweek, :priority, :message, :nextrun) ON DUPLICATE KEY UPDATE `frequency`=:frequency, `dayofmonth`=:dayofmonth, `dayofweek`=:dayofweek, `nextrun`=IF(:frequency=0, `nextrun`, :nextrun), `priority`=IF(:frequency=0, IF(`priority`>:priority, `priority`, :priority), :priority), `message`=:message, `updated`=CURRENT_TIMESTAMP();', [
                    ':task' => [$this->taskName, 'string'],
                    ':arguments' => [$this->arguments, 'string'],
                    ':instance' => [$this->instance, 'int'],
                    ':system' => [$this->system, 'bool'],
                    ':frequency' => [(empty($this->frequency) ? 0 : $this->frequency), 'int'],
                    ':dayofmonth' => [$this->dayofmonth, (empty($this->dayofmonth) ? 'null' : 'string')],
                    ':dayofweek' => [$this->dayofweek, (empty($this->dayofweek) ? 'null' : 'string')],
                    ':priority' => [(empty($this->priority) ? 0 : $this->priority), 'int'],
                    ':message' => [$this->message, (empty($this->message) ? 'null' : 'string')],
                    ':nextrun' => [(empty($this->time) ? time() : $this->nextTime), 'datetime'],
                ]);
            } catch (\Throwable $e) {
                Agent::log('Failed to add or update task instance.', 'InstanceAddFail', error: $e, task: $this);
            }
            #Log only if something was actually changed
            if (Agent::$dbController->getResult() > 0) {
                Agent::log('Added or updated task instance.', 'InstanceAdd', task: $this);
            }
            return $result;
        }
        return false;
    }
    
    /**
     * Delete item from schedule
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (empty($this->taskName)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        if (Agent::$dbReady) {
            $result = false;
            try {
                $result = Agent::$dbController->query('DELETE FROM `'.Agent::dbPrefix.'schedule` WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `system`=0;', [
                    ':task' => [$this->taskName, 'string'],
                    ':arguments' => [$this->arguments, 'string'],
                    ':instance' => [$this->instance, 'int'],
                ]);
            } catch (\Throwable $e) {
                Agent::log('Failed to delete task instance.', 'InstanceDeleteFail', error: $e, task: $this);
            }
            #Log only if something was actually deleted, and if it's not a one-time job
            if ($this->frequency > 0 && Agent::$dbController->getResult() > 0) {
                Agent::log('Deleted task instance.', 'InstanceDelete', task: $this);
            }
            return $result;
        }
        return false;
    }
    
    /**
     * Set the task instance as system
     * @return bool
     */
    public function setSystem(): bool
    {
        if ($this->foundInDB === false) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        if ($this->frequency === 0) {
            throw new \UnexpectedValueException('One-time job cannot be made system one');
        }
        if (Agent::$dbReady) {
            $result = false;
            try {
                $result = Agent::$dbController->query('UPDATE `'.Agent::dbPrefix.'schedule` SET `system`=1 WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance `system`=0;', [
                    ':task' => [$this->taskName, 'string'],
                    ':arguments' => [$this->arguments, 'string'],
                    ':instance' => [$this->instance, 'int'],
                ]);
            } catch (\Throwable $e) {
                Agent::log('Failed to mark task instance as system one.', 'InstanceToSystemFail', error: $e, task: $this);
            }
            #Log only if something was actually changed
            if (Agent::$dbController->getResult() > 0) {
                Agent::log('Marked task instance as system one.', 'InstanceToSystem', task: $this);
            }
            return $result;
        }
        return false;
    }
    
    /**
     * Reschedule a task (or remove it if it's onetime)
     *
     * @param bool     $result    Whether task was successful
     * @param int|null $timestamp Optional timestamp to use
     *
     * @return bool
     * @throws \JsonException
     */
    public function reSchedule(bool $result = true, ?int $timestamp = null): bool
    {
        if ($this->foundInDB === false) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        if (Agent::$dbReady) {
            #Check whether this is a successful one-time job
            if ($this->frequency === 0 && $result === true) {
                #Since this is a one-time task, we can just remove it
                return $this->delete();
            }
            #Determine new time
            $time = $timestamp ?? $this->updateNextRun($result);
            #Actually reschedule. One task time task will be rescheduled for the retry time from settings
            try {
                Agent::$dbController->query('UPDATE `'.Agent::dbPrefix.'schedule` SET `status`=0, `runby`=NULL, `sse`=0, `nextrun`=:time, `'.($result === true ? 'lastsuccess' : 'lasterror').'`=CURRENT_TIMESTAMP() WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance;', [
                    ':time' => [$time, 'datetime'],
                    ':task' => [$this->taskName, 'string'],
                    ':arguments' => [$this->arguments, 'string'],
                    ':instance' => [$this->instance, 'int'],
                ]);
            } catch (\Throwable $e) {
                Agent::log('Failed to reschedule task instance for '.SandClock::format($time, 'c').'.', 'RescheduleFail', error: $e, task: $this);
            }
            #Log only if something was actually changed
            if (Agent::$dbController->getResult() > 0) {
                Agent::log('Task instance rescheduled for '.SandClock::format($time, 'c').'.', 'Reschedule', task: $this);
            }
            return $result;
        }
        return false;
    }
    
    /**
     * Run the function based on the task details
     *
     * @return bool
     * @throws \JsonException|\Exception
     */
    public function run(): bool
    {
        if ($this->foundInDB === false) {
            #Check if this is called from Agent class
            $caller = debug_backtrace();
            if (empty($caller[1]) || $caller[1]['function'] !== 'runTask' || $caller[1]['class'] !== 'Simbiat\\Cron\\Agent') {
                #Not called from Agent class, so assume that instance is not properly initiated, thus we need to throw an error
                throw new \UnexpectedValueException('Not found in database.');
            }
            #Call is from Agent class, so assume that it was a one-time job, that has already been run and removed
            return true;
        }
        if ($this->nextTime !== SandClock::suggestNextDay($this->nextTime,
                (!empty($this->dayofweek) ? json_decode($this->dayofweek, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY) : []),
                (!empty($this->dayofmonth) ? json_decode($this->dayofmonth, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY) : []))
        ) {
            #Register error.
            Agent::log('Attempted to run function during forbidden day of week or day of month.', 'CronTaskFail', task: $this);
            $this->reSchedule(false);
            return false;
        }
        #Set time limit for the task
        set_time_limit($this->taskObject->maxTime);
        #Update last run
        Agent::$dbController->query('UPDATE `'.Agent::dbPrefix.'schedule` SET `status`=2, `lastrun` = CURRENT_TIMESTAMP() WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance;', [
            ':task' => [$this->taskName, 'string'],
            ':arguments' => [$this->arguments, 'string'],
            ':instance' => [$this->instance, 'int'],
        ]);
        #Decode allowed returns if any
        if (!empty($this->taskObject->returns)) {
            $allowedreturns = json_decode($this->taskObject->returns, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY);
        }
        try {
            $function = $this->functionCreation();
            #Run function
            if (empty($this->arguments)) {
                $result = $function();
            } else {
                #Replace instance reference
                $arguments = str_replace('"$cronInstance"', (string)$this->instance, $this->arguments);
                $result = \call_user_func_array($function, json_decode($arguments, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY));
            }
        } catch (\Throwable $e) {
            $result = $e->getMessage()."\r\n".$e->getTraceAsString();
        }
        #Validate result
        if ($result !== true) {
            #Check if it's an allowed return value
            if (!empty($allowedreturns)) {
                if (in_array($result, $allowedreturns, true) === true) {
                    #Override the value
                    $result = true;
                } else {
                    Agent::log('Unexpected return `'.json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION).'`.', 'CronTaskFail', task: $this);
                    $result = false;
                }
            } elseif ($result !== false) {
                Agent::log('Unexpected return `'.json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION).'`.', 'CronTaskFail', task: $this);
                $result = false;
            }
        }
        #Reschedule
        $this->reSchedule($result);
        #Return
        return $result;
    }
    
    /**
     * Create function to run
     * @return string|array
     * @throws \JsonException
     */
    private function functionCreation(): string|array
    {
        $object = null;
        #Check if object is required
        if (!empty($this->taskObject->object)) {
            #Check if parameters for the object are set
            if (!empty($this->taskObject->parameters)) {
                $parameters = json_decode($this->taskObject->parameters, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY);
                #Check if extra methods are set
                if (!empty($parameters['extramethods'])) {
                    #Separate extra methods
                    $extramethods = $parameters['extramethods'];
                    #Remove them from original
                    unset($parameters['extramethods']);
                }
            } else {
                $parameters = null;
            }
            #Generate object
            if (empty($parameters)) {
                $object = (new $this->taskObject->object());
            } else {
                $object = (new $this->taskObject->object(...$parameters));
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
        if ($object === null) {
            $function = $this->taskObject->function;
        } else {
            $function = [$object, $this->taskObject->function];
        }
        #Check if callable
        if (!\is_callable($function)) {
            throw new \RuntimeException('Function is not callable');
        }
        return $function;
    }
    
    /**
     * Calculate time for the next run
     *
     * @param bool $result Flag to determine if we are determining new time for a successful job (`true`, default) or failed one
     *
     * @return int
     * @throws \JsonException
     */
    public function updateNextRun(bool $result = true): int
    {
        if ($this->foundInDB === false) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        $currentTime = time();
        if (empty($this->nextTime)) {
            $this->nextTime = $currentTime;
        }
        if (!$result && $this->taskObject->retry && $this->taskObject->retry > 0) {
            $newTime = $this->nextTime + $this->taskObject->retry;
        } else {
            #Determine minimum seconds to move the time by
            if ($this->frequency > 0) {
                $seconds = $this->frequency;
            } else {
                $seconds = Agent::$retry;
            }
            #Determine time difference between current time and run time, that was initially set
            $timeDiff = $currentTime - $this->nextTime;
            #Determine how many runs (based on frequency) could have happened within the time difference, essentially to "skip" over the missed runs
            $possibleRuns = (int)ceil($timeDiff / $seconds);
            #Increase time value by
            $newTime = $this->nextTime + (max($possibleRuns, 1) * $seconds);
        }
        if (empty($this->dayofmonth) && empty($this->dayofweek)) {
            return $newTime;
        }
        #Check if new time will satisfy day of week/month requirements
        return SandClock::suggestNextDay($newTime,
            (!empty($this->dayofweek) ? json_decode($this->dayofweek, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY) : []),
            (!empty($this->dayofmonth) ? json_decode($this->dayofmonth, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY) : []));
    }
}
