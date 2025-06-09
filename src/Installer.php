<?php
declare(strict_types = 1);

namespace Simbiat\Cron;

use Simbiat\Database\Manage;
use Simbiat\Database\Query;

/**
 * Installer class for CRON library.
 */
class Installer
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
     * Install the necessary tables
     * @return bool
     */
    public function install(): bool
    {
        return new \Simbiat\Database\Installer($this->dbh)::install(__DIR__.'/sql/*.sql', $this->getVersion(), 'cron__', $this->prefix);
    }
    
    /**
     * Get the current version of the Agent from the database perspective (can be different from the library version)
     * @return string
     */
    public function getVersion(): string
    {
        #Check if the settings table exists
        if (Manage::checkTable($this->prefix.'settings') === 1) {
            #Assume that we have installed the database, try to get the version
            $version = Query::query('SELECT `value` FROM `'.$this->prefix.'settings` WHERE `setting`=\'version\'', return: 'value');
            #If an empty installer script was run before 2.1.2, we need to determine what version we have based on other things
            if (empty($version)) {
                #If errors' table does not exist, and the log table does - we are on version 2.0.0
                if (Manage::checkTable($this->prefix.'errors') === 0 && Manage::checkTable($this->prefix.'log') === 1) {
                    $version = '2.0.0';
                    #If one of the schedule columns is datetime, it's 1.5.0
                } elseif (Manage::getColumnType($this->prefix.'schedule', 'registered') === 'datetime') {
                    $version = '1.5.0';
                    #If `maxTime` column is present in `tasks` table - 1.3.0
                } elseif (Manage::checkColumn($this->prefix.'tasks', 'maxTime')) {
                    $version = '1.3.0';
                    #If `maxTime` column is present in `tasks` table - 1.2.0
                } elseif (Manage::checkColumn($this->prefix.'schedule', 'sse')) {
                    $version = '1.2.0';
                    #If one of the settings has the name `errorLife` (and not `errorlife`) - 1.1.14
                } elseif (Query::query('SELECT `setting` FROM `'.$this->prefix.'settings` WHERE `setting`=\'errorLife\'', return: 'value') === 'errorLife') {
                    $version = '1.1.14';
                    #If the `arguments` column is not nullable - 1.1.12
                } elseif (!Manage::isNullable($this->prefix.'schedule', 'arguments')) {
                    $version = '1.1.12';
                    #If `errors_to_arguments` Foreign Key exists in `errors` table - 1.1.8
                } elseif (Manage::checkFK($this->prefix.'_errors', 'errors_to_arguments')) {
                    $version = '1.1.8';
                    #It's 1.1.7 if the old column description is used
                } elseif (Manage::getColumnDescription($this->prefix.'schedule', 'arguments') === 'Optional task arguments') {
                    $version = '1.1.7';
                    #If the `maxthreads` setting exists - it's 1.1.0
                } elseif (Query::query('SELECT `setting` FROM `'.$this->prefix.'settings` WHERE `setting`=\'maxthreads\'', return: 'value') === 'maxthreads') {
                    $version = '1.1.0';
                    #Otherwise - version 1.0.0
                } else {
                    $version = '1.0.0';
                }
            }
        } else {
            $version = '0.0.0';
        }
        return $version;
    }
}