<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use function is_string, is_array;

/**
 * Cron task object
 */
class Task
{
    /**
     * @var string Unique name of the task
     */
    public string $taskName = '';
    /**
     * @var string Name of the task
     */
    public string $function = '';
    /**
     * @var string|null Optional object reference
     */
    public ?string $object = null;
    /**
     * @var string|null Parameters to set for the object (passed to construct)
     */
    public ?string $parameters = '';
    /**
     * @var string|null Expected (and allowed) return values
     */
    public ?string $returns = '';
    /**
     * @var int Maximum execution time
     */
    public int $maxTime = 3600;
    /**
     * @var int Minimal allowed frequency (in seconds) at which a task instance can run. Does not apply to one-time jobs.
     */
    public int $minFrequency = 3600;
    /**
     * @var int Custom number of seconds to reschedule a failed task instance for. 0 disables the functionality.
     */
    public int $retry = 3600;
    /**
     * @var bool Whether task is system one or not
     */
    public bool $system = false;
    /**
     * @var string|null Description of the task
     */
    public ?string $description = null;
    /**
     * @var bool Whether task was found in database
     */
    public bool $foundInDB = false;
    
    /**
     * Create a Cron task object
     *
     * @param string $taskName If name is not empty, settings will be attempts to be loaded from database
     *
     * @throws \JsonException
     */
    public function __construct(string $taskName = '')
    {
        #Ensure that Cron management is created to establish DB connection and settings
        new Agent();
        if (!empty($taskName) && Agent::$dbReady) {
            $this->taskName = $taskName;
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
            $settings = Agent::$dbController->selectRow('SELECT * FROM `'.Agent::dbPrefix.'tasks` WHERE `task`=:name;', [':name' => $this->taskName]);
            if (!empty($settings)) {
                $this->settingsFromArray($settings);
                if (empty($this->function)) {
                    throw new \UnexpectedValueException('Task has no assigned function');
                }
                $this->foundInDB = true;
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
        foreach ($settings as $setting => $value) {
            switch ($setting) {
                case 'task':
                    $this->taskName = $value;
                    break;
                case 'function':
                    $this->function = $value;
                    break;
                case 'object':
                    if (empty($value)) {
                        $this->object = null;
                    } else {
                        $this->object = $value;
                    }
                    break;
                case 'parameters':
                    $this->setParameters($value);
                    break;
                case 'allowedreturns':
                    $this->setAllowedReturns($value);
                    break;
                case 'maxTime':
                    $this->setMaxTime($value);
                    break;
                case 'minFrequency':
                    $this->setMinFrequency($value);
                    break;
                case 'retry':
                    $this->setRetry($value);
                    break;
                case 'system':
                    $this->system = (bool)$value;
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
     * Set task parameters
     * @param mixed $value
     *
     * @return void
     * @throws \JsonException
     */
    private function setParameters(mixed $value): void
    {
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
    
    /**
     * Set task's allowed return values
     * @param mixed $value
     *
     * @return void
     * @throws \JsonException
     */
    private function setAllowedReturns(mixed $value): void
    {
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
    
    /**
     * Set maximum time the task is allowed to run
     * @param mixed $value
     *
     * @return void
     */
    private function setMaxTime(mixed $value): void
    {
        if (empty($value)) {
            $this->maxTime = 0;
        } elseif (is_numeric($value)) {
            $this->maxTime = (int)$value;
            if ($this->maxTime < 0) {
                $this->maxTime = 0;
            }
        } else {
            throw new \UnexpectedValueException('`maxTime` is not a valid numeric value');
        }
    }
    
    /**
     * Set minimum frequency allowed for task instances
     * @param mixed $value
     *
     * @return void
     */
    private function setMinFrequency(mixed $value): void
    {
        if (empty($value)) {
            $this->minFrequency = 0;
        } elseif (is_numeric($value)) {
            $this->minFrequency = (int)$value;
            if ($this->minFrequency < 0) {
                $this->minFrequency = 0;
            }
        } else {
            throw new \UnexpectedValueException('`minFrequency` is not a valid numeric value');
        }
    }
    
    /**
     * Set custom number of seconds to reschedule a failed task instance for. 0 disables the functionality.
     * @param mixed $value
     *
     * @return void
     */
    private function setRetry(mixed $value): void
    {
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
    
    /**
     * Add (or update) task
     *
     * @return bool
     */
    public function add(): bool
    {
        if (empty($this->taskName)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        if (empty($this->function)) {
            throw new \UnexpectedValueException('Function name is not set');
        }
        if (Agent::$dbReady) {
            return Agent::$dbController->query('INSERT INTO `'.Agent::dbPrefix.'tasks` (`task`, `function`, `object`, `parameters`, `allowedreturns`, `maxTime`, `minFrequency`, `retry`, `system`, `description`) VALUES (:task, :function, :object, :parameters, :returns, :maxTime, :minFrequency, :retry, :system, :desc) ON DUPLICATE KEY UPDATE `function`=:function, `object`=:object, `parameters`=:parameters, `allowedreturns`=:returns, `maxTime`=:maxTime, `minFrequency`=:minFrequency, `retry`=:retry, `system`=:system, `description`=:desc;', [
                ':task' => [$this->taskName, 'string'],
                ':function' => [$this->function, 'string'],
                ':object' => [$this->object, (empty($this->object) ? 'null' : 'string')],
                ':parameters' => [$this->parameters, (empty($this->parameters) ? 'null' : 'string')],
                ':returns' => [$this->returns, (empty($this->returns) ? 'null' : 'string')],
                ':maxTime' => [$this->maxTime, 'int'],
                ':minFrequency' => [$this->minFrequency, 'int'],
                ':retry' => [$this->retry, 'int'],
                ':system' => [$this->system, 'bool'],
                ':desc' => [$this->description, (empty($this->description) ? 'null' : 'string')],
            ]);
        }
        return false;
    }
    
    /**
     * Delete task, if it's not a system one
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (empty($this->taskName)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        if (Agent::$dbReady) {
            try {
                return Agent::$dbController->query('DELETE FROM `'.Agent::dbPrefix.'tasks` WHERE `task`=:task AND `system`=0;', [
                    ':task' => [$this->taskName, 'string'],
                ]);
            } catch (\Throwable) {
                return false;
            }
        } else {
            return false;
        }
    }
    
    /**
     * Set the task as system
     * @return bool
     */
    public function setSystem(): bool
    {
        if (empty($this->taskName)) {
            throw new \UnexpectedValueException('Task name is not set');
        }
        if (Agent::$dbReady && $this->foundInDB) {
            return Agent::$dbController->query('UPDATE `'.Agent::dbPrefix.'tasks` SET `system`=1 WHERE `task`=:task AND `system`=0;', [
                ':task' => [$this->taskName, 'string'],
            ]);
        }
        return false;
    }
}
