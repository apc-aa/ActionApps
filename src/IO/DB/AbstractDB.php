<?php


namespace AA\IO\DB;

abstract class AbstractDB {

    /* public: parameter */

    /** @var string - yes | no | report  */
    protected $Halt_On_Error = 'yes'; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)

    protected $Record = [];
    protected $Errno  = 0;
    protected $Error  = "";

    public function __construct(array $connection) {}

    function quote($string) {}

    /* public: connection management */
    function connect() {}

    function connect_failed($message='') {
        $this->set_halt('yes');
        $this->halt($message);
    }

    public function set_halt(string $mode): bool {
        if (!in_array($mode, ['yes','report','no'])) {
            return false;
        }
        $this->Halt_On_Error = $mode;
        return true;
    }

    public function get_halt(): string {
        return $this->Halt_On_Error;
    }

    /* public: discard the query result */
    function free() {}

    /* public: perform a query */
    public function query($Query_String) {}

    public function query_halt(string $Query_String, string $mode) {
        $store = $this->Halt_On_Error;
        $this->set_halt($mode);
        $ret = $this->query($Query_String);
        $this->set_halt($store);
        return $ret;
    }

    /* public: walk result set */
    public function next_record(): bool { return false;}

    /* public: return all the results */
    // function fetch_table() {}

    /* public: return all the results */
    public function fetch_column($col): array { return []; }

    /* public: position in result set */
    public function seek($pos = 0) {}

    /* public: table locking */
    public function lock($table, $mode="write") {}

    public function unlock() {}

    public function ping() {}

    /* public: evaluate the result (size, width) */
    public function affected_rows(): int { return 0; }

    public function num_rows(): int { return 0; }

    public function num_fields() {}

    public function last_insert_id(): false|string { return false; }

    public function f($Name) {}

    /** based on my test mysqli contains string[], while db_pdo:mysql returns columns in type based on table column type */
    public function record($Name = null) {
        return  $Name ? $this->Record[$Name] : $this->Record;
    }

    public function metadata($table = "", $full = false): array|false { return false; }

    public function table_names(): array { return []; }

    /* private: error handling */
    public function halt($msg) {
        if ($this->get_halt() === 'no') {
            return;
        }
        $this->haltmsg($msg);
        if ($this->get_halt() !== 'report') {
            die("Session halted.");
        }
    }

    public function haltmsg($msg) {
        huhl('err');
        printf("<p><b>Database error:</b> %s<br>\n", safe($msg));
        printf("<b>%s Error</b>: %s (%s)</p>\n", get_class_name($this), $this->Errno, $this->Error);
    }
}