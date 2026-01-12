- [What](#what)
- [Why](#why)
- [Features](#features)
- [How to](#how-to)
    * [Installation](#installation)
    * [Trigger processing](#trigger-processing)
    * [Tasks management](#tasks-management)
        + [Adding a task](#adding-a-task)
        + [Deleting a task](#deleting-a-task)
        + [Setting task as system](#setting-a-task-as-a-system-one)
    * [Scheduling](#scheduling)
        + [Adding a task instance](#adding-a-task-instance)
        + [Removing task from schedule](#removing-a-task-from-the-schedule)
        + [Setting task instance as system](#setting-task-instance-as-system)
        + [Manual task instance trigger](#manual-task-instance-trigger)
        + [Manual task instance rescheduling](#manual-task-instance-rescheduling)
        + [Time for next run](#time-for-next-run)
    * [Settings](#settings)
    * [Event types](#event-types)

## What

Despite the name this is not a CRON replacement, but it **is** a task scheduler nonetheless, that uses MySQL/MariaDB database to store tasks and their schedule.

## Why

Originally my [fftracker](https://github.com/Simbiat/FFTracker) was hosted on a server that did not have CRON accessible by users, and thus I stored tasks for entities' updates (and not only) in a database and triggered them through Server-Sent Events (SSE). While Tracker was moved to a better server this approached stayed with little changes and allowed to have parallel processing despite having no proper PHP libraries to have proper parallel processing (or multithreading). Now it's used for automatically generated tasks with various priority, which would be difficult to handle using regular CRON.

## Features

1. Usable both in CLI and called from a web page.
2. If called from a web page, it will output headers and statuses as per SSE specification.
3. If called from a web page and SSE loop is enabled in settings, it will loop the execution until the web page is closed.
4. Settings are stored in a database.
5. Tasks have types stored in a database, allowing you to replicate multiple instances based on the same task, but with different arguments and settings.
6. Task types can be objects, not only functions.
7. Task types support additional methods that can be called before executing the actual function, each with its additional optional arguments.
8. Supports one-time execution for task instances.
9. Supports frequencies with 1-second precision (can be run every second).
10. Supports restrictions based on the day of the week or day of the month.
11. Logs execution of the jobs, including errors.
12. Auto-reset of hanged jobs and auto-purge of old logs.
13. Allows globally disabling tasks processing yet allows their management.

## How to

### Installation

1. Download (manually or through composer).
2. Establish DB connection using my [DB-Pool](https://github.com/Simbiat/db-pool) library or passing a `PDO` object to `Agent`'s (or `Task`'s or `TaskInstance`'s) constructor.
3. Install:

```php
(new \Simbiat\Cron\Installer($dbh))->install();
```

Due to the current design, after the tables are created this way, you will need to recreate the object for future use, in case you will be using the same script.

If you do not want to run installation, but rather just check the current version (from database perspective), use

```php
(new \Simbiat\Cron\Installer($dbh))->getVersion();
```

#### Changing prefix

By default, tables use `cron__` prefix, but you can change that by passing your own prefix. All classes (`Installer`, `Agent`, `Task` and `TaskInstance`) support a `prefix` argument, so if you do change it during installation, you can use it even when managing stuff outside of `Agent`.

#### Changing database connection

Similar logic applies for the database connection object (`dbh`), in case you are not using [DB-Pool](https://github.com/Simbiat/db-pool).

### Trigger processing

To trigger processing, you need to simply run this:

```php
(new \Simbiat\Cron\Agent($dbh))->process(10);
```

where `10` is maximum number of tasks you want to run. It is expected that you will have it in some .php file that will be triggered by some system task scheduler (like actual Cron in the case of *NIX systems).  
**_IMPORTANT_**: Due to the logic of the library related to support of multiple instances of same tasks, the number of actual rows selected from the table will be **double** of the number of passed to `process` function. It should not matter much in case of small number of task instances, but if you have a lot of those, and each `process` needs to select a lot of rows as well, it **will** affect performance. For example, in case of eight million instances, `process(25)` results in the query completing with 25 seconds, while `process(50)` - up to 5 minutes, when everything else is under same conditions (and you have low memory, at least).

This command will do the following:

1. Set the script execution time limit to 0.
2. If launched outside CLI, ignore user abort and send appropriate HTTP headers for SSE mode.
3. Call function to reschedule all hanged jobs (can be called manually with `(new \Simbiat\Cron\Agent())->unHang()`).
4. Call function to purge old logs (can be called manually with `(new \Simbiat\Cron\Agent())->logPurge()`).
5. Update the database with random "id" that will represent the current process and help prevent tasks overlap or empty runs if several processes are run simultaneously. Update will be done only for the selected number of tasks that are due for execution at a given second.
6. Trigger each task (optionally in a loop).

Task run is expected to return `boolean` values by default, but this can be expanded (read below). Only `true` is considered as actual success unless values are expanded. Any other value will be treated as `false`. This value will be converted to string and logged, thus it is encouraged to have your own error handling inside the called function, especially considering that, by design, this library **cannot guarantee** catching those errors.

### Tasks management

#### Adding a task

To use this library, you will need to add at least one task using the below command.

```php
(new Cron\Task())->settingsFromArray($settings)->add();
```

`$settings` is an associative array where each key is a setting as follows:

1. `task` is mandatory name of the task, that will be treated as a `UNIQUE` ID. Limited to `VARCHAR(100)`.
2. `function` is mandatory name of the function, that will be called. Limited to `VARCHAR(255)`.
3. `object` can be used, if your `$function` can be called only from an object. You must specify full name of the object with all namespaces, for example `\Simbiat\FFTracker`. Limited to `VARCHAR(255)`.
4. `parameters` are optional parameters that will be used when creating the optional `$object`. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in the database as JSON string and limited to `VARCHAR(5000)`.
5. `allowed_returns` are optional return values that will be considered as success. By default, the library relies on `boolean` values to determine if the task was completed successfully, but this option allows expanding the possible values. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in the database as JSON string and limited to `VARCHAR(5000)`.
6. `desccription` is an optional description of the task. Limited to `VARCHAR(1000)`.
7. `enabled` whether a task (and its instances) is enabled (and run as per schedule) or not. Used only when creating new tasks.
8. `system` whether a task can be removed by this class or not. Used only when creating new tasks.
9. `max_time` maximum time in seconds to allow the function to run (will update execution time limit before running the task). `3600` by default.
10. `min_frequency` minimal allowed frequency (in seconds) at which a task instance can run. It does not apply to one-time jobs.
11. `retry` custom number of seconds to reschedule a failed task instance for instead of determining next run based on `frequency`. `0` (default) disables the functionality, since this can (and most likely will) introduce drift, so best be used on tasks that do not require precise time to be run on. Applies to one-time jobs as well.

Calling this function with `$task`, that is already registered, will update respective values, except for `system`.

`parameters` argument also supports special array key `'extra_methods'`. This is a multidimensional array like this:

```php
'extra_methods' =>
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

Every `method` in each subarray is the name of the method, that needs to be called on an `$object` and `arguments` - expandable array of arguments, that needs to be passed to that `method`. These methods will be executed in the order registered in the array.

Keep in mind that each method should be returning an object (normally `return $this`), otherwise the chain can fail.

It is also possible to load settings from DB while creating the object by passing the task name into Task constructor:

```php
(new \Simbiat\Cron\Task('task_name'));
```

#### Deleting a task

To delete a task pass appropriate `task_name` to constructor and call `delete`.

```php
(new \Simbiat\Cron\Task('task_name'))->delete();
```

Note, that tasks with `system` flag set to `1` will not be deleted.

#### Enabling or disabling task

If you are creating a task from scratch, then just pass `enabled` setting set to `1` (default) or `0` in the settings array. If it's an existing task, do this:

```php
(new \Simbiat\Cron\Task('task_name'))->setEnabled(bool $enabled = true);
```

#### Setting a task as a system one

If you are creating a task from scratch, then just pass `system` setting set to `1` in the settings array. If it's an existing task, do this:

```php
(new \Simbiat\Cron\Task('task_name'))->setSystem();
```

This flag can't be set to `0` from the class, because it would defeat its security purpose. To remove it — update the database directly.

### Scheduling

Actual scheduling is done through "task instances" managed by `TaskInstance` class.

#### Adding a task instance

To schedule a task, use this function:

```php
(new Cron\TaskInstance())->settingsFromArray($settings)->add();
```

1. `task` is mandatory name of the task.
2. `arguments` are optional arguments that will be passed to the function. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in the database as JSON or empty string and limited to `VARCHAR(255)` (due to MySQL limitations). Also supports special string `"$cron_instance"` (when JSON encoded, that is) that will be replaced by task instance value, when run (useful, when you need multiple instances, and need to offset their processing logic).
3. `instance` is optional instance number (or ID if you like). By default, it is `1`. This is useful if you want to create multiple instances for the same task with the same arguments, which you want to run in parallel, when possible.
4. `frequency` governs how frequent (in seconds) a task is supposed to be run. If set to `0`, it will mean that the task instance is one-time, thus it will be removed from schedule (not from the list of tasks) after successful run.
5. `message` is an optional custom message to be shown, when running in SSE mode.
6. `day_of_month` is an optional array of integers that limits days of a month on which a task can be run. It is recommended to use it only with `$frequency` set to `86400` (1 day), because otherwise it can cause unexpected shifts and delays. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in the database as JSON string and limited to `VARCHAR(255)`.
7. `day_of_week` is an optional array of integers that limits days of a week on which a task can be run. It is recommended to use it only with `$frequency` set to `86400` (1 day), because otherwise it can cause unexpected shifts and delays. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in the database as JSON string and limited to `VARCHAR(60)`.
8. `enabled` whether a task instance is enabled (and run as per schedule) or not. Used only when creating new instances.
9. `system` whether a task instance can be removed by this class or not. Used only when creating new instances.
10. `next_run` time to schedule next run of the task instance. If not passed during creation of the task instance, will schedule it for the current time, which will allow you to run it right away.

Same as with `Task` class it is also possible to load settings from DB while creating the object:

```php
(new \Simbiat\Cron\TaskInstance('task_name', 'arguments', 1));
```

Only `'task_name'` is mandatory, but if you have multiple instances of a task, be sure to pass respective arguments and instance, since only the combination of the three ensures uniqueness.

#### Removing a task from the schedule

To remove a task from schedule pass appropriate `$task` and `$arguments` to

```php
(new \Simbiat\Cron\TaskInstance('task_name', 'arguments', 1))->delete();
```

#### Enabling or disabling task instance as system

If you are creating a task instance from scratch, then just pass `enabled` setting set to `1` (default) or `0` in the settings array. If it's an existing instance, do this:

```php
(new \Simbiat\Cron\TaskInstance('task_name', 'arguments', 1))->setEnabled(bool $enabled = true);
```

#### Setting task instance as system

If you are creating a task instance from scratch, then just pass `system` setting set to `1` in the settings array. If it's an existing task, do this:

```php
(new \Simbiat\Cron\TaskInstance('task_name', 'arguments', 1))->setSystem();
```

#### Manual task instance trigger

In some cases you may want to manually trigger a task. You can do this like this:

```php
(new \Simbiat\Cron\TaskInstance('task_name', 'arguments', 1))->run();
```

Note, that if the task is not found in the database when `run()` is executed, you will get an exception. This differs from automated processing, when function would simply return `false` under the assumption, that this was a one-time instance, executed by another process (although unlikely to happen).

#### Manual task instance rescheduling

You can also manually reschedule a task using

```php
(new \Simbiat\Cron\TaskInstance('task_name', 'arguments', 1))->reSchedule($result, $timestamp);
```

`$result` is a `boolean` value indicating whether the last run of a task (even if it did not happen) should be considered as successful (`true`) or not (`false`). Determines which timestamp and which counters in the database will be updated and whether to remove one-time instances. `string` can be provided as well, and in that case it will be treated as `false`, but also update `last_error_message` column with respective text.

`$timestamp` is optional time, that you want to set. If not provided (`null`), will calculate the best time for next run.

#### Time for next run

You can execute below command to get a suggested `DateTimeImmutable` for next run of a task.

```php
(new \Simbiat\Cron\TaskInstance('task_name', 'arguments', 1))->updateNextRun($result);
```

It will calculate how many jobs were potentially missed based on time difference between current `next_run` value in the database and current time as well as instance frequency. This is required to keep the schedule consistent, so that if you schedule a task at `02:34` daily, it would always run at `02:34` (or try, at least). If instance has `day_of_week` or `day_of_month`, the function will find the earliest day that will satisfy both limitations starting from the date, which was determined based on instance frequency. `result` value is optional (`true` by default), and will affect logic only if set to `false` and `retry` value for the task is more than 0, essentially overriding the normal logic.

### Settings

To change any settings, use

```php
(new \Simbiat\Cron\Agent($dbh))->setSetting($setting, $value);
```

`$setting` is name of the setting to change (`string`).

`$value` is the new value for the setting (`int`).

All settings are grabbed from the database on object creation and when triggering automated processing.
Supported settings are as follows:

1. `enabled` governs whether processing is available. Does not block tasks management. Boolean value, thus as per MySQL/MariaDB design accepts only `0` and `1`. Default is `1`.
2. `log_life` is number of days to store error logs. Default is `30`.
3. `retry` is the number of seconds to delay execution of failed one-time jobs. Such jobs have frequency set to `0`, thus in case of failure this can result in them spamming. This setting can help avoid that. Default is`3600`.
4. `sse_loop` governs whether processing can be done in a loop if used in SSE mode. If set to `0` after running `process` cycle SSE will send `SSEEnd` event. If set to `1` - it will continue processing in a loop until stream is closed. Default is `0`.
5. `sse_retry` is number of milliseconds for connection retry for SSE. Will also be used to determine how long should the loop sleep if no threads or jobs, but will be treated as a number of seconds divided by 20. Default is `10000` (or roughly 8 minutes for empty cycles).
6. `max_threads` is maximum number of threads (or rather loops) to be allowed to run at the same time. Does not affect singular `runTask()` calls, only `process()`. Number of current threads is determined by the number of distinct values of `run_by` in the `schedule` table, thus be careful with bad (or no) error catching, or otherwise it can be easily overrun by hanged jobs. Default is `4`.

### Event types

Below is the list of event types that are used when logging and when outputting SSE stream:

1. `CronFail` - failure of cron processing.
2. `CronEmpty` - empty list of tasks in the cycle.
3. `CronNoThreads` - no free threads in this cycle.
4. `CronDisabled` - cron processing is disabled in settings.
5. `TaskAdd` - a task was added or updated.
6. `TaskAddFail` - a task failed to be added or updated.
7. `TaskDelete` - a task was deleted.
8. `TaskDeleteFail` - a task failed to be deleted.
9. `TaskToSystem` - a task was marked as system one.
10. `TaskToSystemFail` - a task failed to be marked as system one.
11. `TaskEnable` - a task was enabled.
12. `TaskDisable` - a task was disabled.
13. `TaskEnableFail` - failed to enable a task.
14. `TaskDisableFail` - failed to disable a task.
15. `InstanceStart` - a task instance was started.
16. `InstanceEnd` - a task instance completed successfully.
17. `InstanceFail` - a task instance failed.
18. `InstanceAdd` - a task instance was added or updated.
19. `InstanceAddFail` - a task instance failed to be added or updated.
20. `InstanceDelete` - a task instance was deleted.
21. `InstanceDeleteFail` - a task instance failed to be deleted.
22. `InstanceToSystem` - a task instance was marked as system one.
23. `InstanceToSystemFail` - a task instance failed to be marked as system one.
24. `InstanceEnable` - a task instance was enabled.
25. `InstanceDisable` - a task instance was disabled.
26. `InstanceEnableFail` - failed to enable task instance.
27. `InstanceDisableFail` - failed to disable task instance.
28. `Reschedule` - a task instance was rescheduled.
29. `RescheduleFail` - a task instance failed to be rescheduled.
30. `SSEStart` - start of cron processing in SSE mode.
31. `SSEEnd` - end of processing in SSE mode.
32. `CustomEmergency` - custom event indicating an emergency (SysLog standard level 0).
33. `CustomAlert` - custom event indicating an alert (SysLog standard level 1).
34. `CustomCritical` - custom event indicating a critical condition (SysLog standard level 2).
35. `CustomError` - custom event indicating an error (SysLog standard level 3).
36. `CustomWarning` - custom event indicating a warning (SysLog standard level 4).
37. `CustomNotice` - custom event indicating a notice (SysLog standard level 5).
38. `CustomInformation` - custom event indicating an informative message (SysLog standard level 6).
39. `CustomDebug` - custom event indicating a debugging message (SysLog standard level 7).

### Custom events

You might have noticed that among the event types there are a few starting with `Custom` prefix. They are added to allow you to log custom events from functions called by Cron.  
To log events call:

```php
(new \Simbiat\Cron\Agent)->log(string $message, \Simbiat\Cron\EventTypes $event, bool $end_stream = false, ?\Throwable $error = null, ?TaskInstance $task = null);
```

Instead of `Agent` you can use `Task` or `TaskInstance`, since `log` is part of a `TraitForCron` and is available in all of them.

`$message` is the text of your message you want to send.  
`$event` is the event type taken from enum `\Simbiat\Cron\EventTypes`.  
`$end_stream` is a `bool` value indicating whether the execution should stop after sending the message. This will also end the SSE stream.  
`$error` is optional `\Throwable` object, that will be used to log details of an error, that you have caught.  
`$task` is an optional `\Simbiat\Cron\TaskInstance` object, that you ***SHOULD NOT*** pass to this function normally. If you are using the class normally, it will be populated automatically even when not passed (appropriate `TaskInstance` will be determined using backtrace).