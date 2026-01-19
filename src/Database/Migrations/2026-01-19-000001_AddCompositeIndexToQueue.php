<?php

declare(strict_types=1);

namespace Daycry\Jobs\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCompositeIndexToQueue extends Migration
{
    public function up(): void
    {
        $table = config('Jobs')->database['table'];
        
        // Add composite index for fetching pending jobs efficienty
        // Covers: WHERE status='pending' AND schedule <= ? ORDER BY priority ASC, schedule ASC
        $this->forge->addKey(['status', 'schedule', 'priority'], false, false, 'idx_queue_fetch');
        $this->forge->processIndexes($table);
    }

    public function down(): void
    {
        $table = config('Jobs')->database['table'];
        
        $this->forge->dropKey($table, 'idx_queue_fetch', false);
    }
}
