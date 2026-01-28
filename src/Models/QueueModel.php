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

namespace Daycry\Jobs\Models;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use CodeIgniter\Validation\ValidationInterface;
use Config\Database;
use DateTime;
use DateTimeZone;
use Daycry\Jobs\Entities\Queue;

/**
 * Model for interacting with queued job records (insertion, fetching next pending job).
 */
class QueueModel extends Model
{
    protected $primaryKey     = 'id';
    protected $returnType     = Queue::class;
    protected $useSoftDeletes = false;
    protected $allowedFields  = [
        'identifier',
        'queue',
        'payload',
        'priority',
        'schedule',
        'status',
        'max_retries',
        'attempts',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    public function __construct(?ConnectionInterface &$db = null, ?ValidationInterface $validation = null)
    {
        if ($db === null) {
            $db            = Database::connect(config('Jobs')->database['group']);
            $this->DBGroup = config('Jobs')->database['group'];
        }

        parent::__construct($db, $validation);
    }

    protected function initialize(): void
    {
        parent::initialize();

        $this->table = config('Jobs')->database['table'];
    }

    /**
     * Fetch next pending job ready for execution ordered by priority then schedule.
     */
    public function getJob(): ?Queue
    {
        $now = (new DateTime('now', new DateTimeZone(config('App')->appTimezone)))->format('Y-m-d H:i:s');

        return $this->where('status', 'pending')
            ->where('schedule <=', $now)
            ->orderBy('priority ASC, schedule ASC')
            ->first();
    }

    /**
     * Reserve a job from the queue safely (Atomic operation).
     */
    public function reserveJob(string $queue): ?Queue
    {
        $table       = $this->db->prefixTable($this->table);
        $attempts    = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            // 1. Find a candidate ID
            $now = (new DateTime('now', new DateTimeZone(config('App')->appTimezone)))->format('Y-m-d H:i:s');

            $sql = "SELECT id FROM {$table}
                    WHERE queue = ? AND status = 'pending' AND schedule <= ?
                    ORDER BY priority ASC, schedule ASC LIMIT 1";

            $query = $this->db->query($sql, [$queue, $now]);
            $row   = $query->getRow();

            if (! $row) {
                return null; // Queue empty
            }

            // 2. Try to lock it
            $updateSql = "UPDATE {$table}
                          SET status = 'in_progress', updated_at = ?
                          WHERE id = ? AND status = 'pending'";

            $this->db->query($updateSql, [$now, $row->id]);

            if ($this->db->affectedRows() > 0) {
                return $this->find($row->id);
            }

            // If we failed, someone else took it. Retry.
            $attempts++;
            usleep(10000); // 10ms wait
        }

        return null; // Could not lock any job after retries
    }
}
