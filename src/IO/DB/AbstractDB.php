<?php


namespace AA\IO\DB;

abstract class AbstractDB {

    /* public: parameter */

    /** @var string - yes | no | report  */
    public    $Halt_On_Error = "yes"; // "yes" (halt with message), "no" (ignore errors quietly), "report" (ignore error, but spit a warning)

    protected $Record = [];
    protected $Errno  = 0;
    protected $Error  = "";

    public function __construct(array $connection) {}

    function quote($string) {}

    /* public: connection management */
    function connect() {}

    function connect_failed($message='') {
        $this->Halt_On_Error = "yes";
        $this->halt($message);
    }

    /* public: discard the query result */
    function free() {}

    /* public: perform a query */
    public function query($Query_String) {}

    /* public: walk result set */
    public function next_record() {}

    /* public: return all the results */
    // function fetch_table() {}

    /* public: return all the results */
    function fetch_column($col) {}

    /* public: position in result set */
    function seek($pos = 0) {}

    /* public: table locking */
    function lock($table, $mode="write") {}

    function unlock() {}

    function ping() {}

    /* public: evaluate the result (size, width) */
    function affected_rows() {}

    function num_rows() {}

    function num_fields() {}

    function last_insert_id() {}

    function f($Name) {}

    function record($Name = null) {
        return  $Name ? $this->Record[$Name] : $this->Record;
    }

    function metadata($table = "", $full = false) {}

    function table_names() {}

    /* private: error handling */
    function halt($msg) {
        if ($this->Halt_On_Error == "no") {
            return;
        }

        $this->haltmsg($msg);

        if ($this->Halt_On_Error != "report") {
            die("Session halted.");
        }
    }

    function haltmsg($msg) {
        huhl('err');
        printf("<p><b>Database error:</b> %s<br>\n", $msg);
        printf("<b>%s Error</b>: %s (%s)</p>\n", get_class_name($this), $this->Errno, $this->Error);
    }
}