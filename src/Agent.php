<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use JetBrains\PhpStorm\ExpectedValues;
use Simbiat\Database\Query;
use Simbiat\HTTP\SSE;
use function in_array;

/**
 * Task scheduler that uses MySQL/MariaDB database to store tasks and their schedule.
 * @noinspection ContractViolationInspection https://github.com/kalessil/phpinspectionsea/issues/1996
 */
class Agent
{
    use TraitForCron;
    
    /**
     * Supported settings
     * @var array
     */
    private const array SETTINGS = ['enabled', 'log_life', 'retry', 'sse_loop', 'sse_retry', 'max_threads'];
    
    /**
     * Class constructor
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Cron database prefix.
     */
    public function __construct(\PDO|null $dbh = null, string $prefix = 'cron__')
    {
        $this->init($dbh, $prefix);
    }
    
    /**
     * Process Cron items
     *
     * @param int $items Number of items to process
     *
     * @return bool
     * @throws \Throwable
     */
    public function process(int $items = 1): bool
    {
        #Start stream if not in CLI
        if (SSE::isPossible()) {
            SSE::open();
        }
        #Generate random ID
        $this->run_by = $this->generateRunBy();
        if (SSE::$sse) {
            $this->log('Cron processing started in SSE mode', EventTypes::SSEStart);
        }
        #Regular maintenance
        if (Query::$dbh !== null) {
            #Reschedule hanged jobs
            $this->unHang();
            #Depending on the number of events in the log, this may take a while, so use a bit of randomization to not do this on very run.
            try {
                if (\random_int(1, 60 * $this->max_threads) < 60 * ($this->max_threads - 1)) {
                    #Clean old logs
                    $this->logPurge();
                }
            } catch (\Throwable) {
                #Do nothing, not critical, since these are just logs
            }
        } else {
            #Notify about the end of the stream
            $this->log('Cron database not available', EventTypes::CronFail, true);
            return false;
        }
        #Check if cron is enabled and process only if it is
        if (!$this->cron_enabled) {
            #Notify about the end of the stream
            $this->log('Cron processing is disabled', EventTypes::CronDisabled, true);
            return false;
        }
        #Sanitize the number of items
        if ($items < 1) {
            $items = 1;
        }
        do {
            if (!$this->getCronSettings()) {
                $this->log('Failed to get CRON settings', EventTypes::CronFail, true);
                return false;
            }
            #Check if enough threads are available
            try {
                if (Query::query('SELECT COUNT(DISTINCT(`run_by`)) as `count` FROM `'.$this->prefix.'schedule` WHERE `run_by` IS NOT NULL;', return: 'count') >= $this->max_threads) {
                    $this->log('Cron threads are exhausted', EventTypes::CronNoThreads);
                    if (!SSE::$sse) {
                        return false;
                    }
                    #Sleep for a bit
                    \sleep($this->sse_retry / 20);
                    continue;
                }
            } catch (\Throwable $exception) {
                $this->log('Failed to check for available threads', EventTypes::CronFail, true, $exception);
                return false;
            }
            #Queue tasks for this random ID
            $tasks = $this->getTasks($items);
            if ($tasks === false || $tasks === []) {
                $this->log('Cron list is empty', EventTypes::CronEmpty);
                if (SSE::$sse) {
                    #Sleep for a bit
                    \sleep($this->sse_retry / 20);
                }
            } else {
                $total_tasks = \count($tasks);
                foreach ($tasks as $number => $task) {
                    $this->runTask($task, $number + 1, $total_tasks);
                }
            }
            #Additionally, reschedule hanged jobs if we're in SSE
            if (SSE::$sse && $this->sse_loop) {
                $this->unHang();
            }
        } while ($this->cron_enabled && SSE::$sse && $this->sse_loop && \connection_status() === 0);
        #Notify about the end of the stream
        if (SSE::$sse) {
            $this->log('Cron processing finished', EventTypes::SSEEnd, true);
        }
        return true;
    }
    
