<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:04
 */

namespace AA\IO\Grabber;

use AA_Property;
use PDO;

/** SQL database query grabber
 *  // ----- example of importer.php for this grabber ----
 *  $t13s = [];
 *  $t13s['headline........'] = new AA_Transformation_Value(['new_content'=>'{index:name}']);
 *  $t13s['date...........2'] = new AA_Transformation_Value(['new_content'=>'{date}']);
 *  $grabber   = new AA\IO\Grabber\SQL('SELECT * FROM customers', 'database', 'db-user', 'db-password');
 *  $saver     = new AA\IO\Saver($grabber, $t13s, 'a49a5346ea451524bf3c173514377bb2', 'update/insert', '["headline........"]');
 *  $importer  = new AA\IO\Importer($saver);
 *  // $importer->toexecutelater();
 *  $saver->run();
 */
class SQL extends AbstractGrabber
{

    /** @var string sql query */
    protected $sql = '';

    /** @var string pdo connection string */
    protected $dsn = '';

    /** @var string database username */
    protected $username = '';

    /** @var string database password */
    protected $password = '';


    /** @var string[] column names */
    protected $db;
    protected $dbresult;

    /**
     * SQL constructor.
     * new AA\IO\Grabber\SQL('SELECT * FROM data', 'DBname', 'DBuser', 'DBPasswd', 'mysql', '127.0.0.1');
     * new AA\IO\Grabber\SQL('SELECT * FROM data', 'DBname', 'DBuser', 'DBPasswd', 'mysql', 'mydbserver');
     */
    function __construct($sql='', $dbname='', $username='', $password='', $type='', $host='', $port='', $charset='') {
        $this->sql  = $sql;
        $this->dsn  = ( ctype_alpha($type) ? $type : 'mysql');
        $arr = [];
        if ( ctype_alnum($dbname) ) {
            $arr[] = 'dbname=' . $dbname;
        }
        if ( filter_var($host, FILTER_VALIDATE_IP) or ctype_alnum($host) ) {
            $arr[] = 'host=' . $host;
        }
        if ( ctype_digit($port) ) {
            $arr[] = 'port=' . $port;
        }
        if ( ctype_alnum($charset) ) {
            $arr[] = 'charset=' . $charset;
        }
        $this->dsn .= ':'.join(';',$arr);

        $this->username         = $username;
        $this->password         = $password;
    }

    /** name function
     * Name of the grabber - used for grabber selection box
     */
    public static function name() : string {
        return _m('SQL');
    }

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public static function description(): string {
        return _m('Import data from SQL database');
    }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations
     * @return bool
     */
    public function isDirectfill(): bool {
        return false;
    }

    /** getClassProperties function of AA_Serializable
     *  Used for importer and htmlSettings()
     * @return array  $sql='', $dbname='', $username='', $password='', $type='', $host='', $port='', $charset=''
     */
    public static function getClassProperties(): array {
        $properties = [];   //                   id             name        type    multi  persistent - validator, required, help, morehelp, example
        $properties['sql']      = new AA_Property('sql',      _m("SQL query"), 'string', false, true);
        $properties['dbname']   = new AA_Property('dbname',   _m("Database name"), 'string', false, true);
        $properties['username'] = new AA_Property('username', _m("Username"), 'string', false, true);
        $properties['password'] = new AA_Property('password', _m("Password"), 'string', false, true);
        $properties['type']     = new AA_Property('type',     _m("Database type (mysql, ...)"), 'string', false, true);
        $properties['host']     = new AA_Property('host',     _m("Host IP address"), 'string', false, true);
        $properties['port']     = new AA_Property('port',     _m("Port"), 'string', false, true);
        $properties['charset']  = new AA_Property('charset',  _m("Charset (utf8mb4,...)"), 'string', false, true);
        return $properties;
    }


    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        try {
            $this->db       = new PDO($this->dsn, $this->username, $this->password);
        } catch (\PDOException $e) {
            $this->message('not possible to connect');
            return;
	}    
        $this->message('connected');
	$this->dbresult = $this->db->query($this->sql);
 
        // $this->message("parsed items ". count($this->_items));
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if (!$this->dbresult or (false === ($data = $this->dbresult->fetch(PDO::FETCH_ASSOC)))) {
            return false;
        }
        $item = new \ItemContent();
        foreach ($data as $k => $v) {
            $item->setValue($k, $v);
        }
        return $item;
    }

    /** finish function
     *  Function called by AA\IO\Saver after we get the last item from the data
     *  input
     */
    function finish() {
        // close connections
        $this->dbresult = null;
        $this->db       = null;
    }
}
