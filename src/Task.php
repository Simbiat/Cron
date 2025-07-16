<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use Simbiat\Database\Query;
use function is_string, is_array;

/**
 * Cron task object
 */
class Task
{
    use TraitForCron;
    
    /**
     * @var string Unique name of the task
     */
    private(set) string $task_name = '';
    /**
     * @var string Name of the task
     */
    private(set) string $function = '';
    /**
     * @var string|null Optional object reference
     */
    private(set) ?string $object = null;
    /**
     * @var string|null Parameters to set for the object (passed to construct)
     */
    private(set) ?string $parameters = '' {
        set (mixed $value) {
            if (empty($value)) {
                $this->parameters = null;
            } elseif (is_array($value)) {
                $this->parameters = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } elseif (is_string($value) && json_validate($value)) {
                $this->parameters = $value;
            } else {
                throw new \UnexpectedValueException('`parameters` is not an array or a valid JSON string');
            }
        }
    }
    /**
     * @var string|null Expected (and allowed) return values
     */
    private(set) ?string $returns = '' {
        set (mixed $value) {
            if (empty($value)) {
                $this->returns = null;
            } elseif (is_array($value)) {
                $this->returns = json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } elseif (is_string($value) && json_validate($value)) {
                $this->returns = $value;
            } else {
                throw new \UnexpectedValueException('`returns` is not an array or a valid JSON string');
            }
        }
    }
    /**
     * @var int Maximum execution time
     */
    private(set) int $max_time = 3600 {
        set (mixed $value) {
            if (empty($value)) {
                $this->max_time = 0;
            } elseif (is_numeric($value)) {
                $this->max_time = (int)$value;
                if ($this->max_time < 0) {
                    $this->max_time = 0;
                }
            } else {
                throw new \UnexpectedValueException('`max_time` is not a valid numeric value');
            }
        }
    }
    /**
     * @var int Minimal-allowed frequency (in seconds) at which a task instance can run. Does not apply to one-time jobs.
     */
    private(set) int $min_frequency = 3600 {
        set (mixed $value) {
            if (empty($value)) {
                $this->min_frequency = 0;
            } elseif (is_numeric($value)) {
                $this->min_frequency = (int)$value;
                if ($this->min_frequency < 0) {
                    $this->min_frequency = 0;
                }
            } else {
                throw new \UnexpectedValueException('`min_frequency` is not a valid numeric value');
            }
        }
    }
    /**
     * @var int Custom number of seconds to reschedule a failed task instance for. 0 disables the functionality.
     */
    private(set) int $retry = 3600 {
        set (mixed $value) {
            if (empty($value)) {
                $this->retry = 0;
            } elseif (is_numeric($value)) {
                $this->retry = (int)$value;
                if ($this->retry < 0) {
                    $this->retry = 0;
                }
            } else {
                throw new \UnexpectedValueException('`retry` is not a valid numeric value');
            }
        }
    }
    /**
     * @var bool Whether a task is system one or not
     */
    private(set) bool $system = false;
    /**
     * @var bool Whether a task (and its task instances) is enabled
     */
    private(set) bool $enabled = true;
    /**
     * @var string|null Description of the task
     */
    private(set) ?string $description = null;
    /**
     * @var bool Whether a task was found in a database
     */
    private(set) bool $found_in_db = false;
    