    /**
     * Wrapper for running the task
     * @param array $task        Task object
     * @param int   $number      Current task number
     * @param int   $total_tasks Total number of tasks
     *
     * @return void
     */
    private function runTask(array $task, int $number, int $total_tasks): void
    {
        try {
            $task_instance = (new TaskInstance($task['task'], $task['arguments'], $task['instance']));
            #Notify of the task starting
            $this->log($number.'/'.$total_tasks.' '.(empty($task['message']) ? $task['task'].' starting' : $task['message']), EventTypes::InstanceStart, task: $task_instance);
            #Attemp to run
            $result = $task_instance->run();
        } catch (\Throwable $exception) {
            $this->log('Failed to run task `'.$task['task'].'` ('.$number.'/'.$total_tasks.')', EventTypes::InstanceFail, false, $exception, ($task_instance ?? null));
            return;
        } finally {
            $this->current_task = null;
        }
        #Notify of the task finishing
        if ($result) {
            $this->log($number.'/'.$total_tasks.' '.$task['task'].' finished'.($task_instance->frequency === 0 ? ' and deleted' : ''), EventTypes::InstanceEnd, task: $task_instance);
        } else {
            $this->log($number.'/'.$total_tasks.' '.$task['task'].' failed', EventTypes::InstanceFail, task: $task_instance);
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
            Query::query('UPDATE `'.$this->prefix.'schedule` AS `to_update`
                        INNER JOIN
                        (
                            SELECT `task`, `arguments`, `instance` FROM (
                                SELECT `task`, `arguments`, `instance`, `next_run`, `priority`, `frequency`, ROW_NUMBER() OVER (PARTITION BY `task`, `arguments` ORDER BY `next_run`, `priority` DESC, (`frequency`=0) DESC, `frequency` DESC) AS `row_number` FROM (
                                    SELECT `task`, `arguments`, `instance`, `next_run`, `priority`, `frequency` FROM `'.$this->prefix.'schedule` AS `instances`
                                    WHERE `enabled`=1 AND `run_by` IS NULL AND `next_run`<=CURRENT_TIMESTAMP(6) AND EXISTS (SELECT 1 FROM `'.$this->prefix.'tasks` `tasks` WHERE `tasks`.`task`=`instances`.`task` AND `tasks`.`enabled`=1)
                                    LIMIT :inner_limit
                                ) `ranked`
                            ) `deduped`
                            WHERE `row_number` = 1
                            ORDER BY `next_run`, `priority` DESC, (`frequency`=0) DESC, `frequency` DESC LIMIT :limit FOR UPDATE SKIP LOCKED
                        ) `to_select`
                        ON `to_update`.`task`=`to_select`.`task`
                            AND `to_update`.`arguments`=`to_select`.`arguments`
                            AND `to_update`.`instance`=`to_select`.`instance`
                        SET `status`=1, `run_by`=:run_by, `sse`=:sse;',
                [
                    ':run_by' => $this->run_by,
                    ':sse' => [SSE::$sse, 'bool'],
                    ':limit' => [$items, 'int'],
                    ':inner_limit' => [$items * 2, 'int']
                ]);
        } catch (\Throwable $exception) {
            #Notify about the end of the stream
            $this->log('Failed to queue job', EventTypes::CronFail, true, $exception);
        }
        #Get tasks
        try {
            return Query::query(
                'SELECT `task`, `arguments`, `instance` FROM `'.$this->prefix.'schedule` WHERE `run_by`=:run_by ORDER BY `next_run`, `priority` DESC, (`frequency`=0) DESC, `frequency` DESC;',
                [
                    ':run_by' => $this->run_by,
                ], return: 'all'
            );
        } catch (\Throwable $exception) {
            #Notify about the end the stream
            $this->log('Failed to get queued tasks', EventTypes::CronFail, true, $exception);
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
    public function setSetting(#[ExpectedValues(self::SETTINGS)] string $setting, int $value): self
    {
        #Check setting name
        if (!in_array($setting, self::SETTINGS, true)) {
            throw new \InvalidArgumentException('Attempt to set unsupported setting');
        }
        #Handle values lower than 0
        if ($value <= 0) {
            $value = match ($setting) {
                'enabled', 'sse_loop' => 0,
                'log_life' => 30,
                'retry' => 3600,
                'sse_retry' => 10000,
                'max_threads' => 4,
            };
        }
        if (Query::query('UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=:setting;', [
            ':setting' => [$setting, 'string'],
            ':value' => [$value, in_array($setting, ['enabled', 'sse_loop']) ? 'bool' : 'int'],
        ])) {
            switch ($setting) {
                case 'enabled':
                    $this->cron_enabled = (bool)$value;
                    break;
                case 'sse_loop':
                    $this->sse_loop = (bool)$value;
                    break;
                case 'log_life':
                    $this->log_life = $value;
                    break;
                case 'retry':
                    $this->one_time_retry = $value;
                    break;
                case 'sse_retry':
                    $this->sse_retry = $value;
                    break;
                case 'max_threads':
                    $this->max_threads = $value;
                    break;
            }
            return $this;
        }
        throw new \UnexpectedValueException('Failed to set setting `'.$setting.'` to '.$value);
    }
    
    /**
     * Function to reschedule hanged jobs
     *
     * @return bool
     * @throws \Throwable
     */
    public function unHang(): bool
    {
        #Delete task instances that do not have a respective task registered.
        #Depending on the number of task instances, this may take a while, so use a bit of randomization to not do this on very run.
        #It is also not critical: these tasks, if picked-up, will fail to run due to `function` ending up being `null`, and thus not callable.
        try {
            if (\random_int(1, 60 * $this->max_threads) >= 60 * ($this->max_threads - 1)) {
                Query::query('DELETE FROM `'.$this->prefix.'schedule` WHERE `task` IS NOT IN (SELECT `task` FROM `'.$this->prefix.'tasks`);');
            }
        } catch (\Throwable) {
            #Do nothing
        }
        #Delete task instances that were marked as `For removal` (`status` was set to `3`), which means they failed to be removed initially, but succeeded to be updated.
        $tasks = Query::query('SELECT `task`, `arguments`, `instance` FROM `'.$this->prefix.'schedule` as `a` WHERE `status` = 3;', return: 'all');
        foreach ($tasks as $task) {
            new TaskInstance($task['task'], $task['arguments'], $task['instance'])->delete();
        }
        $tasks = Query::query('SELECT `task`, `arguments`, `instance`, `status` FROM `'.$this->prefix.'schedule` as `a` WHERE `run_by` IS NOT NULL AND (`thread_heartbeat` IS NULL OR CURRENT_TIMESTAMP(6)>DATE_ADD(`thread_heartbeat`, INTERVAL (SELECT `max_time` FROM `'.$this->prefix.'tasks` WHERE `'.$this->prefix.'tasks`.`task`=`a`.`task`) SECOND));', return: 'all');
        foreach ($tasks as $task) {
            #If this was a one-time task, schedule it for right now, to avoid delaying it for double the time.
            try {
                new TaskInstance($task['task'], $task['arguments'], $task['instance'])->reSchedule($task['status'] === 1 ? 'Hanged thread' : 'Hanged job');
            } catch (\Throwable $exception) {
                #If the instance was not found in the database, it was probably deleted, so we can safely ignore the error.
                if ($exception->getMessage() !== 'Not found in database.') {
                    throw $exception;
                }
            }
        }
        return true;
    }
    
    /**
     * Function to clean up log
     * @return bool
     */
    public function logPurge(): bool
    {
        try {
            return Query::query('DELETE FROM `'.$this->prefix.'log` WHERE `time` <= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL :log_life DAY);', [
                ':log_life' => [$this->log_life, 'int'],
            ]);
        } catch (\Throwable) {
            return false;
        }
    }
}