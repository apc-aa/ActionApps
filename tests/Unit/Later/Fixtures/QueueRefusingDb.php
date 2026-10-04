<?php

declare(strict_types=1);

namespace Tests\Unit\Later\Fixtures;

use AA\IO\DB\DB_PDO;
use Tests\Unit\Central\Package\Fixtures\TableDb;

/** TableDb whose queue INSERT fails without halting, as a DB_PDO in the 'no' halt mode does - so
 *  Toexecute::later() runs the task at once */
final class QueueRefusingDb extends DB_PDO
{
    public static int $refused = 0;

    private TableDb $db;

    public function __construct()
    {
        parent::__construct(['type' => 'db_pdo:sqlite', 'database' => ':memory:']);
        $this->db = new TableDb();
    }

    public function query($Query_String): bool
    {
        if (str_starts_with($Query_String, 'INSERT INTO `toexecute`')) {
            ++self::$refused;
            return false;
        }
        return $this->db->query($Query_String);
    }

    public function query_halt(string $Query_String, string $mode): bool
    {
        return $this->query($Query_String);
    }

    public function next_record(): bool
    {
        $more         = $this->db->next_record();
        $this->Record = $this->db->record();
        return $more;
    }

    public function num_rows(): int
    {
        return $this->db->num_rows();
    }

    public function affected_rows(): int
    {
        return $this->db->affected_rows();
    }

    public function last_insert_id(): false|string
    {
        return $this->db->last_insert_id();
    }

    public function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->db->commit();
    }

    public function rollback(): bool
    {
        return $this->db->rollback();
    }
}
