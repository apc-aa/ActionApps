<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:04
 */

namespace AA\IO\Grabber;

use AA_Property;
use FTP\Connection;
use PDO;

/** FTP directory grabber */
class FilesFTP extends AbstractGrabber
{

    /** @var string sql query */
    protected $server = '';

    /** @var string ftp username */
    protected $username = '';

    /** @var string ftp password */
    protected $password = '';

    /** @var string directory to start with */
    protected $directory = '';


    /** @var Connection|false connection to ftp */
    protected $connection;
    protected array $filepaths = [];

    /** @var array include regexp - filter only files, which fullpath  matches any of the rexexp in this array - ['/.*\.txt$/'] */
    protected array $include = [];

    /** @var array exclude regexp - filter out files, which fullpath matches any of the rexexp in this array - ['~/pressreleases/~', '~/news/~'] */
    protected array $exclude = [];

    /** @var int max_files - limit number of files to be processed */
    protected int $max_files = 0;

    /** @var array info - additional info to get about the files - ['name','dir','path','size','mtime','content'] - default is all except content */
    protected array $info = [];

    /**
     * new AA\IO\Grabber\FileFTP('ftp.server.com', 'ftpuser', 'ftppassword', 'directory', [], [], 10);
     * new AA\IO\Grabber\FilesFTP('example.org', 'honza', 'mySecretPwd', '/data', ['/.*\.txt$/'], ['~/pressreleases/~', '~/news/~'], 0);
     */
    function __construct(string $server='', string $username='', string $password='', string $directory='', array $include=[], array $exclude=[], int $max_files=0, array $info=[]) {
        $this->server           = $server;
        $this->username         = $username;
        $this->password         = $password;
        $this->directory        = $directory;
        $this->include          = $include;
        $this->exclude          = $exclude;
        $this->max_files        = $max_files;
        $this->info             = $info;
    }

    /** name function
     * Name of the grabber - used for grabber selection box
     */
    public static function name() : string {
        return _m('FTP files recursive');
    }

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in HTML
     */
    public static function description(): string {
        return _m('Import data from FTP directories');
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
        $properties['server']    = new AA_Property('server',    _m("FTP server"), 'string', false, true);
        $properties['username']  = new AA_Property('username', _m("FTP Username"), 'string', false, true);
        $properties['password']  = new AA_Property('password', _m("FTP Password"), 'string', false, true);
        $properties['directory'] = new AA_Property('directory', _m("FTP directory"), 'string', false, true);
        $properties['include']   = new AA_Property('include',   _m("Include files regexp"), 'string',  true, true);
        $properties['exclude']   = new AA_Property('exclude',   _m("Exclude files regexp"), 'string',  true, true);
        $properties['max_files'] = new AA_Property('max_files', _m("Max files to process"), 'int',    false, true);
        $properties['info']      = new AA_Property('info',      _m("Info to get"), 'string',  true, true, null, false, 'name,dir,path,size,mtime,content - default is all except content');
        return $properties;
    }


    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     * @throws \Exception
     */
    function prepare() {
        $this->connection = ftp_connect($this->server);

        if (!$this->connection) {
            throw new \Exception('not possible to connect to FTP server');
        }

        // Login to FTP server
        if (!ftp_login($this->connection, $this->username, $this->password)) {
            ftp_close($this->connection);
            throw new \Exception('not possible to login to FTP server - user/pwd');
        }
        // Enable passive mode
        ftp_pasv($this->connection, true);

        $this->message('connected');
        $file_list = ftp_rawlist($this->connection, $this->directory, true);

        if ($file_list === false) {
            throw new \Exception('Couldn\'t list files in directory');
        }

//      $current_path = '';
        $current_path = $this->directory;
        foreach ($file_list as $file_info) {

            // Check for directory change indication
            // Split the raw list line to extract file attributes
            $file_details = preg_split("/\s+/", $file_info, 9);
            if ((count($file_details) === 1) AND ($file_details[0][0] === '/')) {
                $current_path = rtrim($file_details[0], ':/');
                continue;
            }

            $file_name = $file_details[8];

            // Determine the full path
            if (($file_details[0][0] !== 'd') AND strlen($file_name) AND ($file_name !== '.')  AND ($file_name !== '..')) {  // exclude directory entries
                $full_name = $current_path . '/' . $file_name;
                // if include is set, check if the file matches any of the regexp in the list
                if (count($this->include) AND !count(array_filter($this->include, fn($regex) => preg_match($regex, $full_name)))) {
                    continue;
                }
                // if exclude is set, check if the file matches any of the regexp in the list
                if (count($this->exclude) AND count(array_filter($this->exclude, fn($regex) => preg_match($regex, $full_name)))) {
                    continue;
                }
                $this->filepaths[] = $full_name;
            }

            if ($this->max_files AND count($this->filepaths) >= $this->max_files) {
                break;
            }
        }

        $this->message("found files: ". count($this->filepaths));
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if ( !count($this->filepaths) OR !($file = current($this->filepaths))) {
            return false;
        }
        $item = new \ItemContent();
        if (!count($info = array_intersect($this->info, ['name','dir','path','size','mtime','content']))) {
            $info = ['name','dir','path','size','mtime'];
        }
        // parse the file name
        in_array('name',$info)    AND $item->setValue('name', basename($file));
        in_array('dir',$info)     AND $item->setValue('dir',  dirname($file));
        in_array('path',$info)    AND $item->setValue('path', $file);
        in_array('size',$info)    AND $item->setValue('size',  ftp_size($this->connection, $file));
        in_array('mtime',$info)   AND $item->setValue('mtime', ftp_mdtm($this->connection, $file));
        in_array('content',$info) AND $item->setValue('content', $this->getFtpFileContent($file));
        //               'content' => get_file_content($conn_id, $full_path)
        // $item->setValue('content', ftp_get($this->connection, $file, 'php://output', FTP_BINARY));
        next($this->filepaths);

        return $item;
    }

    protected function getFtpFileContent($remote_file) {
        // Open a temporary stream to read the file content
        $temp_handle = fopen('php://temp', 'r+');
        $file_content = '';
        if (ftp_fget($this->connection, $temp_handle, $remote_file)) {
            // Rewind the stream to the beginning
            rewind($temp_handle);
            // Read the content from the stream into a string
            $file_content = stream_get_contents($temp_handle);
        }
        fclose($temp_handle);
        return $file_content;
    }


    /** Function called by AA\IO\Saver after we get the last item from the data input
     */
    function finish() {
        // close connections
        if ($this->connection) {
            ftp_close($this->connection);
        }
        $this->filepaths = [];
        $this->connection = false;
    }
}
