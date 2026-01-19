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

class AddMaxRetriesToQueue extends Migration
{
    public function up(): void
    {
        $table = config('Jobs')->database['table'];

        $fields = [
            'max_retries' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'default'    => null,
            ],
            'attempts' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => false,
                'default'    => 0,
            ],
        ];

        $this->forge->addColumn($table, $fields);
    }

    public function down(): void
    {
        $table = config('Jobs')->database['table'];

        $this->forge->dropColumn($table, ['max_retries', 'attempts']);
    }
}
