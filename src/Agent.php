<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use JetBrains\PhpStorm\ExpectedValues;
use Simbiat\Database\Query;
use Simbiat\HTTP\SSE;
use function in_array;

/**
 * Task scheduler that uses MySQL/MariaDB database to store tasks and their schedule.
 */
class Agent
{
    use TraitForCron;
    
    /**
     * Supported settings
     * @var array
     */
    private const array settings = ['enabled', 'logLife', 'retry', 'sseLoop', 'sseRetry', 'maxThreads'];
    /**
     * Logic to calculate task priority. Not sure, I fully understand how this provides the results I expect, but it does. Essentially, `priority` is valued higher, while "overdue" time has a smoother scaling. Rare jobs (with higher value of `frequency`) also have higher weight, but one-time jobs have even higher weight, since they are likely to be quick ones.
     * @var string
     */
    private const string calculatedPriority = '((CASE WHEN `frequency` = 0 THEN 1 ELSE (4294967295 - `frequency`) / 4294967295 END) + LOG(TIMESTAMPDIFF(SECOND, `nextrun`, CURRENT_TIMESTAMP(6)) + 2) * 100 + `priority` * 1000)';
    
    
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
        $this->runBy = $this->generateRunBy();
        if (SSE::$SSE) {
            $this->log('Cron processing started in SSE mode', 'SSEStart');
        }
        #Regular maintenance
        if (Query::$dbh !== null) {
            #Reschedule hanged jobs
            $this->unHang();
            #Clean old logs
            $this->logPurge();
        } else {
            #Notify about the end of the stream
            $this->log('Cron database not available', 'CronFail', true);
            return false;
        }
        #Check if cron is enabled and process only if it is
        if (!$this->cronEnabled) {
            #Notify about the end of the stream
            $this->log('Cron processing is disabled', 'CronDisabled', true);
            return false;
        }
        #Sanitize the number of items
        if ($items < 1) {
            $items = 1;
        }
        do {
            if (!$this->getCronSettings()) {
                $this->log('Failed to get CRON settings', 'CronFail', true);
                return false;
            }
            #Check if enough threads are available
            try {
                if (Query::query('SELECT COUNT(DISTINCT(`runby`)) as `count` FROM `'.$this->prefix.'schedule` WHERE `runby` IS NOT NULL;', return: 'count') >= $this->maxThreads) {
                    $this->log('Cron threads are exhausted', 'CronNoThreads');
                    if (!SSE::$SSE) {
                        return false;
                    }
                    #Sleep for a bit
                    sleep($this->sseRetry / 20);
                    continue;
                }
            } catch (\Throwable $exception) {
                $this->log('Failed to check for available threads', 'CronFail', true, $exception);
                return false;
            }
            #Queue tasks for this random ID
            $tasks = $this->getTasks($items);
            if (empty($tasks)) {
                $this->log('Cron list is empty', 'CronEmpty');
                if (SSE::$SSE) {
                    #Sleep for a bit
                    sleep($this->sseRetry / 20);
                }
            } else {
                $totalTasks = \count($tasks);
                foreach ($tasks as $number => $task) {
                    $this->runTask($task, $number + 1, $totalTasks);
                }
            }
            #Additionally, reschedule hanged jobs if we're in SSE
            if (SSE::$SSE && $this->sseLoop) {
                $this->unHang();
            }
        } while ($this->cronEnabled && SSE::$SSE && $this->sseLoop && connection_status() === 0);
        #Notify about the end of the stream
        if (SSE::$SSE) {
            $this->log('Cron processing finished', 'SSEEnd', true);
        }
        return true;
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
            $this->log($number.'/'.$totalTasks.' '.(empty($task['message']) ? $task['task'].' starting' : $task['message']), 'InstanceStart', task: $taskInstance);
            #Attemp to run
            $result = $taskInstance->run();
        } catch (\Throwable $exception) {
            $this->log('Failed to run task `'.$task['task'].'` ('.$number.'/'.$totalTasks.')', 'InstanceFail', false, $exception, ($taskInstance ?? null));
            return;
        } finally {
            $this->currentTask = null;
        }
        #Notify of the task finishing
        if ($result) {
            $this->log($number.'/'.$totalTasks.' '.$task['task'].' finished'.($taskInstance->frequency === 0 ? ' and deleted' : ''), 'InstanceEnd', task: $taskInstance);
        } else {
            $this->log($number.'/'.$totalTasks.' '.$task['task'].' failed', 'InstanceFail', task: $taskInstance);
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
            Query::query('UPDATE `'.$this->prefix.'schedule` AS `toUpdate`
                        INNER JOIN
                        (
                            SELECT `task`, `arguments`, `instance` FROM (
                                SELECT `task`, `arguments`, `instance`, `nextrun`, '.self::calculatedPriority.' AS `calculated` FROM `'.$this->prefix.'schedule` AS `instances`
                                WHERE `enabled`=1 AND `runby` IS NULL AND `nextrun`<=CURRENT_TIMESTAMP() AND (SELECT `enabled` FROM `'.$this->prefix.'tasks` `tasks` WHERE `tasks`.`task`=`instances`.`task`)=1
                                ORDER BY `calculated` DESC, `nextrun`
                                LIMIT :innerLimit
                            ) `instances` GROUP BY `task`, `arguments` ORDER BY `calculated` DESC, `nextrun` LIMIT :limit FOR UPDATE SKIP LOCKED
                        ) `toSelect`
                        ON `toUpdate`.`task`=`toSelect`.`task`
                            AND `toUpdate`.`arguments`=`toSelect`.`arguments`
                            AND `toUpdate`.`instance`=`toSelect`.`instance`
                        SET `status`=1, `runby`=:runby, `sse`=:sse;',
                [
                    ':runby' => $this->runBy,
                    ':sse' => [SSE::$SSE, 'bool'],
                    ':limit' => [$items, 'int'],
                    #Using this approach seems to be the best solution so far, so that no temporary tables are used (or smaller ones, at least), and it is still relatively performant.
                    #In the worst case scenario tested with 8mil+ records in schedule, the query took 1.5 minutes, which was happening while there are other queries running on the same table at the same time.
                    #On smaller (and more realistic) data sets performance hit is negligible.
                    ':innerLimit' => [$items * 2, 'int']
                ]);
        } catch (\Throwable $exception) {
            #Notify about the end of the stream
            $this->log('Failed to queue job', 'CronFail', true, $exception);
        }
        #Get tasks
        try {
            return Query::query(
                'SELECT `task`, `arguments`, `instance` FROM `'.$this->prefix.'schedule` WHERE `runby`=:runby ORDER BY '.self::calculatedPriority.' DESC, `nextrun`;',
                [
                    ':runby' => $this->runBy,
                ], return: 'all'
            );
        } catch (\Throwable $exception) {
            #Notify about the end the stream
            $this->log('Failed to get queued tasks', 'CronFail', true, $exception);
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
        if (Query::query('UPDATE `'.$this->prefix.'settings` SET `value`=:value WHERE `setting`=:setting;', [
            ':setting' => [$setting, 'string'],
            ':value' => [$value, in_array($setting, ['enabled', 'sseLoop']) ? 'bool' : 'int'],
        ])) {
            switch ($setting) {
                case 'enabled':
                    $this->cronEnabled = (bool)$value;
                    break;
                case 'sseLoop':
                    $this->sseLoop = (bool)$value;
                    break;
                case 'logLife':
                    $this->logLife = $value;
                    break;
                case 'retry':
                    $this->oneTimeRetry = $value;
                    break;
                case 'sseRetry':
                    $this->sseRetry = $value;
                    break;
                case 'maxThreads':
                    $this->maxThreads = $value;
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
        #Delete tasks that were marked as `For removal` (`status` was set to `3`), which means they failed to be removed initially, but succeeded to be updated.
        $tasks = Query::query('SELECT `task`, `arguments`, `instance` FROM `'.$this->prefix.'schedule` as `a` WHERE `status` = 3;', return: 'all');
        foreach ($tasks as $task) {
            new TaskInstance($task['task'], $task['arguments'], $task['instance'])->delete();
        }
        $tasks = Query::query('SELECT `task`, `arguments`, `instance`, `frequency` FROM `'.$this->prefix.'schedule` as `a` WHERE `runby` IS NOT NULL AND CURRENT_TIMESTAMP()>DATE_ADD(IF(`lastrun` IS NOT NULL, `lastrun`, `nextrun`), INTERVAL (SELECT `maxTime` FROM `'.$this->prefix.'tasks` WHERE `'.$this->prefix.'tasks`.`task`=`a`.`task`) SECOND);', return: 'all');
        foreach ($tasks as $task) {
            #If this was a one-time task, schedule it for right now, to avoid delaying it for double the time.
            try {
                new TaskInstance($task['task'], $task['arguments'], $task['instance'])->reSchedule(false);
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
            return Query::query('DELETE FROM `'.$this->prefix.'log` WHERE `time` <= DATE_SUB(CURRENT_TIMESTAMP(), INTERVAL :logLife DAY);', [
                ':logLife' => [$this->logLife, 'int'],
            ]);
        } catch (\Throwable) {
            return false;
        }
    }
}