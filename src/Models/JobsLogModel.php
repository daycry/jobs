<?php

namespace Daycry\Jobs\Models;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use CodeIgniter\Validation\ValidationInterface;
use Config\Database;

class JobsLogModel extends Model
{
    protected $primaryKey         = 'id';
    protected $useAutoIncrement   = true;
    protected $returnType         = 'object';
    protected $useSoftDeletes     = false;
    protected $allowedFields      = ['name', 'job', 'payload', 'environment', 'output', 'error', 'start_at', 'end_at', 'duration', 'test_time'];
    protected $useTimestamps      = true;
    protected $createdField       = 'created_at';
    protected $updatedField       = 'updated_at';
    protected $deletedField       = 'deleted_at';
    protected $validationRules    = [];
    protected $validationMessages = [];
    protected $skipValidation     = false;

    public function __construct(?ConnectionInterface &$db = null, ?ValidationInterface $validation = null)
    {
        if ($db === null) {
            $this->DBGroup = config('Jobs')->databaseGroup;
            $db            = Database::connect($this->DBGroup);
        }

        $this->table = config('Jobs')->tableName;

        parent::__construct($db, $validation);
    }
}
