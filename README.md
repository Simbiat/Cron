- [What?](#what-)
- [Why?](#why-)
- [Features](#features)
- [How to](#how-to)
  * [Installation](#installation)
  * [Trigger processing](#trigger-processing)
  * [Tasks management](#tasks-management)
    + [Adding a task](#adding-a-task)
    + [Deleting a task](#deleting-a-task)
    + [Scheduling a task](#scheduling-a-task)
    + [Removing task from schedule](#removing-task-from-schedule)
  * [Settings](#settings)
  * [SSE Events](#sse-events)

# What?
Despite the name this is not a CRON replacement, but it **is** a task scheduler nonetheless, that utilizes MySQL/MariaDB database to store tasks and their schedule.

# Why?
Originally my [fftracker](https://github.com/Simbiat/FFTracker) was hosted on server that did not have CRON accessible by users and thus I stored tasks for entities' updates (and not only) in database and triggered them through Server Side Events (SSE). While Tracker was moved to a better server this approached stayed with little changes and allowed to have parallel processing despite having no proper PHP libraries to have proper parallel processing (or multithreading).

# Features
1. Usable both in CLI and called from a web page.
2. If called from a web page, will output headers and statuses as per SSE specification.
3. If called from a web page and SSE loop is enabled in settings, will loop the execution, until the web page is closed.
4. Settings are stored in database.
5. Tasks have types, stored in database, allowing you to replicate multiple jobs based on same task, but with different arguments.
6. Task types can be objects, not only functions.
7. Task types support additional methods, that can be called before executing the actual function, each with its additional optional arguments.
8. Supports one-time execution.
9. Supports frequencies with 1 second precision (can be ran every second).
10. Supports restrictions based on day of week or day of month.
11. Support logging of errors of the failed jobs.
12. Auto-reset of hanged jobs and auto-purge of old error logs.
13. Allows to globally disable tasks processing yet allow their management.

# How to
## Installation
1. Download (manually or through composer).
2. Establish DB connection using my [Database](https://github.com/Simbiat/Database) class.
3. Create object using the code below:
```php
(new \Simbiat\Cron('prefix__', false));
```
`'prefix__'` - the optional prefix your tables will have. Defaults to `'cron__'`.
`false` is required for installation, as a flag that indicates whether tables are "installed" (defaults to `true`). `false` will trigger the installation process.  
Due to current design, after the tables are created this way, you will need to recreate the object for future use, in case you will be using the same script.

## Trigger processing
To trigger processing you need to simply run this:
```php
$cron->process(1);
```
Where `$cron` is the object you have created and `1` is number of tasks you want to run.  
This command will do the following:
1. Set script execution time limit to 0.
2. If launched outside of CLI, ignore user abort, and send appropriate HTTP headers for SSE mode.
3. Call function to reschedule all hanged jobs.
4. Call function to purge old errors in the log.
5. Update the database with random "id" that will represent the current process and help prevent tasks overlap or empty runs if several processes are ran simultaneously. Update will be done only for the selected number of tasks that are due for execution at a given second.
6. Trigger each task (optionally in a loop).

Step 6 will call 
```php
$this->runTask(string $task, null|array|string $arguments = NULL);
```
This function can also be called separately if you want to trigger a specific job (if it is not already running). It takes 2 arguments, that compile a `UNIQUE` key on database level:  
`$task` - name of the task type.  
`$arguments` - arguments for the function.  
If a task with these values and status `0` (not running) or `1` (pending) is not found - `false` will be returned by the function, but this **will not** be treated as an error (it is possible, that a task was a one-time job and was finished already).  
Task run here are expected to return `boolean` values by default, but this can be expanded (read below). Only `true` is considered as actual success unless values are expanded. Any other value will be treated as `false`. This value will be converted to string and logged in error log, thus it is encouraged to your own error handling inside the called function, especially considering, that, by design, this library **cannot guarantee** catching those errors.

## Tasks management
### Adding a task
In order to use this library you will need to add at least 1 task using below command.  
```php
addTask(string $task, string $function, ?string $object = NULL, null|array|string $parameters = NULL, null|array|string $returns = NULL, ?string $desc = NULL);
```
`$task` is mandatory name of the task, that will be treated as a `UNIQUE` ID. Limited to `VARCHAR(100)`.  
`$function` is mandatory name of the function, that will be called. Limited to `VARCHAR(255)`.  
`$object` can be used, if your `$function` can be called only from an object. You must specify full name of the object with all namespaces, for example `\Simbiat\FFTracker`. Limited to `VARCHAR(255)`.  
`$parameters` are optional parameters that will be used when creating the optional `$object`. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(5000)`.  
`$returns` are optional return values, that will be considered as "success". By default, the library relies on `boolean` values to determine if the task was completed successfully, but this option allows to expand the possible values. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(5000)`.  
`$desc` is an optional description of the task. Limited to `VARCHAR(1000)`.  
Calling this function with `$task`, that is already registered, will update respective values.  
`$parameters` argument also supports special array key `'extramethods'`. This is a multidimensional array like this:
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

### Deleting a task
To delete a task pass appropriate `$task` to
```php
deleteTask(string $task);
```

### Scheduling a task
To schedule a task use this function:
```php
add(string $task, null|array|string $arguments = NULL, int|string $frequency = 0, int $priority = 0, ?string $message = NULL, null|array|string $dayofmonth = NULL, null|array|string $dayofweek = NULL, int $time = 0);
```
`$task` is mandatory name of the task, with which it was added using `addTask`.   
`$arguments` are optional arguments, that will be passed to the function. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(255)` (due to MySQL limitations).  
`$frequency` governs how frequent (in seconds) a task is supposed to be ran. If set to 0, it will mean that the task is one-time, thus it will be removed from schedule (not from list of tasks) after successful run.  
`$message` is an optional custom message to be shown, when running in SSE mode.  
`$dayofmonth` is an optional array of integers, that limits days of a month on which a task can be ran. It is recommended to use it only with `$frequency` set to `86400` (1 day), because otherwise it can cause unexpected shifts and delays. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(255)`.  
`$dayofweek` is an optional array of integers, that limits days of a week on which a task can be ran. It is recommended to use it only with `$frequency` set to `86400` (1 day), because otherwise it can cause unexpected shifts and delays. Needs to be either pure array or JSON encoded array of values, that can be expanded (`...` operator). Stored in database as JSON string and limited to `VARCHAR(60)`.  

### Removing task from schedule
To remove a task from schedule pass appropriate `$task` and `$arguments` to
```php
delete(string $task, null|array|string $arguments = NULL);
```

## Settings
To change any settings, use
```php
setSetting(string $setting, int $value);
```
`$setting` is name of the setting to change.  
`$value` is the new value for the setting.  
All settings are grabbed from database on object creation (unless created with `false` for installation).  
Supported settings are as follows:
1. `enabled` governs whether processing is available. Does not block tasks management. Boolean value, thus as per MySQL/MariaDB design accepts only `0` and `1`. Default is `1`.
2. `errorlife` is number of days to store error logs. Default is `30`.
3. `maxtime` is maximum seconds that a job can run. When exceeded, a job is considered "hung" and will be rescheduled. Default is `3600`.
4. `retry` is the number of seconds to delay execution of failed one-time jobs. Such jobs have frequency set to `0`, thus in case of failure this can result in them spamming. This setting can help avoid that. Default is`3600`.
5. `sseLoop` governs whether processing can be done in a loop if used in SSE mode. If set to `0` after running `process` cycle SSE will send `CronEnd` event. If set to `1` - it will continue processing in a loop until stream is closed. Default is `0`.
6. `sseRetry` is number of milliseconds for connection retry for SSE. Will also be used to determine how long should the loop sleep if no threads or jobs, but will be treated as number of seconds divided by 20. Default is `10000` (or roughly 8 minutes for empty cycles).
7. `maxthreads` is maximum number of threads (or rather loops) to be allowed to run at the same time. Does not affect singular `runTask()` calls, only `process()`. Number of current threads is determined by the number of distinct values of `runby` in the `schedule` table, thus be careful with bad (or no) error catching or otherwise it can be easily overrun by hanged jobs. Default is `4`.

## SSE Events
When used in SSE mode, these events will be sent to client:
1. `CronStart` - start of processing.
2. `CronFail` - failure of processing.
3. `CronTaskSkip` - task was skipped.
4. `CronTaskStart` - task was started.
5. `CronTaskEnd` - task completed successfully.
6. `CronTaskFail` - task failed.
7. `CronEmpty` - empty list of tasks in the cycle.
8. `CronNoThreads` - no free threads on this cycle.
9. `CronEnd` - end of processing.