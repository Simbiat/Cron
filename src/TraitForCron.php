<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use Simbiat\Database\Query;
use Simbiat\HTTP\SSE;
use Simbiat\SandClock;
use function in_array;

/**
 * Collection of methods shared by classes in Cron namespace
 */
trait TraitForCron
{
    /**
     * PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @var \PDO|null
     */
    private(set) \PDO|null $dbh = null;
    /**
     * PDO Cron database prefix. Only Latin characters, underscores, dashes and numbers are allowed. Maximum 53 symbols.
     * @var string
     */
    private(set) string $prefix = 'cron__' {
        set {
            if (\preg_match('/^[\w\-]{0,53}$/u', $value) === 1) {
                $this->prefix = $value;
            } else {
                throw new \UnexpectedValueException('Invalid database prefix');
            }
        }
    }
    /**
     * Flag to indicate whether Cron is enabled
     * @var bool
     */
    private(set) bool $cron_enabled = false;
    /**
     * Retry time for one-time jobs
     * @var int
     */
    private(set) int $one_time_retry = 3600;
    /**
     * Days to store errors for
     * @var int
     */
    private(set) int $log_life = 30;
    /**
     * Flag to indicate whether SSE is looped or not
     * @var bool
     */
    private(set) bool $sse_loop = false;
    /**
     * Number of milliseconds for connection retry for SSE. Will also be used to determine how long should the loop sleep if no threads or jobs, but will be treated as a number of seconds divided by 20. The default is `10000` (or roughly 8 minutes for empty cycles).
     * @var int
     */
    private(set) int $sse_retry = 10000;
    /**
     * Maximum threads
     * @var int
     */
    private(set) int $max_threads = 4;
    /**
     * Random ID
     * @var null|string
     */
    private(set) ?string $run_by = null;
    /**
     * Current task object
     * @var null|TaskInstance
     */
    private ?TaskInstance $current_task = null;
    
    /**
     * Class constructor
     * @param \PDO|null $dbh    PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param string    $prefix Cron database prefix.
     */
    private function init(\PDO|null $dbh = null, string $prefix = 'cron__'): void
    {
        #Check that a database connection is established
        if ($dbh !== null) {
            $this->dbh = $dbh;
        }
        $this->prefix = $prefix;
        #Establish it, if possible
        new Query($dbh);
        $this->getCronSettings();
    }
    
    /**
     * Helper function to get settings
     */
    private function getCronSettings(): bool
    {
        #Get settings
        try {
            $settings = Query::query('SELECT `setting`, `value` FROM `'.$this->prefix.'settings`', return: 'pair');
        } catch (\Throwable) {
            return false;
        }
        #Update enabled flag
        if (\array_key_exists('enabled', $settings)) {
            $this->cron_enabled = (bool)(int)$settings['enabled'];
        }
        #Update SSE loop flag
        if (\array_key_exists('sse_loop', $settings)) {
            $this->sse_loop = (bool)(int)$settings['sse_loop'];
        }
        #Update retry time
        if (\array_key_exists('retry', $settings)) {
            $settings['retry'] = (int)$settings['retry'];
            if ($settings['retry'] > 0) {
                $this->one_time_retry = $settings['retry'];
            }
        }
        #Update SSE retry time
        if (\array_key_exists('sse_retry', $settings)) {
            $settings['sse_retry'] = (int)$settings['sse_retry'];
            if ($settings['sse_retry'] > 0) {
                $this->sse_retry = $settings['sse_retry'];
            }
        }
        #Update maximum number of threads
        if (\array_key_exists('max_threads', $settings)) {
            $settings['max_threads'] = (int)$settings['max_threads'];
            if ($settings['max_threads'] > 0) {
                $this->max_threads = $settings['max_threads'];
            }
        }
        #Update maximum life of an error
        if (\array_key_exists('log_life', $settings)) {
            $settings['log_life'] = (int)$settings['log_life'];
            if ($settings['log_life'] > 0) {
                $this->log_life = $settings['log_life'];
            }
        }
        return true;
    }
    
