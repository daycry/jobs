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
use Throwable;

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
    protected $useTimestamps                 = true;
    protected $createdField                  = 'created_at';
    protected $updatedField                  = 'updated_at';
    protected $deletedField                  = 'deleted_at';
    private static ?bool $supportsSkipLocked = null;

    /**
     * @param ConnectionInterface<mixed, mixed>|null $db
     */
    public function __construct(?ConnectionInterface &$db = null, ?ValidationInterface $validation = null)
    {
        if (! $db instanceof ConnectionInterface) {
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
     * Attempts FOR UPDATE SKIP LOCKED first (MySQL 8+, PostgreSQL 9.5+),
     * falls back to optimistic locking for older databases or SQLite.
     *
     * When $ownerToken is provided (v3 backend path), the reserved row is stamped with
     * reserved_at = now and owner_token = $ownerToken so the reaper can later detect and
     * recover leases left behind by a crashed worker.
     */
    public function reserveJob(string $queue, ?string $ownerToken = null): ?Queue
    {
        // Try atomic locking first (best for concurrency)
        if (self::$supportsSkipLocked !== false) {
            $result = $this->reserveJobSkipLocked($queue, $ownerToken);
            if ($result instanceof Queue || self::$supportsSkipLocked === true) {
                return $result;
            }
        }

        return $this->reserveJobOptimistic($queue, $ownerToken);
    }

    /**
     * Reserve using FOR UPDATE SKIP LOCKED (MySQL 8+, PostgreSQL 9.5+).
     */
    private function reserveJobSkipLocked(string $queue, ?string $ownerToken = null): ?Queue
    {
        $table = $this->db->prefixTable($this->table);
        $now   = (new DateTime('now', new DateTimeZone(config('App')->appTimezone)))->format('Y-m-d H:i:s');

        try {
            $this->db->transStart();

            $sql = "SELECT id FROM {$table}
                    WHERE queue = ? AND status = 'pending' AND schedule <= ?
                    AND (available_at IS NULL OR available_at <= ?)
                    ORDER BY priority ASC, schedule ASC
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED";

            $query = $this->db->query($sql, [$queue, $now, $now]);
            $row   = $query->getRow();

            if ($row === null) {
                $this->db->transComplete();
                self::$supportsSkipLocked = true;

                return null;
            }

            $updateSql = "UPDATE {$table}
                          SET status = 'in_progress', reserved_at = ?, owner_token = ?, updated_at = ?
                          WHERE id = ?";

            $this->db->query($updateSql, [$now, $ownerToken, $now, $row->id]);
            $this->db->transComplete();

            self::$supportsSkipLocked = true;

            return $this->findQueue((int) $row->id);
        } catch (Throwable) {
            try {
                $this->db->transRollback();
            } catch (Throwable) {
            }
            // Database doesn't support SKIP LOCKED — fall back permanently
            self::$supportsSkipLocked = false;

            return null;
        }
    }

    /**
     * Reserve using optimistic locking (fallback for older databases).
     * Uses exponential backoff with jitter to scale under contention.
     */
    private function reserveJobOptimistic(string $queue, ?string $ownerToken = null): ?Queue
    {
        $table       = $this->db->prefixTable($this->table);
        $maxAttempts = 10;
        $baseDelayUs = 10_000;  // 10ms inicial
        $maxDelayUs  = 500_000; // 500ms cap por intento

        for ($attempts = 0; $attempts < $maxAttempts; $attempts++) {
            $now = (new DateTime('now', new DateTimeZone(config('App')->appTimezone)))->format('Y-m-d H:i:s');

            $sql = "SELECT id FROM {$table}
                    WHERE queue = ? AND status = 'pending' AND schedule <= ?
                    AND (available_at IS NULL OR available_at <= ?)
                    ORDER BY priority ASC, schedule ASC LIMIT 1";

            $query = $this->db->query($sql, [$queue, $now, $now]);
            $row   = $query->getRow();

            if ($row === null) {
                // Queue empty for this worker; no point retrying.
                return null;
            }

            $updateSql = "UPDATE {$table}
                          SET status = 'in_progress', reserved_at = ?, owner_token = ?, updated_at = ?
                          WHERE id = ? AND status = 'pending'";

            $this->db->query($updateSql, [$now, $ownerToken, $now, $row->id]);

            if ($this->db->affectedRows() > 0) {
                return $this->findQueue((int) $row->id);
            }

            // Lost the race: exponential backoff with ±20% jitter, capped.
            $expDelay = min($maxDelayUs, $baseDelayUs * (2 ** $attempts));
            $jitter   = (int) ($expDelay * (random_int(-200, 200) / 1000));
            usleep(max(1_000, $expDelay + $jitter));
        }

        return null;
    }

    /**
     * Fetch a row by primary key and narrow it to a {@see Queue} entity. The model's
     * $returnType is Queue::class so a hit always materialises as a Queue; this helper makes
     * that contract explicit for static analysis (find() is typed object|array|null upstream).
     */
    private function findQueue(int $id): ?Queue
    {
        $row = $this->find($id);

        return $row instanceof Queue ? $row : null;
    }

    /**
     * Reclaim rows stuck in 'in_progress' whose lease exceeded the visibility timeout
     * (the owning worker crashed/stalled). Returns the number of rows recovered.
     */
    public function reapStuck(string $queue, int $visibilityTimeoutSeconds): int
    {
        $table     = $this->db->prefixTable($this->table);
        $threshold = (new DateTime('now', new DateTimeZone(config('App')->appTimezone)))
            ->modify('-' . max(0, $visibilityTimeoutSeconds) . ' seconds')
            ->format('Y-m-d H:i:s');

        $sql = "UPDATE {$table}
                SET status = 'pending', owner_token = NULL, reserved_at = NULL
                WHERE queue = ? AND status = 'in_progress' AND reserved_at IS NOT NULL AND reserved_at < ?";

        $this->db->query($sql, [$queue, $threshold]);

        return $this->db->affectedRows();
    }

    /**
     * Requeue a leased row IN PLACE for retry: same row/id, attempts incremented, made
     * available again after $delaySeconds (retry backoff). Avoids the orphan-row leak of
     * the legacy mark-failed + insert-new pattern.
     */
    public function requeueInPlace(int $id, int $delaySeconds = 0): bool
    {
        $table     = $this->db->prefixTable($this->table);
        $tz        = new DateTimeZone(config('App')->appTimezone);
        $now       = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
        $available = (new DateTime('now', $tz))->modify('+' . max(0, $delaySeconds) . ' seconds')->format('Y-m-d H:i:s');

        $sql = "UPDATE {$table}
                SET status = 'pending', attempts = attempts + 1, available_at = ?, reserved_at = NULL, owner_token = NULL, updated_at = ?
                WHERE id = ?";

        $this->db->query($sql, [$available, $now, $id]);

        return $this->db->affectedRows() > 0;
    }

    /**
     * Set a terminal status ('completed' | 'failed') on a leased row.
     */
    public function markStatus(int $id, string $status): bool
    {
        $table = $this->db->prefixTable($this->table);
        $now   = (new DateTime('now', new DateTimeZone(config('App')->appTimezone)))->format('Y-m-d H:i:s');

        $sql = "UPDATE {$table}
                SET status = ?, reserved_at = NULL, owner_token = NULL, updated_at = ?
                WHERE id = ?";

        $this->db->query($sql, [$status, $now, $id]);

        return $this->db->affectedRows() > 0;
    }

    /**
     * Reset SKIP LOCKED detection (for testing).
     */
    public static function resetSkipLockedDetection(): void
    {
        self::$supportsSkipLocked = null;
    }
}
