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
    private(set) string $task_name = '';
    /**
     * @var string Optional arguments
     */
    private(set) string $arguments = '' {
        /**
         * @noinspection PhpMethodNamingConventionInspection https://youtrack.jetbrains.com/issue/WI-81560
         */
        set (mixed $value) {
            /** @noinspection IsEmptyFunctionUsageInspection We do not know what values to expect here, so this should be fine as a universal solution */
            if (empty($value)) {
                $this->arguments = '';
            } elseif (is_array($value)) {
                try {
                    $this->arguments = \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
                } catch (\Throwable) {
                    $this->arguments = '';
                }
            } elseif (is_string($value) && \json_validate($value)) {
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
        /**
         * @noinspection PhpMethodNamingConventionInspection https://youtrack.jetbrains.com/issue/WI-81560
         * @noinspection PhpUnusedParameterInspection https://youtrack.jetbrains.com/issue/WI-81990
         */
        set (int $value) {
            $this->instance = $value;
            if ($this->instance < 1) {
                $this->instance = 1;
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
        /**
         * @noinspection PhpMethodNamingConventionInspection https://youtrack.jetbrains.com/issue/WI-81560
         */
        set {
            $frequency = $value;
            if ($frequency < 0) {
                $frequency = 0;
            }
            if ($frequency > 0 && $frequency < $this->task_object->min_frequency) {
                throw new \UnexpectedValueException('`frequency` for `'.$this->task_name.'` should be either 0 (one-time job) or equal or more than '.$this->task_object->min_frequency.' seconds');
            }
            if ($frequency === 0 && $this->system) {
                throw new \UnexpectedValueException('`frequency` cannot be set to 0 (one-time job), if task instance is system one');
            }
            $this->frequency = $frequency;
        }
    }
    /**
     * @var string|null Day of month limitation
     */
    private(set) ?string $day_of_month = null {
        /**
         * @noinspection PhpMethodNamingConventionInspection https://youtrack.jetbrains.com/issue/WI-81560
         */
        set (mixed $value) {
            /** @noinspection IsEmptyFunctionUsageInspection We do not know what values to expect here, so this should be fine as a universal solution */
            if (empty($value)) {
                $this->day_of_month = null;
            } elseif (is_array($value)) {
                try {
                    $this->day_of_month = \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
                } catch (\Throwable) {
                    $this->day_of_month = null;
                }
            } elseif (is_string($value) && \json_validate($value)) {
                $this->day_of_month = $value;
            } else {
                throw new \UnexpectedValueException('`day_of_month` is not an array or a valid JSON string');
            }
        }
    }
    /**
     * @var string|null Day of week limitation
     */
    private(set) ?string $day_of_week = null {
        /**
         * @noinspection PhpMethodNamingConventionInspection https://youtrack.jetbrains.com/issue/WI-81560
         */
        set (mixed $value) {
            /** @noinspection IsEmptyFunctionUsageInspection We do not know what values to expect here, so this should be fine as a universal solution */
            if (empty($value)) {
                $this->day_of_week = null;
            } elseif (is_array($value)) {
                try {
                    $this->day_of_week = \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
                } catch (\Throwable) {
                    $this->day_of_week = null;
                }
            } elseif (is_string($value) && \json_validate($value)) {
                $this->day_of_week = $value;
            } else {
                throw new \UnexpectedValueException('`day_of_week` is not an array or a valid JSON string');
            }
        }
    }
    /**
     * @var int Task instance priority
     */
    private(set) int $priority = 0 {
        /**
         * @noinspection PhpMethodNamingConventionInspection https://youtrack.jetbrains.com/issue/WI-81560
         */
        set {
            $this->priority = $value;
            if ($this->priority < 0) {
                $this->priority = 0;
            } elseif ($this->priority > 255) {
                $this->priority = 255;
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
    private(set) ?\DateTimeImmutable $next_time = null;
    /**
     * @var bool Whether the task was found in the database
     */
    private(set) bool $found_in_db = false;
    /**
     * @var Task|null Task object
     */
    private(set) ?Task $task_object = null;
    
    
    /**
     * @param string            $task_name Task name
     * @param string|array|null $arguments Arguments for the task
     * @param int               $instance  Task instance number
     * @param \PDO|null         $dbh       PDO object to use for database connection. If not provided class expects that connection has already been established through `\Simbiat\Cron\Agent`.
     * @param string            $prefix    Cron database prefix.
     *
     */
    public function __construct(string $task_name = '', string|array|null $arguments = null, int $instance = 1, \PDO|null $dbh = null, string $prefix = 'cron__')
    {
        $this->init($dbh, $prefix);
        if (\preg_match('/^\s*$/u', $task_name) === 0) {
            $this->task_name = $task_name;
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
                ':name' => $this->task_name,
                ':arguments' => $this->arguments,
                ':instance' => [$this->instance, 'int'],
            ], return: 'row'
        );
        if (\count($settings) > 0) {
            #Set `run_by` value, if present
            if (!empty($settings['run_by'])) {
                $this->run_by = $settings['run_by'];
            }
            #Status is not allowed to be changed from outside, so `settingsFromArray` does not handle it, but we do update it in the class itself
            $this->status = $settings['status'];
            unset($settings['status']);
            #Get task object
            $this->task_object = (new Task($this->task_name));
            #Process settings
            $this->settingsFromArray($settings);
            #If nothing failed at this point, set the flag to `true`
            $this->found_in_db = $this->task_object->found_in_db;
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
        if ($this->task_object === null && !empty($settings['task'])) {
            $this->task_object = (new Task($settings['task']));
        }
        #We need to process system status first, since frequency depends on it
        if (\array_key_exists('system', $settings)) {
            $this->system = (bool)$settings['system'];
        }
        foreach ($settings as $setting => $value) {
            switch ($setting) {
                case 'task':
                    $this->task_name = $value;
                    break;
                case 'arguments':
                case 'instance':
                case 'frequency':
                case 'priority':
                case 'day_of_month':
                case 'day_of_week':
                    $this->{$setting} = $value;
                    break;
                case 'enabled':
                    $this->enabled = (bool)$value;
                    break;
                case 'next_run':
                    $this->next_time = SandClock::valueToDateTime($value);
                    break;
                case 'message':
                    if (!is_string($value) || \preg_match('/^\s*$/u', $value) !== 0) {
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
        if (\preg_match('/^\s*$/u', $this->task_name) !== 0) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        try {
            $result = Query::query('INSERT INTO `'.$this->prefix.'schedule` (`task`, `arguments`, `instance`, `enabled`, `system`, `frequency`, `day_of_month`, `day_of_week`, `priority`, `message`, `next_run`) VALUES (:task, :arguments, :instance, :enabled, :system, :frequency, :day_of_month, :day_of_week, :priority, :message, :next_run) ON DUPLICATE KEY UPDATE `frequency`=:frequency, `day_of_month`=:day_of_month, `day_of_week`=:day_of_week, `next_run`=IF(:frequency=0, `next_run`, :next_run), `priority`=IF(:frequency=0, IF(`priority`>:priority, `priority`, :priority), :priority), `message`=:message, `updated`=CURRENT_TIMESTAMP(6);', [
                ':task' => [$this->task_name, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
                ':enabled' => [$this->enabled, 'enabled'],
                ':system' => [$this->system, 'bool'],
                ':frequency' => [$this->frequency, 'int'],
                ':day_of_month' => [$this->day_of_month, (\preg_match('/^\s*$/u', $this->day_of_month ?? '') !== 0 ? 'null' : 'string')],
                ':day_of_week' => [$this->day_of_week, (\preg_match('/^\s*$/u', $this->day_of_week ?? '') !== 0 ? 'null' : 'string')],
                ':priority' => [$this->priority, 'int'],
                ':message' => [$this->message, (\preg_match('/^\s*$/u', $this->message ?? '') !== 0 ? 'null' : 'string')],
                ':next_run' => [$this->next_time, 'datetime'],
            ], return: 'affected');
            $this->found_in_db = true;
        } catch (\Throwable $throwable) {
            $this->log('Failed to add or update task instance.', 'InstanceAddFail', error: $throwable, task: $this);
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
        if (\preg_match('/^\s*$/u', $this->task_name) !== 0) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        try {
            $result = Query::query('DELETE FROM `'.$this->prefix.'schedule` WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `system`=0;', [
                ':task' => [$this->task_name, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
            ], return: 'affected');
        } catch (\Throwable $first) {
            $this->log('Failed to delete task instance.', 'InstanceDeleteFail', error: $first, task: $this);
            try {
                $result = Query::query('UPDATE `'.$this->prefix.'schedule` SET `status` = 3 WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `system`=0;', [
                    ':task' => [$this->task_name, 'string'],
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
            $this->found_in_db = false;
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
        if (!$this->found_in_db) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        if ($this->frequency === 0) {
            throw new \UnexpectedValueException('One-time job cannot be made system one');
        }
        try {
            $result = Query::query('UPDATE `'.$this->prefix.'schedule` SET `system`=1 WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `system`=0;', [
                ':task' => [$this->task_name, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
            ], return: 'affected');
        } catch (\Throwable $throwable) {
            $this->log('Failed to mark task instance as system one.', 'InstanceToSystemFail', error: $throwable, task: $this);
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
        if (!$this->found_in_db) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        try {
            $result = Query::query('UPDATE `'.$this->prefix.'schedule` SET `enabled`=:enabled WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance;', [
                ':task' => [$this->task_name, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
                ':enabled' => [$enabled, 'bool'],
            ], return: 'affected');
        } catch (\Throwable $throwable) {
            $this->log('Failed to '.($enabled ? 'enable' : 'disable').' task instance.', 'Instance'.($enabled ? 'Enable' : 'Disable').'Fail', error: $throwable, task: $this);
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
     * @param bool|string                                        $result    Whether a task was successful
     * @param string|float|int|\DateTime|\DateTimeImmutable|null $timestamp Optional timestamp to use
     *
     * @return bool
     */
    public function reSchedule(bool|string $result = true, string|float|int|\DateTime|\DateTimeImmutable|null $timestamp = null): bool
    {
        if (!$this->found_in_db) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        #Check whether this is a successful one-time job
        if ($this->frequency === 0 && $result) {
            #Since this is a one-time task, we can just remove it
            return $this->delete();
        }
        #Determine a new time
        /** @noinspection IsEmptyFunctionUsageInspection Valid scenario due to multiple possible types used for the variable */
        if (empty($timestamp)) {
            $time = $this->updateNextRun($result);
        } else {
            $time = SandClock::valueToDateTime($timestamp);
        }
        #Actually reschedule. One task time task will be rescheduled for the retry time from settings
        try {
            if ($result === true) {
                $query = /** @lang SQL */
                    'UPDATE `'.$this->prefix.'schedule` SET `status`=0, `run_by`=NULL, `sse`=0, `next_run`=:time, `last_success`=CURRENT_TIMESTAMP(6), `success_total`=`success_total`+1, `success_streak`=`success_streak`+1, `error_streak`=0 WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance;';
            } else {
                #If `last_run` is NULL, then the job was not tried to be run, and probably is being rescheduled due to some issue not related to this specific instance
                $query = /** @lang SQL */
                    'UPDATE `'.$this->prefix.'schedule` SET `status`=0, `run_by`=NULL, `sse`=0, `next_run`=:time, `last_error`=IF(`last_run` IS NULL, NULL, CURRENT_TIMESTAMP(6)), `error_total`=`error_total`+1, `error_streak`=`error_streak`+1, `success_streak`=0, `last_error_message`=:error_text WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance;';
            }
            $affected = Query::query($query, [
                ':time' => [$time, 'datetime'],
                ':task' => [$this->task_name, 'string'],
                ':arguments' => [$this->arguments, 'string'],
                ':instance' => [$this->instance, 'int'],
                ':error_text' => [\is_bool($result) ? null : $result, \is_bool($result) ? 'null' : 'string']
            ], return: 'affected');
        } catch (\Throwable $throwable) {
            $this->log('Failed to reschedule task instance for '.SandClock::format($time, 'c').'.', 'RescheduleFail', error: $throwable, task: $this);
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
        #If run_by value is empty (a job is being run manually) - generate it
        if (\preg_match('/^\s*$/u', $this->run_by ?? '') !== 0) {
            $this->run_by = $this->generateRunBy();
        }
        if (!$this->found_in_db) {
            #Assume that it was a [one-time job], that has already been run and removed by another (possibly manual) process
            return true;
        }
        if ($this->next_time !== SandClock::suggestNextDay($this->next_time,
                (\preg_match('/^\s*$/u', $this->day_of_week ?? '') === 0 ? \json_decode($this->day_of_week, flags: \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_BIGINT_AS_STRING | \JSON_OBJECT_AS_ARRAY) : []),
                (\preg_match('/^\s*$/u', $this->day_of_month ?? '') === 0 ? \json_decode($this->day_of_month, flags: \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_BIGINT_AS_STRING | \JSON_OBJECT_AS_ARRAY) : []))
        ) {
            #Register error.
            $this->log('Attempted to run function during forbidden day of week or day of month.', 'InstanceFail', task: $this);
            $this->reSchedule(false);
            return false;
        }
        #Set the time limit for the task
        \set_time_limit($this->task_object->max_time);
        #Update last run
        $affected = Query::query('UPDATE `'.$this->prefix.'schedule` SET `status`=2, `run_by`=:run_by, `last_run` = CURRENT_TIMESTAMP(6) WHERE `task`=:task AND `arguments`=:arguments AND `instance`=:instance AND `status` IN (0, 1);', [
            ':task' => [$this->task_name, 'string'],
            ':arguments' => [$this->arguments, 'string'],
            ':instance' => [$this->instance, 'int'],
            ':run_by' => [$this->run_by, 'string'],
        ], return: 'affected');
        if ($affected <= 0) {
            #The task was either picked up by some manual process or has been removed
            return true;
        }
        #Decode allowed returns if any
        if (\preg_match('/^\s*$/u', $this->task_object->returns ?? '') === 0) {
            $allowed_returns = \json_decode($this->task_object->returns, flags: \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_BIGINT_AS_STRING | \JSON_OBJECT_AS_ARRAY);
        }
        try {
            $function = $this->functionCreation();
            #Run function
            if (\preg_match('/^\s*$/u', $this->arguments ?? '') !== 0) {
                $result = $function();
            } else {
                #Replace instance reference
                $arguments = \str_replace('"$cron_instance"', (string)$this->instance, $this->arguments);
                $result = \call_user_func_array($function, \json_decode($arguments, flags: \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_BIGINT_AS_STRING | \JSON_OBJECT_AS_ARRAY));
            }
        } catch (\Throwable $throwable) {
            $result = $throwable->getMessage()."\r\n".$throwable->getTraceAsString();
        }
        #Check if it's an allowed return value, unless it's regular boolean
        if (!\is_bool($result)) {
            /** @noinspection IsEmptyFunctionUsageInspection Valid case, since we do not know to what the JSON got decoded here */
            if (!empty($allowed_returns) && in_array($result, $allowed_returns, true)) {
                $result = true;
            } else {
                $result = 'Unexpected return `'.\json_encode($result, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION).'`.';
                $this->log($result, 'InstanceFail', task: $this);
            }
        }
        #Reschedule
        $this->reSchedule($result);
        #Return
        if (is_string($result)) {
            return false;
        }
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
        $extra_methods = [];
        #Check if an object is required
        if (\preg_match('/^\s*$/u', $this->task_object->object ?? '') === 0) {
            #Check if parameters for the object are set
            if (\preg_match('/^\s*$/u', $this->task_object->parameters ?? '') === 0) {
                $parameters = \json_decode($this->task_object->parameters, flags: \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_BIGINT_AS_STRING | \JSON_OBJECT_AS_ARRAY);
                #Check if extra methods are set
                if (!empty($parameters['extra_methods'])) {
                    #Separate extra methods
                    $extra_methods = $parameters['extra_methods'];
                    #Remove them from the original
                    unset($parameters['extra_methods']);
                }
            } else {
                $parameters = null;
            }
            #Generate object
            if ($parameters === null || $parameters === []) {
                $object = (new $this->task_object->object());
            } else {
                $object = (new $this->task_object->object(...$parameters));
            }
            #Call the extra methods
            if ($extra_methods !== []) {
                foreach ($extra_methods as $method) {
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
            $function = $this->task_object->function;
        } else {
            $function = [$object, $this->task_object->function];
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
        if (!$this->found_in_db) {
            throw new \UnexpectedValueException('Not found in database.');
        }
        $current_time = SandClock::valueToDateTime();
        if (empty($this->next_time)) {
            $this->next_time = $current_time;
        }
        if (!$result && $this->task_object->retry && $this->task_object->retry > 0) {
            try {
                $new_time = $this->next_time->modify('+'.$this->task_object->retry.' seconds');
            } catch (\DateMalformedStringException) {
                #We should not get here, since the value is not from the user, and there are validations on earlier steps; this is just a failback
                $new_time = $current_time;
            }
        } else {
            #Determine minimum seconds to move the time by
            if ($this->frequency > 0) {
                $seconds = $this->frequency;
            } else {
                $seconds = $this->one_time_retry;
            }
            #Determine the time difference between current time and run time that was initially set
            $time_diff = $current_time->getTimestamp() - $this->next_time->getTimestamp();
            #Determine how many runs (based on frequency) could have happened within the time difference, essentially to "skip" over the missed runs
            $possible_runs = (int)\ceil($time_diff / $seconds);
            #Increase time value by
            try {
                $new_time = $this->next_time->modify('+'.(\max($possible_runs, 1) * $seconds).' seconds');
            } catch (\DateMalformedStringException) {
                #We should not get here, since the value is not from the user, and there are validations on earlier steps; this is just a failback
                $new_time = $current_time;
            }
        }
        if (\preg_match('/^\s*$/u', $this->day_of_month ?? '') !== 0 && \preg_match('/^\s*$/u', $this->day_of_week ?? '') !== 0) {
            return $new_time;
        }
        #Check if the new time will satisfy day of week/month requirements
        try {
            return SandClock::suggestNextDay($new_time,
                (\preg_match('/^\s*$/u', $this->day_of_week ?? '') === 0 ? \json_decode($this->day_of_week, flags: \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_BIGINT_AS_STRING | \JSON_OBJECT_AS_ARRAY) : []),
                (\preg_match('/^\s*$/u', $this->day_of_month ?? '') === 0 ? \json_decode($this->day_of_month, flags: \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_BIGINT_AS_STRING | \JSON_OBJECT_AS_ARRAY) : []));
        } catch (\Throwable) {
            #We should not get here, since the value is not from the user, and there are validations on earlier steps; this is just a failback
            return $current_time;
        }
    }
}