    /**
     * Function to end SSE stream and rethrow an error, if it was provided
     *
     * @param string                          $message    Log message
     * @param string                          $event      log event type
     * @param bool                            $end_stream Flag to indicate whether we end the stream
     * @param \Throwable|null                 $error      Error object
     * @param \Simbiat\Cron\TaskInstance|null $task       TaskInstance object
     *
     * @return void
     */
    public function log(string $message, string $event, bool $end_stream = false, ?\Throwable $error = null, ?TaskInstance $task = null): void
    {
        $skip_insert = false;
        $run_by = null;
        #If task instance was not passed, attempt to find it in backtrace
        if ($task === null) {
            $run_by = $this->runByFromBackTrace();
        } else {
            #If $task instance was passed, or we found it, use its value for run_by
            $run_by = $task?->run_by ?? $this->run_by;
        }
        #To reduce the amount of NoThreads, Empty and Disabled events in the DB log, we check if the latest event is the same we want to write
        if (in_array($event, ['CronDisabled', 'CronEmpty', 'CronNoThreads'])) {
            #Reset run_by value to null, since these entries can belong to multiple threads, and we don't really care about which one was the last one
            $run_by = null;
            #Get last event time and type
            $last_event = Query::query('SELECT `time`, `type` FROM `'.$this->prefix.'log` ORDER BY `time` DESC LIMIT 1', return: 'row');
            #Checking for empty, in case there are no logs in the table
            if (!empty($last_event['type']) && $last_event['type'] === $event) {
                #Update the message of last event with current time
                Query::query(
                    'UPDATE `'.$this->prefix.'log` SET `message`=:message WHERE `time`=:time AND `type`=:type;',
                    [
                        ':type' => $event,
                        ':time' => [$last_event['time'], 'datetime'],
                        ':message' => [$message.' (last check at '.SandClock::format(0, 'c').')', 'string'],
                    ]
                );
                $skip_insert = true;
            }
        }
        #Insert log entry only if we did not update the last log on previous check
        if (!$skip_insert) {
            Query::query(
                'INSERT INTO `'.$this->prefix.'log` (`time`, `type`, `run_by`, `sse`, `task`, `arguments`, `instance`, `message`) VALUES (:time, :type,:run_by,:sse,:task, :arguments, :instance, :message);',
                [
                    ':time' => [\microtime(true), 'timestamp'],
                    ':type' => $event,
                    ':run_by' => [$run_by ?? null, $run_by === null ? 'null' : 'string'],
                    ':sse' => [SSE::$sse, 'bool'],
                    ':task' => [$task?->task_name, $task === null ? 'null' : 'string'],
                    ':arguments' => [$task?->arguments, $task === null ? 'null' : 'string'],
                    ':instance' => [$task?->instance, $task === null ? 'null' : 'int'],
                    ':message' => [$message.($error !== null ? "\r\n".$error->getMessage()."\r\n".$error->getTraceAsString() : ''), 'string'],
                ]
            );
        }
        if (SSE::$sse) {
            SSE::send($message, $event, ((($end_stream || $error !== null)) ? 0 : $this->sse_retry));
        }
        if ($end_stream) {
            if (SSE::$sse) {
                SSE::close();
            }
            if ($error !== null) {
                throw new \RuntimeException($message, previous: $error);
            }
            exit(0);
        }
    }
    
    private function runByFromBackTrace(): ?string
    {
        $run_by = null;
        $task_instance_class = Agent::class;
        $backtrace = \debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT | \DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($backtrace as $frame) {
            if (!empty($frame['object']) && $frame['object'] instanceof $task_instance_class) {
                $run_by = $frame['object']->run_by;
                break;
            }
        }
        return $run_by;
    }
    
    /**
     * Generate random ID to be used by threads
     * @return false|string
     */
    private function generateRunBy(): false|string
    {
        try {
            return \bin2hex(\random_bytes(15));
        } catch (\Throwable $exception) {
            $this->log('Failed to generate random ID', 'CronFail', true, $exception);
            return false;
        }
    }
}