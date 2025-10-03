<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Queues.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Daycry\Jobs\Commands;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Exceptions\ExceptionInterface;
use CodeIgniter\I18n\Time;
use Cron\CronExpression;

/**
 * Lists currently scheduled cron jobs including last and next run times (if logging supports history).
 */
class CronJobListCommand extends BaseJobsCommand
{
    /**
     * The Command's name
     *
     * @var string
     */
    protected $name = 'jobs:cronjob:list';

    /**
     * the Command's short description
     *
     * @var string
     */
    protected $description = 'Lists the cronjobs currently set to run.';

    /**
     * the Command's usage
     *
     * @var string
     */
    protected $usage = 'jobs:cronjob:list';

    /**
     * Lists upcoming tasks
     */
    public function run(array $params)
    {
        $this->getConfig();
        if (! $this->isActive()) {
            $this->tryToEnable();

            return false;
        }

        $scheduler = service('scheduler');
        config('Jobs')->init($scheduler);

        $jobs = [];

        // Instanciar una sola vez el handler de logging si la configuración es válida
        $handler = null;
        if (isset($this->config->loggers[$this->config->log])) {
            $class = $this->config->loggers[$this->config->log];
            if (class_exists($class)) {
                try {
                    $handler = new $class();
                } catch (ExceptionInterface $e) {
                    $handler = null; // Falla silenciosa: seguimos sin last_run
                }
            }
        }

        foreach ($scheduler->getJobs() as $job) {
            $cron    = new CronExpression($job->getExpression());
            $nextRun = ($job->isEnabled()) ? $cron->getNextRunDate()->format('Y-m-d H:i:s') : '--';

            $lastRunValue = '--';
            if ($handler && method_exists($handler, 'lastRun')) {
                try {
                    $lr           = $handler->lastRun($job->getName());
                    $lastRunValue = $lr instanceof Time ? $lr->format('Y-m-d H:i:s') : ($lr ?: '--');
                } catch (ExceptionInterface $e) {
                    // Ignorar errores al obtener el último run
                }
            }

            $jobs[] = [
                'name'     => $job->getName() ?: $job->getJob(),
                'job'      => $job->getJob(),
                'schedule' => $job->getExpression(),
                'last_run' => $lastRunValue,
                'next_run' => $nextRun,
            ];
        }

        // Ordenar por próxima ejecución; los deshabilitados ('--') siempre al final
        usort($jobs, static function ($a, $b) {
            $na = $a['next_run'];
            $nb = $b['next_run'];
            if ($na === $nb) {
                return 0;
            }
            if ($na === '--') {
                return 1; // a va después
            }
            if ($nb === '--') {
                return -1; // b va después
            }

            return $na <=> $nb;
        });

        CLI::table(
            $jobs,
            [
                'Name',
                'Type',
                'Expression',
                'Last Run',
                'Next Run',
            ],
        );
    }
}
