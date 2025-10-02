<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Jobs.
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
use Daycry\Jobs\Entities\Queue;

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

    public function getJob(): ?Queue
    {
        return $this->where('status', 'pending')->where('schedule <=', date('Y-m-d H:i:s'))->orderBy('priority ASC, schedule ASC')->first();
    }
}
