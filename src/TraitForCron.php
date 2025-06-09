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
            if (preg_match('/^[\w\-]{0,53}$/u', $value) === 1) {
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
    private(set) bool $cronEnabled = false;
    /**
     * Retry time for one-time jobs
     * @var int
     */
    private(set) int $oneTimeRetry = 3600;
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
     * List of event types that are allowed to not have TaskInstance object with them
     * @var array
     */
    private const array eventsNoInstance = ['SSEStart', 'CronFail', 'CronEmpty', 'CronNoThreads', 'SSEEnd', 'TaskToSystem', 'TaskToSystemFail', 'TaskAdd', 'TaskAddFail', 'TaskDelete', 'TaskDeleteFail', 'CronDisabled'];
    /**
     * Random ID
     * @var null|string
     */
    private(set) ?string $run_by = null;
    /**
     * Current task object
     * @var null|TaskInstance
     */
    private ?TaskInstance $currentTask = null;
    
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
        if (isset($settings['enabled'])) {
            $this->cronEnabled = (bool)(int)$settings['enabled'];
        }
        #Update SSE loop flag
        if (isset($settings['sse_loop'])) {
            $this->sse_loop = (bool)(int)$settings['sse_loop'];
        }
        #Update retry time
        if (isset($settings['retry'])) {
            $settings['retry'] = (int)$settings['retry'];
            if ($settings['retry'] > 0) {
                $this->oneTimeRetry = $settings['retry'];
            }
        }
        #Update SSE retry time
        if (isset($settings['sse_retry'])) {
            $settings['sse_retry'] = (int)$settings['sse_retry'];
            if ($settings['sse_retry'] > 0) {
                $this->sse_retry = $settings['sse_retry'];
            }
        }
        #Update maximum number of threads
        if (isset($settings['max_threads'])) {
            $settings['max_threads'] = (int)$settings['max_threads'];
            if ($settings['max_threads'] > 0) {
                $this->max_threads = $settings['max_threads'];
            }
        }
        #Update maximum life of an error
        if (isset($settings['log_life'])) {
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
     * @param string                          $message   SSE message
     * @param string                          $event     SSE type
     * @param bool                            $endStream Flag to indicate whether we end the stream
     * @param \Throwable|null                 $error     Error object
     * @param \Simbiat\Cron\TaskInstance|null $task      TaskInstance object
     *
     * @return void
     */
    public function log(string $message, string $event, bool $endStream = false, ?\Throwable $error = null, ?TaskInstance $task = null): void
    {
        if ($task === null && !in_array($event, $this::eventsNoInstance, true)) {
            #Something is trying to use Cron log to write a custom message and does not have associated TaskInstance with it, so probably was called outside Cron classes.
            #We do not want to flood DB with unsupported logs, and for SSE a separate function can be used
            return;
        }
        $skipInsert = false;
        #If $task was passed, use its value for run_by
        $run_by = $task?->run_by ?? $this->run_by;
        #To reduce the amount of NoThreads, Empty and Disabled events in the DB log, we check if the latest event is the same we want to write
        if (in_array($event, ['CronDisabled', 'CronEmpty', 'CronNoThreads'])) {
            #Reset run_by value to null, since these entries can belong to multiple threads, and we don't really care about which one was the last one
            $run_by = null;
            #Get last event time and type
            $lastEvent = Query::query('SELECT `time`, `type` FROM `'.$this->prefix.'log` ORDER BY `time` DESC LIMIT 1', return: 'row');
            #Checking for empty, in case there are no logs in the table
            if (!empty($lastEvent['type']) && $lastEvent['type'] === $event) {
                #Update the message of last event with current time
                Query::query(
                    'UPDATE `'.$this->prefix.'log` SET `message`=:message WHERE `time`=:time AND `type`=:type;',
                    [
                        ':type' => $event,
                        ':time' => [$lastEvent['time'], 'datetime'],
                        ':message' => [$message.' (last check at '.SandClock::format(0, 'c').')', 'string'],
                    ]
                );
                $skipInsert = true;
            }
        }
        #Insert log entry only if we did not update the last log on previous check
        if (!$skipInsert) {
            Query::query(
                'INSERT INTO `'.$this->prefix.'log` (`type`, `run_by`, `sse`, `task`, `arguments`, `instance`, `message`) VALUES (:type,:run_by,:sse,:task, :arguments, :instance, :message);',
                [
                    ':type' => $event,
                    ':run_by' => [empty($run_by) ? null : $run_by, empty($run_by) ? 'null' : 'string'],
                    ':sse' => [SSE::$SSE, 'bool'],
                    ':task' => [$task?->taskName, $task === null ? 'null' : 'string'],
                    ':arguments' => [$task?->arguments, $task === null ? 'null' : 'string'],
                    ':instance' => [$task?->instance, $task === null ? 'null' : 'int'],
                    ':message' => [$message.($error !== null ? "\r\n".$error->getMessage()."\r\n".$error->getTraceAsString() : ''), 'string'],
                ]
            );
        }
        if (SSE::$SSE) {
            SSE::send($message, $event, ((($endStream || $error !== null)) ? 0 : $this->sse_retry));
        }
        if ($endStream) {
            if (SSE::$SSE) {
                SSE::close();
            }
            if ($error !== null) {
                throw new \RuntimeException($message, previous: $error);
            }
            exit(0);
        }
    }
    
    /**
     * Generate random ID to be used by threads
     * @return false|string
     */
    private function generateRunBy(): false|string
    {
        try {
            return bin2hex(random_bytes(15));
        } catch (\Throwable $exception) {
            $this->log('Failed to generate random ID', 'CronFail', true, $exception);
            return false;
        }
    }
}