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

namespace Daycry\Jobs\Database\Migrations;

use CodeIgniter\Database\Migration;
use Throwable;

/**
 * v3 lease columns for the database queue backend:
 *  - reserved_at: when the row was leased (in_progress); the reaper compares it to the visibility timeout.
 *  - available_at: earliest time the row may be fetched again (used to apply retry backoff via nack delay).
 *  - owner_token: random per-worker token identifying the lease holder.
 *
 * Also adds the composite index matching the real claim query
 * (WHERE queue = ? AND status = 'pending' AND schedule <= ? ORDER BY priority, schedule).
 */
class QueueLeaseColumns extends Migration
{
    public function up(): void
    {
        $table = config('Jobs')->database['table'];

        $this->forge->addColumn($table, [
            'reserved_at'  => ['type' => 'datetime', 'null' => true],
            'available_at' => ['type' => 'datetime', 'null' => true],
            'owner_token'  => ['type' => 'varchar', 'constraint' => 64, 'null' => true],
        ]);

        $this->forge->addKey(['queue', 'status', 'priority', 'schedule'], false, false, 'idx_queue_claim');
        $this->forge->processIndexes($table);
    }

    public function down(): void
    {
        $table = config('Jobs')->database['table'];

        try {
            $this->forge->dropKey($table, 'idx_queue_claim', false);
        } catch (Throwable) {
            // index may not exist on some engines — ignore
        }

        try {
            $this->forge->dropColumn($table, ['reserved_at', 'available_at', 'owner_token']);
        } catch (Throwable) {
            // SQLite older versions cannot drop columns — ignore
        }
    }
}
