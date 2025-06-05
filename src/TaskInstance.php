<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use Simbiat\Database\Query;
use Simbiat\SandClock;
use function is_string, is_array, in_array;

/**
 * Scheduled task instance object
 */
class TaskInstance
{
    use TraitForCron;
    
    /**
     * @var string Unique name of the task
     */
    private(set) string $taskName = '';
    /**
     * @var string Optional arguments
     */
    private(set) string $arguments = '' {
        set (mixed $value) {
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
    }
    /**
     * @var int Task instance number
     */
    private(set) int $instance = 1 {
        set (mixed $value) {
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
    }
    /**
     * @var int Task instance status. `0` means a task is not running; `1` - queued; `2` - running; `3` - to be removed (used only in case of failed removal)
     */
    private(set) int $status = 0;
    /**
     * @var bool Whether the task instance is system one or not
     */
    private(set) bool $system = false;
    /**
     * @var bool Whether the task instance is enabled
     */
    private(set) bool $enabled = true;
    /**
     * @var int Task instance frequency
     */
    private(set) int $frequency = 0 {
        set (mixed $value) {
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
    }
    /**
     * @var string|null Day of month limitation
     */
    private(set) ?string $dayOfMonth = null {
        set (mixed $value) {
            if (empty($value)) {
                $this->dayOfMonth = null;
            } elseif (is_array($value)) {
                $this->dayOfMonth = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } elseif (is_string($value) && json_validate($value)) {
                $this->dayOfMonth = $value;
            } else {
                throw new \UnexpectedValueException('`dayOfMonth` is not an array or a valid JSON string');
            }
        }
    }
    /**
     * @var string|null Day of week limitation
     */
    private(set) ?string $dayOfWeek = null {
        set (mixed $value) {
            if (empty($value)) {
                $this->dayOfWeek = null;
            } elseif (is_array($value)) {
                $this->dayOfWeek = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } elseif (is_string($value) && json_validate($value)) {
                $this->dayOfWeek = $value;
            } else {
                throw new \UnexpectedValueException('`dayOfWeek` is not an array or a valid JSON string');
            }
        }
    }
    /**
     * @var int Task instance priority
     */
    private(set) int $priority = 0 {
        set (mixed $value) {
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
    }
    /**
     * @var string|null Message to show in SSE mode
     */
    private(set) ?string $message = null;
    /**
     * @var null|\DateTimeImmutable Time of the next run
     */
    private(set) ?\DateTimeImmutable $nextTime = null;
    /**
     * @var bool Whether the task was found in the database
     */
    private(set) bool $foundInDB = false;
    /**
     * @var Task|null Task object
     */
    private(set) ?Task $taskObject = null;
    
    
    /**
     * @param string            $taskName  Task name
     * @param string|array|null $arguments Arguments for the task
     * @param int               $instance  Task instance number
     * @param \PDO|null         $dbh       PDO object to use for database connection. If not provided class expects that connection has already been established through `\Simbiat\Cron\Agent`.
     * @param string            $prefix    Cron database prefix.
     *
     */
    public function __construct(string $taskName = '', string|array|null $arguments = null, int $instance = 1, \PDO|null $dbh = null, string $prefix = 'cron__')
    {
        $this->init($dbh, $prefix);
        if (!empty($taskName)) {
            $this->taskName = $taskName;
            $this->arguments = $arguments;
            $this->instance = $instance;
            #Attempt to get settings from DB
            $this->getFromDB();
        }
    }
    
    /**
     * Get task settings from database
     *
     * @return void
     */
    private function getFromDB(): void
    {
        $settings = Query::query('SELECT * FROM `'.$this->prefix.'schedule` WHERE `task`=:name AND `arguments`=:arguments AND `instance`=:instance;',
            [
                ':name' => $this->taskName,
                ':arguments' => $this->arguments,
                ':instance' => [$this->instance, 'int'],
            ], return: 'row'
        );
        if (!empty($settings)) {
            #Set `runBy` value, if present
            if (!empty($settings['runBy'])) {
                $this->runBy = $settings['runBy'];
            }
            #Status is not allowed to be changed from outside, so `settingsFromArray` does not handle it, but we do update it in the class itself
            $this->status = $settings['status'];
            unset($settings['status']);
            #Get task object
            $this->taskObject = (new Task($this->taskName));
            #Process settings
            $this->settingsFromArray($settings);
            #If nothing failed at this point, set the flag to `true`
            $this->foundInDB = $this->taskObject->foundInDB;
        }
    }
    
    /**
     * Set task settings from associative array
     *
     * @return $this
     */
    public function settingsFromArray(array $settings): self
    {
        #If we are creating a task instance from outside the class (for example, new instance), we may not have all details, so ensure we get them
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
                case 'instance':
                case 'frequency':
                case 'priority':
                case 'dayOfMonth':
                case 'dayOfWeek':
                    $this->{$setting} = $value;
                    break;
                case 'enabled':
                    $this->enabled = (bool)$value;
                    break;
                case 'nextRun':
                    $this->nextTime = SandClock::valueToDateTime($value);
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
     * Schedule or update a task
     *
     * @return bool
     */
    public function add(): bool
    {
        if (empty($this->taskName)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        try {
            $result = Query::query('INSERT INTO `'.$this->prefix.'schedule` (`task`, `arguments`, `instance`, `enabled`, `system`, `frequency`, `dayOfMonth`, `dayOfWeek`, `priority`, `message`, `nextRun`) VALUES (:task, :arguments, :instance, :enabled, :system, :frequency, :dayOfMonth, :dayOfWeek, :priority, :message, :nextRun) ON DUPLICATE KEY UPDATE `frequency`=:frequency, `dayOfMonth`=:dayOfMonth, `dayOfWeek`=:dayOfWeek, `nextRun`=IF(:frequency=0, `nextRun`, :nextRun), `priority`=IF(:frequency=0, IF(`priority`>:priority, `priority`, :priority), :priority), `message`=:message, `updated`=CURRENT_TIMESTAMP();', [
                ':task' => [$this->taskName, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
                ':enabled' => [$this->enabled, 'enabled'],
                ':system' => [$this->system, 'bool'],
                ':frequency' => [(empty($this->frequency) ? 0 : $this->frequency), 'int'],
                ':dayOfMonth' => [$this->dayOfMonth, (empty($this->dayOfMonth) ? 'null' : 'string')],
                ':dayOfWeek' => [$this->dayOfWeek, (empty($this->dayOfWeek) ? 'null' : 'string')],
                ':priority' => [(empty($this->priority) ? 0 : $this->priority), 'int'],
                ':message' => [$this->message, (empty($this->message) ? 'null' : 'string')],
                ':nextRun' => [$this->nextTime, 'datetime'],
            ], return: 'affected');
            $this->foundInDB = true;
        } catch (\Throwable $e) {
            $this->log('Failed to add or update task instance.', 'InstanceAddFail', error: $e, task: $this);
            return false;
        }
        #Log only if something was actually changed
        if ($result > 0) {
            $this->log('Added or updated task instance.', 'InstanceAdd', task: $this);
        }
        return true;
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
        try {
            $result = Query::query('DELETE FROM `'.$this->prefix.'schedule` WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `system`=0;', [
                ':task' => [$this->taskName, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
            ], return: 'affected');
        } catch (\Throwable $first) {
            $this->log('Failed to delete task instance.', 'InstanceDeleteFail', error: $first, task: $this);
            try {
                $result = Query::query('UPDATE `'.$this->prefix.'schedule` SET `status` = 3 WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `system`=0;', [
                    ':task' => [$this->taskName, 'string'],
                    ':arguments' => [$this->arguments, 'string'],
                    ':instance' => [$this->instance, 'int'],
                ], return: 'affected');
                #Log only if something was actually deleted, and if it's not a one-time job
                if ($result > 0) {
                    $this->log('Task instance marked for removal.', 'InstanceDelete', task: $this);
                }
            } catch (\Throwable $second) {
                $this->log('Failed to mark task instance for removal.', 'InstanceDeleteFail', error: $second, task: $this);
            }
            return false;
        }
        if ($result > 0) {
            $this->foundInDB = false;
            #Log only if something was actually deleted, and if it's not a one-time job
            if ($this->frequency > 0) {
                $this->log('Deleted task instance.', 'InstanceDelete', task: $this);
            }
        }
        return true;
    }
    
    /**
     * Set the task instance as a system one
     * @return bool
     */
    public function setSystem(): bool
    {
        if (!$this->foundInDB) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        if ($this->frequency === 0) {
            throw new \UnexpectedValueException('One-time job cannot be made system one');
        }
        try {
            $result = Query::query('UPDATE `'.$this->prefix.'schedule` SET `system`=1 WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `system`=0;', [
                ':task' => [$this->taskName, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
            ], return: 'affected');
        } catch (\Throwable $e) {
            $this->log('Failed to mark task instance as system one.', 'InstanceToSystemFail', error: $e, task: $this);
            return false;
        }
        #Log only if something was actually changed
        if ($result > 0) {
            $this->system = true;
            $this->log('Marked task instance as system one.', 'InstanceToSystem', task: $this);
        }
        return true;
    }
    
    /**
     * Function to enable or disable task instance
     * @param bool $enabled Flag indicating whether we want to enable or disable the instance
     *
     * @return bool
     */
    public function setEnabled(bool $enabled = true): bool
    {
        if (!$this->foundInDB) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        try {
            $result = Query::query('UPDATE `'.$this->prefix.'schedule` SET `enabled`=:enabled WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance;', [
                ':task' => [$this->taskName, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
                ':enabled' => [$enabled, 'bool'],
            ], return: 'affected');
        } catch (\Throwable $e) {
            $this->log('Failed to '.($enabled ? 'enable' : 'disable').' task instance.', 'Instance'.($enabled ? 'Enable' : 'Disable').'Fail', error: $e, task: $this);
            return false;
        }
        #Log only if something was actually changed
        if ($result > 0) {
            $this->enabled = $enabled;
            $this->log(($enabled ? 'Enabled' : 'Disabled').' task instance.', 'Instance'.($enabled ? 'Enable' : 'Disable'), task: $this);
        }
        return true;
    }
    
    /**
     * Reschedule a task (or remove it if it's onetime)
     *
     * @param bool                                               $result    Whether a task was successful
     * @param string|float|int|\DateTime|\DateTimeImmutable|null $timestamp Optional timestamp to use
     *
     * @return bool
     */
    public function reSchedule(bool $result = true, string|float|int|\DateTime|\DateTimeImmutable|null $timestamp = null): bool
    {
        if (!$this->foundInDB) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        #Check whether this is a successful one-time job
        if ($this->frequency === 0 && $result) {
            #Since this is a one-time task, we can just remove it
            return $this->delete();
        }
        #Determine a new time
        if (empty($timestamp)) {
            $time = $this->updateNextRun($result);
        } else {
            $time = SandClock::valueToDateTime($timestamp);
        }
        #Actually reschedule. One task time task will be rescheduled for the retry time from settings
        try {
            $affected = Query::query('UPDATE `'.$this->prefix.'schedule` SET `status`=0, `runBy`=NULL, `sse`=0, `nextRun`=:time, `'.($result ? 'lastSuccess' : 'lastError').'`=CURRENT_TIMESTAMP() WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance;', [
                ':time' => [$time, 'datetime'],
                ':task' => [$this->taskName, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
            ], return: 'affected');
        } catch (\Throwable $e) {
            $this->log('Failed to reschedule task instance for '.SandClock::format($time, 'c').'.', 'RescheduleFail', error: $e, task: $this);
            return false;
        }
        #Log only if something was actually changed
        if ($affected > 0) {
            $this->log('Task instance rescheduled for '.SandClock::format($time, 'c').'.', 'Reschedule', task: $this);
        }
        return $result;
    }
    
    /**
     * Run the function based on the task details
     *
     * @return bool
     * @throws \JsonException|\Exception
     */
    public function run(): bool
    {
        #If runBy value is empty (a job is being run manually) - generate it
        if (empty($this->runBy)) {
            $this->runBy = $this->generateRunBy();
        }
        if (!$this->foundInDB) {
            #Assume that it was a [one-time job], that has already been run and removed by another (possibly manual) process
            return true;
        }
        if ($this->nextTime !== SandClock::suggestNextDay($this->nextTime,
                (!empty($this->dayOfWeek) ? json_decode($this->dayOfWeek, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY) : []),
                (!empty($this->dayOfMonth) ? json_decode($this->dayOfMonth, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY) : []))
        ) {
            #Register error.
            $this->log('Attempted to run function during forbidden day of week or day of month.', 'InstanceFail', task: $this);
            $this->reSchedule(false);
            return false;
        }
        #Set the time limit for the task
        set_time_limit($this->taskObject->maxTime);
        #Update last run
        $affected = Query::query('UPDATE `'.$this->prefix.'schedule` SET `status`=2, `runBy`=:runBy, `lastRun` = CURRENT_TIMESTAMP() WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `status` IN (0, 1);', [
            ':task' => [$this->taskName, 'string'],
            ':arguments' => [$this->arguments, 'string'],
            ':instance' => [$this->instance, 'int'],
            ':runBy' => [$this->runBy, 'string'],
        ], return: 'affected');
        if ($affected <= 0) {
            #The task was either picked up by some manual process or has been removed
            return true;
        }
        #Decode allowed returns if any
        if (!empty($this->taskObject->returns)) {
            $allowedReturns = json_decode($this->taskObject->returns, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY);
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
            if (!empty($allowedReturns)) {
                if (in_array($result, $allowedReturns, true)) {
                    #Override the value
                    $result = true;
                } else {
                    $this->log('Unexpected return `'.json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION).'`.', 'InstanceFail', task: $this);
                    $result = false;
                }
            } elseif ($result !== false) {
                $this->log('Unexpected return `'.json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION).'`.', 'InstanceFail', task: $this);
                $result = false;
            }
        }
        #Reschedule
        $this->reSchedule($result);
        #Return
        return $result;
    }
    
    /**
     * Create a function to run
     * @return string|array
     * @throws \JsonException
     */
    private function functionCreation(): string|array
    {
        $object = null;
        #Check if an object is required
        if (!empty($this->taskObject->object)) {
            #Check if parameters for the object are set
            if (!empty($this->taskObject->parameters)) {
                $parameters = json_decode($this->taskObject->parameters, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY);
                #Check if extra methods are set
                if (!empty($parameters['extraMethods'])) {
                    #Separate extra methods
                    $extraMethods = $parameters['extraMethods'];
                    #Remove them from the original
                    unset($parameters['extraMethods']);
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
            if (!empty($extraMethods)) {
                foreach ($extraMethods as $method) {
                    #Check if the method value is present, skip the method, if not
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
     * @param bool $result Flag to determine if we are determining a new time for a successful job (`true`, default) or failed one
     *
     * @return \DateTimeImmutable
     */
    public function updateNextRun(bool $result = true): \DateTimeImmutable
    {
        if (!$this->foundInDB) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        $currentTime = SandClock::valueToDateTime();
        if (empty($this->nextTime)) {
            $this->nextTime = $currentTime;
        }
        if (!$result && $this->taskObject->retry && $this->taskObject->retry > 0) {
            try {
                $newTime = $this->nextTime->modify('+'.$this->taskObject->retry.' seconds');
            } catch (\DateMalformedStringException) {
                #We should not get here, since the value is not from the user, and there are validations on earlier steps; this is just a failback
                $newTime = $currentTime;
            }
        } else {
            #Determine minimum seconds to move the time by
            if ($this->frequency > 0) {
                $seconds = $this->frequency;
            } else {
                $seconds = $this->oneTimeRetry;
            }
            #Determine the time difference between current time and run time that was initially set
            $timeDiff = $currentTime->getTimestamp() - $this->nextTime->getTimestamp();
            #Determine how many runs (based on frequency) could have happened within the time difference, essentially to "skip" over the missed runs
            $possibleRuns = (int)ceil($timeDiff / $seconds);
            #Increase time value by
            try {
                $newTime = $this->nextTime->modify('+'.(max($possibleRuns, 1) * $seconds).' seconds');
            } catch (\DateMalformedStringException) {
                #We should not get here, since the value is not from the user, and there are validations on earlier steps; this is just a failback
                $newTime = $currentTime;
            }
        }
        if (empty($this->dayOfMonth) && empty($this->dayOfWeek)) {
            return $newTime;
        }
        #Check if the new time will satisfy day of week/month requirements
        try {
            return SandClock::suggestNextDay($newTime,
                (!empty($this->dayOfWeek) ? json_decode($this->dayOfWeek, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY) : []),
                (!empty($this->dayOfMonth) ? json_decode($this->dayOfMonth, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING | JSON_OBJECT_AS_ARRAY) : []));
        } catch (\Throwable) {
            #We should not get here, since the value is not from the user, and there are validations on earlier steps; this is just a failback
            return $currentTime;
        }
    }
}
