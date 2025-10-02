<?php

namespace Daycry\Cronjob\Database\Migrations;

use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;
use Daycry\Jobs\Config\Jobs;

class CreateJobsTable extends Migration
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

    public function up()
    {
        // Jobs
        $this->forge->addField(
            [
                'id'          => ['type' => 'int', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
                'name'        => ['type' => 'varchar', 'constraint' => 50, 'null' => true, 'default' => null],
                'job'        => ['type' => 'varchar', 'constraint' => 25, 'null' => false],
                'payload'      => ['type' => 'varchar', 'constraint' => 255, 'null' => false],
                'environment' => ['type' => 'varchar', 'constraint' => 100, 'null' => true, 'default' => null],
                'output'      => ['type' => 'longtext', 'null' => true, 'default' => null],
                'error'       => ['type' => 'longtext', 'null' => true, 'default' => null],
                'start_at'    => ['type' => 'datetime', 'null' => false],
                'end_at'      => ['type' => 'datetime', 'null' => false],
                'duration'    => ['type' => 'time', 'null' => false],
                'test_time'   => ['type' => 'datetime', 'null' => true, 'default' => null],
                'created_at'  => ['type' => 'datetime', 'null' => false, 'default' => new RawSql('CURRENT_TIMESTAMP')],
                'updated_at'  => ['type' => 'datetime', 'null' => true, 'default' => null],
                'deleted_at'  => ['type' => 'datetime', 'null' => true, 'default' => null],
            ],
        );

        $this->forge->addKey('id', true);
        $this->forge->addKey('deleted_at');

        $this->forge->createTable($this->config->tableName, true);
    }

    public function down()
    {
        $this->forge->dropTable($this->config->tableName, true);
    }
}
