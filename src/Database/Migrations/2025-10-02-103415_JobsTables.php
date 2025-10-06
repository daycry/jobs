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

namespace Daycry\Cronjob\Database\Migrations;

use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;
use Daycry\Jobs\Config\Jobs;

class JobsTables extends Migration
{
    protected Jobs $config;

    public function __construct(?Forge $forge = null)
    {
        $this->config = config('Jobs');

        if ($this->config->databaseGroup !== null) {
            $this->DBGroup = $this->config->databaseGroup;
        }

        parent::__construct($forge);
    }

    public function up(): void
    {
        // Esquema actualizado (extended logging)
        $this->forge->addField([
            'id'            => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'          => ['type' => 'varchar', 'constraint' => 50, 'null' => true, 'default' => null],
            'job'           => ['type' => 'varchar', 'constraint' => 50, 'null' => true, 'default' => null],
            'executionId'   => ['type' => 'char', 'constraint' => 36, 'null' => true, 'default' => null],
            'attempt'       => ['type' => 'int', 'constraint' => 11, 'null' => true, 'default' => 0],
            'queue'         => ['type' => 'varchar', 'constraint' => 100, 'null' => true, 'default' => null],
            'source'        => ['type' => 'varchar', 'constraint' => 20, 'null' => true, 'default' => null],
            'retryStrategy' => ['type' => 'varchar', 'constraint' => 30, 'null' => true, 'default' => null],
            'payload'       => ['type' => 'longtext', 'null' => true, 'default' => null],
            'payloadHash'   => ['type' => 'char', 'constraint' => 64, 'null' => true, 'default' => null],
            'environment'   => ['type' => 'varchar', 'constraint' => 100, 'null' => true, 'default' => null],
            'output'        => ['type' => 'longtext', 'null' => true, 'default' => null],
            'outputLength'  => ['type' => 'int', 'constraint' => 11, 'null' => true, 'default' => null],
            'error'         => ['type' => 'longtext', 'null' => true, 'default' => null],
            'status'        => ['type' => 'varchar', 'constraint' => 10, 'null' => true, 'default' => null],
            'start_at'      => ['type' => 'datetime', 'null' => true, 'default' => null],
            'end_at'        => ['type' => 'datetime', 'null' => true, 'default' => null],
            'duration'      => ['type' => 'varchar', 'constraint' => 15, 'null' => true, 'default' => null],
            'test_time'     => ['type' => 'datetime', 'null' => true, 'default' => null],
            'data'          => ['type' => 'longtext', 'null' => true, 'default' => null],
            'created_at'    => ['type' => 'datetime', 'null' => false, 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'    => ['type' => 'datetime', 'null' => true, 'default' => null],
            'deleted_at'    => ['type' => 'datetime', 'null' => true, 'default' => null],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('name');
        $this->forge->addKey('executionId');
        $this->forge->addKey('status');
        $this->forge->addKey('start_at');

        $this->forge->createTable($this->config->tableName, true);
    }

    public function down(): void
    {
        $this->forge->dropTable($this->config->tableName, true);
    }
}