    /**
     * Create a Cron task object
     *
     * @param string    $task_name If the name is not empty, settings will be attempts to be loaded from the database
     * @param \PDO|null $dbh       PDO object to use for database connection. If not provided, the class expects that the connection has already been established through `\Simbiat\Cron\Agent`.
     * @param string    $prefix    Cron database prefix.
     */
    public function __construct(string $task_name = '', \PDO|null $dbh = null, string $prefix = 'cron__')
    {
        $this->init($dbh, $prefix);
        if (!empty($task_name)) {
            $this->task_name = $task_name;
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
        $settings = Query::query('SELECT * FROM `'.$this->prefix.'tasks` WHERE `task`=:name;', [':name' => $this->task_name], return: 'row');
        if (!empty($settings)) {
            $this->settingsFromArray($settings);
            if (empty($this->function)) {
                throw new \UnexpectedValueException('Task has no assigned function');
            }
            $this->found_in_db = true;
        }
    }
    
    /**
     * Set task settings from associative array
     *
     * @return $this
     */
    public function settingsFromArray(array $settings): self
    {
        foreach ($settings as $setting => $value) {
            switch ($setting) {
                case 'object':
                    if (empty($value)) {
                        $this->object = null;
                    } else {
                        $this->object = $value;
                    }
                    break;
                case 'allowed_returns':
                    $this->returns = $value;
                    break;
                case 'task':
                    $this->task_name = $value;
                    break;
                case 'function':
                case 'parameters':
                case 'max_time':
                case 'min_frequency':
                case 'retry':
                    $this->{$setting} = $value;
                    break;
                case 'enabled':
                case 'system':
                    $this->{$setting} = (bool)$value;
                    break;
                case 'description':
                    if (empty($value)) {
                        $this->description = null;
                    } else {
                        $this->description = $value;
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
     * Add (or update) the task
     *
     * @return bool
     */
    public function add(): bool
    {
        if (empty($this->task_name)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        if (empty($this->function)) {
            throw new \UnexpectedValueException('Function name is not set');
        }
        try {
            $task_details_string = json_encode($this, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $result = Query::query('INSERT INTO `'.$this->prefix.'tasks` (`task`, `function`, `object`, `parameters`, `allowed_returns`, `max_time`, `min_frequency`, `retry`, `enabled`, `system`, `description`) VALUES (:task, :function, :object, :parameters, :returns, :max_time, :min_frequency, :retry, :enabled, :system, :desc) ON DUPLICATE KEY UPDATE `function`=:function, `object`=:object, `parameters`=:parameters, `allowed_returns`=:returns, `max_time`=:max_time, `min_frequency`=:min_frequency, `retry`=:retry, `description`=:desc;', [
                ':task' => [$this->task_name, 'string'],
                ':function' => [$this->function, 'string'],
                ':object' => [$this->object, (empty($this->object) ? 'null' : 'string')],
                ':parameters' => [$this->parameters, (empty($this->parameters) ? 'null' : 'string')],
                ':returns' => [$this->returns, (empty($this->returns) ? 'null' : 'string')],
                ':max_time' => [$this->max_time, 'int'],
                ':min_frequency' => [$this->min_frequency, 'int'],
                ':retry' => [$this->retry, 'int'],
                ':enabled' => [$this->enabled, 'bool'],
                ':system' => [$this->system, 'bool'],
                ':desc' => [$this->description, (empty($this->description) ? 'null' : 'string')],
            ], return: 'affected');
            $this->found_in_db = true;
        } catch (\Throwable $e) {
            $this->log('Failed to add or update task with following details: '.$task_details_string.'.', 'TaskAddFail', error: $e);
            return false;
        }
        #Log only if something was actually changed
        if ($result > 0) {
            $this->log('Added or updated task with following details: '.$task_details_string.'.', 'TaskAdd');
        }
        return true;
    }
    
    /**
     * Delete the task if it's not a system one
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (empty($this->task_name)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        try {
            $result = Query::query('DELETE FROM `'.$this->prefix.'tasks` WHERE `task`=:task AND `system`=0;', [
                ':task' => [$this->task_name, 'string'],
            ], return: 'affected');
        } catch (\Throwable $e) {
            $this->log('Failed delete task `'.$this->task_name.'`.', 'TaskDeleteFail', error: $e);
            return false;
        }
        #Log only if something was actually deleted
        if ($result > 0) {
            $this->found_in_db = false;
            try {
                #Try to delete the respective task instances as well
                Query::query('DELETE FROM `'.$this->prefix.'schedule` WHERE `task`=:task AND `system`=0;', [
                    ':task' => [$this->task_name, 'string'],
                ]);
            } catch (\Throwable) {
                #Do nothing, not critical
            }
            $this->log('Deleted task  `'.$this->task_name.'`.', 'TaskDelete');
        }
        return true;
    }
    
    /**
     * Set the task as a system one
     * @return bool
     */
    public function setSystem(): bool
    {
        if (empty($this->task_name)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        if ($this->found_in_db) {
            try {
                $result = Query::query('UPDATE `'.$this->prefix.'tasks` SET `system`=1 WHERE `task`=:task AND `system`=0;', [
                    ':task' => [$this->task_name, 'string'],
                ], return: 'affected');
            } catch (\Throwable $e) {
                $this->log('Failed to mark task `'.$this->task_name.'` as system one.', 'TaskToSystemFail', error: $e);
                return false;
            }
            #Log only if something was actually changed
            if ($result > 0) {
                $this->system = true;
                $this->log('Marked task `'.$this->task_name.'` as system one.', 'TaskToSystem');
            }
            return true;
        }
        return false;
    }
    
    /**
     * Function to enable or disable the task and its instances
     * @param bool $enabled Flag indicating whether we want to enable or disable the task
     *
     * @return bool
     */
    public function setEnabled(bool $enabled = true): bool
    {
        if (empty($this->task_name)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        if ($this->found_in_db) {
            try {
                $result = Query::query('UPDATE `'.$this->prefix.'tasks` SET `enabled`=:enabled WHERE `task`=:task;', [
                    ':task' => [$this->task_name, 'string'],
                    ':enabled' => [$enabled, 'bool'],
                ], return: 'affected');
            } catch (\Throwable $e) {
                $this->log('Failed to '.($enabled ? 'enable' : 'disable').' task.', 'Task'.($enabled ? 'Enable' : 'Disable').'Fail', error: $e);
                return false;
            }
            #Log only if something was actually changed
            if ($result > 0) {
                $this->enabled = $enabled;
                $this->log(($enabled ? 'Enabled' : 'Disabled').' task.', 'Task'.($enabled ? 'Enable' : 'Disable'));
            }
            return true;
        }
        return false;
    }
}
