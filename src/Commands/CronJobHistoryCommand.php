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
use Throwable;

/**
 * Displays execution history for a specific cron job with filtering and formatting options.
 * Supports table or JSON output and optional payload inclusion.
 */
class CronJobHistoryCommand extends BaseJobsCommand
{
    protected $name        = 'jobs:cronjob:history';
    protected $description = 'Shows recent execution history for a cron job';
    protected $usage       = 'jobs:cronjob:history <jobName> [--limit n] [--status OK|ERROR] [--full] [--json] [--payload] [--extended]';
    protected $arguments   = [
        'jobName' => 'The job name as defined in the scheduler',
    ];
    protected $options = [
        '--limit'    => 'Maximum number of executions to show (default 5)',
        '--status'   => 'Filter by status OK or ERROR',
        '--full'     => 'Do not truncate output or error columns',
        '--json'     => 'Return raw JSON array instead of table',
        '--payload'  => 'Include payload column in table/JSON',
        '--extended' => 'Include extra fields (attempt, source, outputLength, payloadHash)',
    ];

    public function run(array $params)
    {
        $this->getConfig();
        if (! $this->isActive()) {
            $this->tryToEnable();

            return false;
        }

        $jobName = $params[0] ?? null;
        if (! $jobName) {
            CLI::error('You must provide a jobName');
            CLI::write('Usage: ' . $this->usage);

            return false;
        }

        $limit = (int) (CLI::getOption('limit') ?? 5);
        if ($limit <= 0) {
            $limit = 5;
        }

        $filterStatus = CLI::getOption('status');
        if ($filterStatus !== null) {
            $filterStatus = strtoupper($filterStatus);
            if (! in_array($filterStatus, ['OK', 'ERROR'], true)) {
                CLI::error('Invalid --status value. Use OK or ERROR');

                return false;
            }
        }

        $noTruncate   = CLI::getOption('full') !== null; // presence flag
        $asJson       = CLI::getOption('json') !== null; // presence flag
        $showPayload  = CLI::getOption('payload') !== null; // presence flag
        $extendedMode = CLI::getOption('extended') !== null; // presence flag

        // Manual fallback parsing (useful in unit tests where CLI parser not engaged)
        if (! $extendedMode && in_array('--extended', $params, true)) {
            $extendedMode = true;
            // Remove the flag from params for cleanliness (doesn't impact further logic)
            $params = array_values(array_filter($params, static fn ($p) => $p !== '--extended'));
            // Re-evaluate jobName if it was first param
            $jobName = $params[0] ?? $jobName;
        }
        if (! $asJson && in_array('--json', $params, true)) {
            $asJson  = true;
            $params  = array_values(array_filter($params, static fn ($p) => $p !== '--json'));
            $jobName = $params[0] ?? $jobName;
        }

        // Instantiate logging handler like list command
        $handler = null;
        if (isset($this->config->loggers[$this->config->log])) {
            $class = $this->config->loggers[$this->config->log];
            if (class_exists($class)) {
                try {
                    $handler = new $class();
                } catch (Throwable) {
                    $handler = null;
                }
            }
        }

        if (! $handler || ! method_exists($handler, 'history')) {
            CLI::error('Logging handler does not support history or is not configured.');

            return false;
        }

        $history = $handler->history($jobName, $limit);
        if (empty($history)) {
            CLI::write('No history found for job: ' . $jobName);

            return true;
        }

        $rows = [];

        foreach ($history as $row) {
            // Row may be stdClass (file) or entity/object (db) with properties
            $start    = $row->start_at ?? null;
            $end      = $row->end_at ?? null;
            $duration = $row->duration ?? null;
            $output   = $row->output ?? null;
            $error    = $row->error ?? null;
            $payload  = $row->payload ?? null;

            // Derive status
            $status = $error ? 'ERROR' : 'OK';

            // Shorten output & error for table unless --full
            $short = static function (?string $text) use ($noTruncate): ?string {
                if ($text === null) {
                    return null;
                }
                $text = trim($text);
                if ($noTruncate) {
                    return $text;
                }
                if (strlen($text) > 60) {
                    return substr($text, 0, 57) . '...';
                }

                return $text;
            };

            // Apply status filter early to avoid building unnecessary rows
            if ($filterStatus !== null && $filterStatus !== $status) {
                continue;
            }

            $rowData = [
                'start'    => $start,
                'end'      => $end,
                'duration' => $duration,
                'status'   => $status,
                'output'   => $short($output),
                'error'    => $short($error),
            ];
            if ($showPayload) {
                $rowData['payload'] = $noTruncate ? $payload : ($payload && strlen($payload) > 60 ? substr($payload, 0, 57) . '...' : $payload);
            }
            if ($extendedMode) {
                $rowData['attempt']      = $row->attempt ?? null;
                $rowData['source']       = $row->source ?? null;
                $rowData['outputLength'] = $row->outputLength ?? null;
                $rowData['hash']         = $row->payloadHash ?? null;
            }
            $rows[] = $rowData;
        }

        if ($asJson) {
            // In JSON mode we return raw values (including full hash) when extendedMode
            // For non-extended we preserve backward compatibility
            CLI::write(json_encode($rows, JSON_PRETTY_PRINT));

            return true;
        }

        $headers = [
            'Start',
            'End',
            'Duration',
            'Status',
            $noTruncate ? 'Output' : 'Output (trimmed)',
            $noTruncate ? 'Error' : 'Error (trimmed)',
        ];
        if ($showPayload) {
            $headers[] = $noTruncate ? 'Payload' : 'Payload (trimmed)';
        }
        if ($extendedMode) {
            $headers[] = 'Attempt';
            $headers[] = 'Source';
            $headers[] = 'OutputLen';
            $headers[] = 'Hash';

            // Truncate hash display in table rows
            foreach ($rows as &$r) {
                if (isset($r['hash']) && is_string($r['hash']) && strlen($r['hash']) > 12) {
                    $r['hash'] = substr($r['hash'], 0, 12) . '…';
                }
            }
            unset($r);
        }

        CLI::table($rows, $headers);

        return true;
    }
}
