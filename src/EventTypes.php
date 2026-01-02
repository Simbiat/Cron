<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

/**
 * Event types
 */
enum EventTypes: int
{
    /**
     * @var int Cron processing is disabled in settings.
     */
    case CronDisabled = 100;
    /**
     * @var int Empty list of tasks in the cycle.
     */
    case CronEmpty = 101;
    /**
     * @var int No free threads in this cycle.
     */
    case CronNoThreads = 102;
    /**
     * @var int Failure of cron processing.
     */
    case CronFail = 103;
    /**
     * @var int Start of cron processing in SSE mode.
     */
    case SSEStart = 110;
    /**
     * @var int End of cron processing in SSE mode.
     */
    case SSEEnd = 111;
    /**
     * @var int A task was added or updated.
     */
    case TaskAdd = 200;
    /**
     * @var int A task was deleted.
     */
    case TaskDelete = 201;
    /**
     * @var int Task was enabled.
     */
    case TaskEnable = 202;
    /**
     * @var int Task was disabled.
     */
    case TaskDisable = 203;
    /**
     * @var int A task was marked as system one.
     */
    case TaskToSystem = 204;
    /**
     * @var int A task failed to be added or updated.
     */
    case TaskAddFail = 210;
    /**
     * @var int A task failed to be deleted.
     */
    case TaskDeleteFail = 211;
    /**
     * @var int Failed to enable task.
     */
    case TaskEnableFail = 212;
    /**
     * @var int Failed to disable task.
     */
    case TaskDisableFail = 213;
    /**
     * @var int A task failed to be marked as system one.
     */
    case TaskToSystemFail = 214;
    /**
     * @var int A task instance was added or updated.
     */
    case InstanceAdd = 300;
    /**
     * @var int A task instance was deleted.
     */
    case InstanceDelete = 301;
    /**
     * @var int A task instance was marked as system one.
     */
    case InstanceToSystem = 302;
    /**
     * @var int Task instance was enabled.
     */
    case InstanceEnable = 303;
    /**
     * @var int Task instance was disabled.
     */
    case InstanceDisable = 304;
    /**
     * @var int A task instance was rescheduled.
     */
    case Reschedule = 305;
    /**
     * @var int A task instance was started.
     */
    case InstanceStart = 306;
    /**
     * @var int A task instance completed successfully.
     */
    case InstanceEnd = 307;
    /**
     * @var int A task instance failed to be added or updated.
     */
    case InstanceAddFail = 310;
    /**
     * @var int A task instance failed to be deleted.
     */
    case InstanceDeleteFail = 311;
    /**
     * @var int A task instance failed to be marked as system one.
     */
    case InstanceToSystemFail = 312;
    /**
     * @var int Failed to enable task instance.
     */
    case InstanceEnableFail = 313;
    /**
     * @var int Failed to disable task instance.
     */
    case InstanceDisableFail = 314;
    /**
     * @var int A task instance failed to be rescheduled.
     */
    case RescheduleFail = 315;
    /**
     * @var int A task instance failed.
     */
    case InstanceFail = 318;
    /**
     * @var int Custom event indicating an emergency (SysLog standard level 0).
     */
    case CustomEmergency = 900;
    /**
     * @var int Custom event indicating an alert (SysLog standard level 1).
     */
    case CustomAlert = 901;
    /**
     * @var int Custom event indicating a critical condition (SysLog standard level 2).
     */
    case CustomCritical = 902;
    /**
     * @var int Custom event indicating an error (SysLog standard level 3).
     */
    case CustomError = 903;
    /**
     * @var int Custom event indicating a warning (SysLog standard level 4).
     */
    case CustomWarning = 904;
    /**
     * @var int Custom event indicating a notice (SysLog standard level 5).
     */
    case CustomNotice = 905;
    /**
     * @var int Custom event indicating an informative message (SysLog standard level 6).
     */
    case CustomInformation = 906;
    /**
     * @var int Custom event indicating a debugging message (SysLog standard level 7).
     */
    case CustomDebug = 907;
}