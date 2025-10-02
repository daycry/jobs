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
use CodeIgniter\Database\RawSql;

class QueueTables extends Migration
{
    public function up(): void
    {
        /**
         * Projects Modules
         */
        $this->forge->addField([
            'id'         => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'identifier' => ['type' => 'varchar', 'constraint' => 50, 'null' => false],
            'queue'      => ['type' => 'varchar', 'constraint' => 50, 'null' => false],
            'payload'    => ['type' => 'json', 'null' => false],
            'priority'   => ['type' => 'int', 'constraint' => 11, 'null' => false, 'default' => 10],
            'schedule'   => ['type' => 'datetime', 'null' => false, 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'status'     => ['type' => 'enum', 'constraint' => ['pending', 'in_progress', 'completed', 'failed'], 'null' => false, 'default' => 'pending'],
            'created_at' => ['type' => 'datetime', 'null' => false, 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at' => ['type' => 'datetime', 'null' => true],
            'deleted_at' => ['type' => 'datetime', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(key: 'queue');
        $this->forge->addKey('priority');
        $this->forge->addKey('schedule');
        $this->forge->addKey('created_at');
        $this->forge->addKey('updated_at');
        $this->forge->addKey('deleted_at');

        $this->forge->createTable(config('Jobs')->database['table'], true);
    }

    public function down(): void
    {
        $this->forge->dropTable(config('Jobs')->database['table'], true);
    }
}
