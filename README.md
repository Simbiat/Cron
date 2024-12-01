- [What](#what)
- [Why](#why)
- [Features](#features)
- [How to](#how-to)
    * [Installation](#installation)
    * [Trigger processing](#trigger-processing)
    * [Tasks management](#tasks-management)
        + [Adding a task](#adding-a-task)
        + [Deleting a task](#deleting-a-task)
        + [Setting task as system](#setting-task-as-system)
    * [Scheduling](#scheduling)
        + [Adding a task instance](#adding-a-task-instance)
        + [Removing task from schedule](#removing-task-from-schedule)
        + [Setting task instance as system](#setting-task-instance-as-system)
        + [Manual task instance trigger](#manual-task-instance-trigger)
        + [Manual task instance rescheduling](#manual-task-instance-rescheduling)
        + [Time for next run](#time-for-next-run)
    * [Settings](#settings)
    * [Event types](#event-types)

# What

Despite the name this is not a CRON replacement, but it **is** a task scheduler nonetheless, that utilizes MySQL/MariaDB database to store tasks and their schedule.

# Why

Originally my [fftracker](https://github.com/Simbiat/FFTracker) was hosted on server that did not have CRON accessible by users, and thus I stored tasks for entities' updates (and not only) in database and triggered them through Server Side Events (SSE). While Tracker was moved to a better server this approached stayed with little changes and allowed to have parallel processing despite having no proper PHP libraries to have proper parallel processing (or multithreading).

# Features

1. Usable both in CLI and called from a web page.
2. If called from a web page, will output headers and statuses as per SSE specification.
3. If called from a web page and SSE loop is enabled in settings, will loop the execution, until the web page is closed.
4. Settings are stored in database.
5. Tasks have types, stored in database, allowing you to replicate multiple instances based on same task, but with different arguments and settings.
6. Task types can be objects, not only functions.
7. Task types support additional methods, that can be called before executing the actual function, each with its additional optional arguments.
8. Supports one-time execution for task instances.
9. Supports frequencies with 1 second precision (can be run every second).
10. Supports restrictions based on day of week or day of month.
11. Logs execution of the jobs, including errors.
12. Auto-reset of hanged jobs and auto-purge of old logs.
13. Allows to globally disable tasks processing yet allow their management.

# How to

## Installation

1. Download (manually or through composer).
2. Establish DB connection using my [Database](https://github.com/Simbiat/Database) class.
3. Install:

```php
(new \Simbiat\Cron\Agent())->install();
```

Due to current design, after the tables are created this way, you will need to recreate the object for future use, in case you will be using the same script.

## Trigger processing

To trigger processing you need to simply run this:

```php
(new \Simbiat\Cron\Agent())->process(10);
```

where `10` is maximum number of tasks you want to run. It is expected, that you will have it in some .php file, that will be triggered by some system task scheduler (like actual Cron in case of *NIX systems).

This command will do the following:

1. Set script execution time limit to 0.
2. If launched outside of CLI, ignore user abort, and send appropriate HTTP headers for SSE mode.
3. Call function to reschedule all hanged jobs (can be called manually with `(new \Simbiat\Cron\Agent())->unHang()`).
4. Call function to purge old logs (can be called manually with `(new \Simbiat\Cron\Agent())->logPurge()`).
5. Update the database with random "id" that will represent the current process and help prevent tasks overlap or empty runs if several processes are run simultaneously. Update will be done only for the selected number of tasks that are due for execution at a given second.
6. Trigger each task (optionally in a loop).

Task run are expected to return `boolean` values by default, but this can be expanded (read below). Only `true` is considered as actual success unless values are expanded. Any other value will be treated as `false`. This value will be converted to string and logged, thus it is encouraged to have your own error handling inside the called function, especially considering, that, by design, this library **cannot guarantee** catching those errors.

## Tasks management

### Adding a task

In order to use this library you will need to add at least 1 task using below command.

```php
(new Cron\Task())->settingsFromArray($settings)->add();
```

`$settings` is an associative array where each key is a setting as follows:

1. `task` is mandatory name of the task, that will be treated as a `UNIQUE` ID. Limited to `VARCHAR(100)`.
2. `function` is mandatory name of the function, that will be called. Limited to `VARCHAR(255)`.
3. `object` can be used, if your `$function` can be called only from an object. You must specify full name of the object with all namespaces, for example `\Simbiat\FFTracker`. Limited to `VARCHAR(255)`.
4. `parameters` are optional parameters that will be used when creating the optional `$object`. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(5000)`.
5. `allowedreturns` are optional return values, that will be considered as "success". By default, the library relies on `boolean` values to determine if the task was completed successfully, but this option allows to expand the possible values. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(5000)`.
6. `desccription` is an optional description of the task. Limited to `VARCHAR(1000)`.
7. `system` whether a task can be removed by this class or not.
8. `maxTime` maximum time in seconds to allow the function to run (will update execution time limit before running the task). `3600` by default.
9. `minFrequency` minimal allowed frequency (in seconds) at which a task instance can run. Does not apply to one-time jobs.
10. `retry` custom number of seconds to reschedule a failed task instance for instead of determining next run based on `frequency`. `0` (default) disables the functionality, since this can (and most likely will) introduce drift, so best be used on tasks that, do not require precise time to be run on. Applies to one-time jobs as well.

Calling this function with `$task`, that is already registered, will update respective values, except for `system`.

`parameters` argument also supports special array key `'extramethods'`. This is a multidimensional array like this:

```php
'extramethods' =>
    [
        [
            'method' => 'method1',
            'arguments' => [argument1, argument2],
        ],
        [
            'method' => 'method2',
            'arguments' => [argument3, argument4],
        ],
    ]
```

Every `method` in each sub-array is the name of the method, that needs to be called on an `$object` and `arguments` - expandable array of arguments, that needs to be passed to that `method`. These methods will be executed in the order registered in the array.

Keep in mind, that each method should be returning an object (normally `return $this`), otherwise the chain can fail.

It is also possible to load settings from DB while creating the object by passing the task name into Task constructor:

```php
(new \Simbiat\Cron\Task('taskName'));
```

### Deleting a task

To delete a task pass appropriate `taskName` to constructor and call `delete`.

```php
(new \Simbiat\Cron\Task('taskName'))->delete();
```

Note, that tasks with `system` flag set to `1` will not be deleted.

### Setting task as system

If you are creating a task from scratch, then just pass `system` setting set to `1` in the settings array. If it's an existing task, do this:

```php
(new \Simbiat\Cron\Task('taskName'))->setSystem();
```

This flag can't be set to `0` from the class, because it would defeat its security purpose. To remove it - update the database directly.

## Scheduling

Actual scheduling is done through "task instances" managed by `TaskInstance` class.

### Adding a task instance

To schedule a task use this function:

```php
(new Cron\TaskInstance())->settingsFromArray($settings)->add();
```

`$task` is mandatory name of the task.

`$arguments` are optional arguments, that will be passed to the function. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON or empty string and limited to `VARCHAR(255)` (due to MySQL limitations). Also supports special string `"$cronInstance"` (when JSON encoded, that is) that will be replaced by task instance value, when run (useful, when you need multiple instances, and need to offset their processing logic).

`$instance` is optional instance number (or ID if you like). By default, it is `1`. This is useful, if you want to create multiple instances for the same task with same arguments, which you want to run in parallel, when possible.

`$frequency` governs how frequent (in seconds) a task is supposed to be run. If set to `0`, it will mean that the task instance is one-time, thus it will be removed from schedule (not from list of tasks) after successful run.

`$message` is an optional custom message to be shown, when running in SSE mode.

`$dayofmonth` is an optional array of integers, that limits days of a month on which a task can be run. It is recommended to use it only with `$frequency` set to `86400` (1 day), because otherwise it can cause unexpected shifts and delays. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(255)`.

`$dayofweek` is an optional array of integers, that limits days of a week on which a task can be run. It is recommended to use it only with `$frequency` set to `86400` (1 day), because otherwise it can cause unexpected shifts and delays. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(60)`.

`system` whether a task can be removed by this class or not.

`nextrun` time to schedule next run of the task instance. If not passed during creation of the task instance, will schedule it for current time, which will allow you run it right away.

Same as with `Task` class it is also possible to load settings from DB while creating the object:

```php
(new \Simbiat\Cron\TaskInstance('taskName', 'arguments', 1));
```

Only `'taskName'` is mandatory, but if you have multiple instances of a task, be sure to pass respective arguments and instance, since only the combination of the three ensures uniqueness.

### Removing task from schedule

To remove a task from schedule pass appropriate `$task` and `$arguments` to

```php
(new \Simbiat\Cron\TaskInstance('taskName', 'arguments', 1))->delete();
```

### Setting task instance as system

If you are creating a task instance from scratch, then just pass `system` setting set to `1` in the settings array. If it's an existing task, do this:

```php
(new \Simbiat\Cron\TaskInstance('taskName', 'arguments', 1))->setSystem();
```

### Manual task instance trigger

In some cases you may want to manually trigger a task. You can do this like this:

```php
(new \Simbiat\Cron\TaskInstance('taskName', 'arguments', 1))->run();
```

Note, that if the task will not be found in database when `run()` is executed, you will get an exception, which differs from automated processing, when function would simply return `false` under assumption, that this was a one-time instance, that was executed by another process (although unlikely to happen).

### Manual task instance rescheduling

You can also manually reschedule a task using

```php
(new \Simbiat\Cron\TaskInstance('taskName', 'arguments', 1))->reSchedule($result, $timestamp);
```

`$result` is a `boolean` value indicating whether the last run of a task (even if it did not happen) should be considered as successful (`true`) or not (`false`). Determines which timestamp in database will be updated, and whether to remove one-time instances.

`$timestamp` is optional time, that you want to set. If not provided (`null`), will calculate best time for next run.

### Time for next run

You can execute

```php
(new \Simbiat\Cron\TaskInstance('taskName', 'arguments', 1))->updateNextRun($result);
```

to get suggested UNIX timestamp for next run of a task. It will calculate how many jobs were potentially missed based on time difference between current `nextrun` value in database and current time as well as instance frequency. This is required to keep the schedule consistent, so that if you schedule a task at `02:34` daily, it would always run at `02:34` (or try, at least). If instance has `dayofweek` or `dayofmonth`, the function will find the earliest day that will satisfy both limitations starting from the date, which was determined based on instance frequency. `result` value is optional (`true` by default), and will affect logic only if set to `false` and `retry` value for the task is more than 0, essentially overriding the normal logic.

## Settings

To change any settings, use

```php
(new \Simbiat\Cron\Agent())->setSetting($setting, $value);
```

`$setting` is name of the setting to change (`string`).

`$value` is the new value for the setting (`int`).

All settings are grabbed from database on object creation and when triggering automated processing.
Supported settings are as follows:

1. `enabled` governs whether processing is available. Does not block tasks management. Boolean value, thus as per MySQL/MariaDB design accepts only `0` and `1`. Default is `1`.
2. `errorLife` is number of days to store error logs. Default is `30`.
3. `retry` is the number of seconds to delay execution of failed one-time jobs. Such jobs have frequency set to `0`, thus in case of failure this can result in them spamming. This setting can help avoid that. Default is`3600`.
4. `sseLoop` governs whether processing can be done in a loop if used in SSE mode. If set to `0` after running `process` cycle SSE will send `CronEnd` event. If set to `1` - it will continue processing in a loop until stream is closed. Default is `0`.
5. `sseRetry` is number of milliseconds for connection retry for SSE. Will also be used to determine how long should the loop sleep if no threads or jobs, but will be treated as number of seconds divided by 20. Default is `10000` (or roughly 8 minutes for empty cycles).
6. `maxThreads` is maximum number of threads (or rather loops) to be allowed to run at the same time. Does not affect singular `runTask()` calls, only `process()`. Number of current threads is determined by the number of distinct values of `runby` in the `schedule` table, thus be careful with bad (or no) error catching, or otherwise it can be easily overrun by hanged jobs. Default is `4`.

## Event types

Below is the list of event types, that are used when logging and when outputting SSE stream:

1. `CronStart` - start of cron processing.
2. `CronFail` - failure of cron processing.
3. `CronTaskStart` - a task instance was started.
4. `CronTaskEnd` - a task instance completed successfully.
5. `CronTaskFail` - a task instance failed.
6. `CronEmpty` - empty list of tasks in the cycle.
7. `CronNoThreads` - no free threads in this cycle.
8. `CronEnd` - end of processing.
9. `Reschedule` - a task instance was rescheduled.
10. `RescheduleFail` - a task instance failed to be rescheduled.
11. `TaskAdd` - a task was added or updated.
12. `TaskAddFail` - a task failed to be added or updated.
13. `TaskDelete` - a task was deleted.
14. `TaskDeleteFail` - a task failed to be deleted.
15. `TaskToSystem` - a task was marked as system one.
16. `TaskToSystemFail` - a task failed to be marked as system one.
17. `InstanceAdd` - a task instance was added or updated.
18. `InstanceAddFail` - a task instance failed to be added or updated.
19. `InstanceDelete` - a task instance was deleted.
20. `InstanceDeleteFail` - a task instance failed to be deleted.
21. `InstanceToSystem` - a task instance was marked as system one.
22. `InstanceToSystemFail` - a task instance failed to be marked as system one.