<?php
/**
 * File Utilities.
 * @author $Author$
 * @version $Id$
 * @package ImageManager
 */

define('FILE_ERROR_NO_SOURCE',        100);
define('FILE_ERROR_COPY_FAILED',      101);
define('FILE_ERROR_DST_DIR_FAILED',   102);
define('FILE_ERROR_NOT_UPLOADED',     104);
define('FILE_ERROR_TYPE_NOT_ALLOWED', 105); // type of uploaded file not allowed
define('FILE_ERROR_DIR_CREATE',       106); // can't create directory for image uploads
define('FILE_ERROR_CHMOD',            107);
define('FILE_ERROR_WRITE',            108);
define('FILE_ERROR_NO_DESTINATION',   109);
define('FILE_ERROR_READ',             110);
define('FILE_COPY_OK',                199);


/**
 * File Utilities
 * @author $Author$
 * @version $Id$
 * @package ImageManager
 * @subpackage files
 */
class Files {
    /** Method returns or sets last file error
     *  The trick for static class variables is used */
    function lastErr($err_id = null, $err_msg = null, $getmsg = false) {
        static $lastErr;
        static $lastErrMsg;
        if (!is_null($err_id)) {
            $lastErr    = $err_id;
            $lastErrMsg = $err_msg;
        }
        return $getmsg ? $lastErrMsg : $lastErr;
    }

    /** Return last error message - it is grabbed from static variable
     *  of lastErr() method */
    function lastErrMsg() {
        return Files::lastErr(null, null, true);
    }

    /** Prepares slice directories for uploaded file and returns destination
     *  dir name
     */
    function destinationDir(&$slice) {
        $upload = $slice->getUploadBase();
        return Files::_destinationDirCreate($upload['path'], $upload['perms']);
    }

    /** Prepares global AA directory for uploaded file and returns destination
     *  dir name
     */
    function aadestinationDir() {
        return Files::_destinationDirCreate(IMG_UPLOAD_PATH. AA_ID, (int)IMG_UPLOAD_DIR_MODE);
    }

    /** Prepares directory for uploaded file and returns destination dir name
     */
    function _destinationDirCreate($path, $perms) {
        if (!$path OR !is_dir($path)) {
            if (!Files::CreateFolder($path, $perms)) {
                Files::lastErr(FILE_ERROR_DIR_CREATE, _m("Can't create directory for image uploads"));  // set error code
                return false;
            }
        }
        return $path;
    }

    /** checks, if the file is not exist and in case it exist, it finds similar
     *  file name which do not exists. If modificator specified, then it is
     *  added to fileneme (like '_thumb')
     */
    function generateUnusedFilename($file_name, $modificator='') {
        $path_parts = pathinfo($file_name);

        // we have to process dot (extension do not contain it)
        // we must think about the files as 'test' or '.test' as well
        if ( strpos($path_parts['basename'], '.') === false ) {
            $extension = '';
            $base      = $path_parts['basename'] .$modificator;
        } else {
            $extension = '.'. $path_parts['extension'];
            $base = substr($path_parts['basename'],0,-strlen($extension)).$modificator;
        }
        $add = '';
        $i   = 0;
        while (file_exists($dest_file = Files::makeFile($path_parts['dirname'], "$base$add$extension"))) {
            $add = '_'. (++$i);
        }
        return $dest_file;
    }

    /** Returns all content of the uploaded file as the string.
     *  The file is deleted after read
     *  @param string $filevarname - name of form variable, where the file is stored
     */
    function getUploadedFile($filevarname) {
        $file_name  = Files::getTmpFilename('tmp');

        // upload file - todo: error is not returned, if not exist

        if (($dest_file = Files::uploadFile($filevarname, Files::aaDestinationDir(), '', 'overwrite', $file_name)) === false) {
            return false;  // error code is already set from Files::uploadFile()
        }

        if (($text = file_get_contents($dest_file)) === false) {
            Files::lastErr(FILE_ERROR_READ, _m("Can't read the file %1", array($dest_file)));  // set error code
            return false;
        }

        // delete files older than one week in the img_upload directory
        Files::deleteTmpFiles('tmp');

        return $text;
    }

    /** Uploads file to slice's directory
     *  @param $filevarname - name of form variable containing the uploaded data
     *                        (like "upfile")
     *  @param $slice       - slice for which we would like to upload the file
     *                        (used for determining the destination file)
     *  @param $type        - allowed file types (like 'image/jpeg', 'image/*')
     *  @param $replacemethod - how to handle conflicts with existing file
     *                            'new'       - stored as new (unused) filename
     *                            'overwrite' - the old file is overwriten
     *                            'backup'    - old file is backuped to new
     *                                          (unused) filename
     * @param $filename     - the name of file as you want to store it (if you
     *                        do not want to use original name)
     */
    function uploadFile($filevarname, $dest_dir, $type='', $replacemethod='new', $filename=null) {
        $up_file = $_FILES[$filevarname];

        $dest_file = Files::makeFile($dest_dir, Files::escape($filename ? $filename : basename($up_file['name'])));
        if ($dest_file === false) {
            Files::lastErr(FILE_ERROR_NO_DESTINATION, _m('No destination file specified'));  // set error code
            return false;
        }

        // look if the uploaded file exists
        if (!is_uploaded_file($up_file['tmp_name'])) {
            Files::lastErr(FILE_ERROR_NOT_UPLOADED);  // set error code
            return false;
        }

        // look if type of file is allowed
        $file_type = (substr($type,-1)=='*') ? substr($type,0,strpos($type,'/')) : $type;

        if ((@strpos($up_file['type'],$file_type)===false) AND ($type!="")) {
            Files::lastErr(FILE_ERROR_TYPE_NOT_ALLOWED, _m('type of uploaded file not allowed'));  // set error code
            return false;
        }

        switch ($replacemethod) {
            case 'overwrite':
                // nothing to do - file is overwriten, if already exists
                break;
            case 'backup':
                if (Files::backupFile($dest_file) === false) {
                    return false;
                }
                break; // current file will be overwritten
            case 'new':
            default:
                // find new name for the file, if the file already exists
                $dest_file = Files::generateUnusedFilename($dest_file);
        }  // else - mode 'overwrite' - file is overwritten

        // copy the file from the temp directory to the upload directory, and test for success
        // (if the file already exists, move_uploaded_file will overwrite it!)
        if (!move_uploaded_file($up_file['tmp_name'], $dest_file)) {
            Files::lastErr(FILE_ERROR_TYPE_NOT_ALLOWED, _m("Can't move image  %1 to %2", array($up_file['tmp_name'], $dest_file)));  // set error code
            return false;
        }

        // now change permissions (if we have to)
        $perms = (int)IMG_UPLOAD_FILE_MODE;
        if ($perms AND !chmod($dest_file, $perms)) {
            Files::lastErr(FILE_ERROR_CHMOD, _m("Can't change permissions on uploaded file: %1 - %2. See IMG_UPLOAD_FILE_MODE in your config.php3", $dest_file, (int)IMG_UPLOAD_FILE_MODE));  // set error code
            return false;
        }

        return $dest_file;
    }

    /** Creates or rewrites file in slice's directory and stores there the $text
     */
    function createFileFromString(&$text, $dest_dir, $filename) {
        $dest_file = Files::makeFile($dest_dir, Files::escape($filename));
        if ($dest_file === false) {
            // lastErr is already set from destinationFile;
            return false;
        }

        if (!$handle = fopen($dest_file, 'w')) {
            Files::lastErr(FILE_ERROR_WRITE, _m("Can't open file for writing: %1", $dest_file));  // set error code
            return false;
        }

        // Write $somecontent to our opened file.
        if (fwrite($handle, $text) === false) {
            Files::lastErr(FILE_ERROR_WRITE, _m("Can't write to file: %1", $dest_file));  // set error code
            return false;
        }
        fclose($handle);

        return $dest_file;
    }

    function getTmpFilename($ident) {
        return $ident . "_" . md5(uniqid(rand(),1))  . "_" . date("mdY");
    }

    /** Delete all files with the format : {ident}_{hash20}_mmddyyyy older than
     *  7 days (used as temporary upload files)
     */
    function deleteTmpFiles($ident, $slice=null) {
        if ( !$slice ) {
            $upload_dir = IMG_UPLOAD_PATH. AA_ID;
        } else {
            $dir = $slice->getUploadBase();
            $upload_dir = $dir['path'];
        }
        if ($handle = opendir($upload_dir)) {
            while (false !== ($file = readdir($handle))) {
                if (strlen($ident)+42 != strlen($file) || (substr($file,0,strlen($file)-42) != $ident)) {
                    continue;
                }
                $date=mktime(0,0,0,date("m"),date("d")-7,date("Y")) ;
                $filedate = mktime (0,0,0,substr($file,-8,2) ,substr($file,-6,2),substr($file,-4,4));
                $fileName = Files::makeFile($upload_dir, $file);
                if ($filedate < $date) {
                    if (Files::delFile($fileName)) {
                        writeLog("FILE IMP.",_m("Ok : file deleted "). $fileName);
                    } else {
                        writeLog("FILE IMP.",_m("Error: Cannot delete file"). $fileName);
                    }
                }
            }
            closedir($handle);
        } else {
            writeLog("FILE IMP:",_m("Error: Invalid directory") .$upload_dir);
        }
    }


    /** Create backup copy of the file
     *  @returns whole filename of the backup file (or empty string, if
     *           the source file do not exists; returns false if backup fails
     */
    function backupFile($source) {
        if (!is_file($source)) {
            return "";
        }

        $destination = Files::generateUnusedFilename($source);
        if (copy($source, $destination)) {
            if (is_file($destination)) {
                return $destination;
            }
        }

        Files::lastErr(FILE_ERROR_COPY_FAILED, _m('can\'t create backup of the file'));  // set error code
        return false;
    }



    /**
     * Copy a file from source to destination. If unique == true, then if
     * the destination exists, it will be renamed by appending an increamenting
     * counting number.
     * @param string $source where the file is from, full path to the files required
     * @param string $destination_file name of the new file, just the filename
     * @param string $destination_dir where the files, just the destination dir,
     * e.g., /www/html/gallery/
     * @param boolean $unique create unique destination file if true.
     * @return string the new copied filename, else error if anything goes bad.
     */
    function copyFile($source, $destination_dir, $destination_file, $unique=true) {
        if (!(file_exists($source) && is_file($source))) {
            return FILE_ERROR_NO_SOURCE;
        }

        $destination_dir = Files::fixPath($destination_dir);

        if (!is_dir($destination_dir)) {
            return FILE_ERROR_DST_DIR_FAILED;
        }

        $destination = Files::makeFile($destination_dir, Files::escape($destination_file));

        if ($unique) {
            $destination = Files::generateUnusedFilename($destination);
        }

        if (!copy($source, $destination)) {
            return FILE_ERROR_COPY_FAILED;
        }

        //verify that it copied, new file must exists
        return is_file($destination) ? basename($destination) : FILE_ERROR_COPY_FAILED;
    }


    /**
     * Create a new folder.
     * @param string $newFolder specifiy the full path of the new folder.
     * @return boolean true if the new folder is created, false otherwise.
     */
    function createFolder($newFolder, $perms=0777) {
        mkdir($newFolder, $perms);
        return chmod($newFolder, $perms);
    }


    /**
     * Escape the filenames, any non-word characters will be
     * replaced by an underscore.
     * @param string $filename the orginal filename
     * @return string the escaped safe filename
     */
    function escape($filename) {
        return preg_replace('/[^\w\._]/', '_', $filename);
    }

    /**
     * Delete a file.
     * @param string $file file to be deleted
     * @return boolean true if deleted, false otherwise.
     */
    function delFile($file) {
        return is_file($file) ? unlink($file) : false;
    }

    /**
     * Delete folder(s), can delete recursively.
     * @param string $folder the folder to be deleted.
     * @param boolean $recursive if true, all files and sub-directories
     * are delete. If false, tries to delete the folder, can throw
     * error if the directory is not empty.
     * @return boolean true if deleted.
     */
    function delFolder($folder, $recursive=false) {
        $deleted = true;
        if ($recursive) {
            $d = dir($folder);
            while (false !== ($entry = $d->read()))	{
                if ($entry != '.' && $entry != '..') {
                    $obj = Files::fixPath($folder).$entry;
                    if (is_file($obj)) {
                        $deleted &= Files::delFile($obj);
                    } elseif (is_dir($obj))	{
                        $deleted &= Files::delFolder($obj, $recursive);
                    }
                }
            }
            $d->close();
        }

        $deleted &= (is_dir($folder) ? rmdir($folder) : false);

        return $deleted;
    }

    /**
     * Append a / to the path if required.
     * @param string $path the path
     * @return string path with trailing /
     */
    function fixPath($path) {
        //append a slash to the path if it doesn't exists.
        if (!(substr($path,-1) == '/')) {
            $path .= '/';
        }
        return $path;
    }

    /**
     * Concat two paths together. Basically $pathA+$pathB
     * @param string $pathA path one
     * @param string $pathB path two
     * @return string a trailing slash combinded path.
     */
    function makePath($pathA, $pathB) {
        $pathA = Files::fixPath($pathA);
        if (substr($pathB,0,1)=='/') {
            $pathB = substr($pathB,1);
        }
        return Files::fixPath($pathA.$pathB);
    }

    /**
     * Similar to makePath, but the second parameter
     * is not only a path, it may contain say a file ending.
     * @param string $pathA the leading path
     * @param string $pathB the ending path with file
     * @return string combined file path.
     */
    function makeFile($pathA, $pathB) {
        $pathA = Files::fixPath($pathA);
        if (substr($pathB,0,1)=='/') {
            $pathB = substr($pathB,1);
        }
        return $pathA.$pathB;
    }


    /**
     * Format the file size, limits to Mb.
     * @param int $size the raw filesize
     * @return string formated file size.
     */
    function formatSize($size) {
        if ($size < 1024) {
            return $size.' bytes';
        } elseif ($size >= 1024 && $size < 1024*1024) {
            return sprintf('%01.2f',$size/1024.0).' Kb';
        } else {
            return sprintf('%01.2f',$size/(1024.0*1024)).' Mb';
        }
    }

    /**
     * Returns type of the source
     * @param string $filename the name of file (with path, protocol, ...)
     * @return string FILE, HTTP, HTTPS, ...
     */
     function sourceType($filename) {
         if ( strtoupper(substr($filename,0,5)) == 'HTTPS') return 'HTTPS';
         if ( strtoupper(substr($filename,0,4)) == 'HTTP')  return 'HTTP';
         if ( strtoupper(substr($filename,0,3)) == 'FTP')   return 'FTP';
         return 'FILE';
     }
}

/**
 * AA_File_Wrapper class
 *
 * Copyright (c) 2003-2006 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 * (@see http://pkp.sfu.ca/ojs)
 *
 * Class abstracting operations for reading remote files using various protocols.
 * (for when allow_url_fopen is disabled).
 *
 * @todo:
 *     - Other protocols?
 *     - Write mode (where possible)
 *
 * Usage:  $file = &AA_File_Wrapper::wrapper($filename);
 *         if (!$file->open()) {
 *			   $result = false;
 *			   return $result;
 *		   }
 *		   while ($data = $file->read()) {
 *             //...
 *         }
 *		   $file->close();
 *
 */
class AA_File_Wrapper {

    /** @var $url string URL to the file */
    var $url;

    /** @var $info array parsed URL info */
    var $info;

    /** @var $fp int the file descriptor */
    var $fp;

    /**
     * Constructor.
     * @param $url string
     * @param $info array
     */
    function AA_File_Wrapper($url, &$info) {
        $this->url = $url;
        $this->info = $info;
    }

    /**
     * Read and return the contents of the file (like file_get_contents()).
     * @return string
     */
    function contents() {
        $contents = '';
        if ($this->open()) {
            while (!$this->eof()) {
                $contents .= $this->read();
            }
            $this->close();
        }
        return $contents;
    }

    /**
     * Open the file.
     * @param $mode string only 'r' (read-only) is currently supported
     * @return boolean
     */
    function open($mode = 'r') {
        $this->fp = null;
        $this->fp = fopen($this->url, $mode);
        return $this->fp;
    }

    /**
     * Close the file.
     */
    function close() {
        fclose($this->fp);
        unset($this->fp);
    }

    /**
     * Read from the file.
     * @param $len int
     * @return string
     */
    function read($len = 8192) {
        return fread($this->fp, $len);
    }

    /**
     * Check for end-of-file.
     * @return boolean
     */
    function eof() {
        return feof($this->fp);
    }


    //
    // Static
    //

    /**
     * Return instance of a class for reading the specified URL.
     * @param $url string
     * @return AA_File_Wrapper
     */
    function &wrapper($url) {
        $info = parse_url($url);
        if (ini_get('allow_url_fopen')) {
            $wrapper = &new AA_File_Wrapper($url, $info);
        } else {
            switch (@$info['scheme']) {
                case 'http':
                    $wrapper = &new AA_HTTP_File_Wrapper($url, $info);
                    break;
                case 'https':
                    $wrapper = &new AA_HTTPS_File_Wrapper($url, $info);
                    break;
                case 'ftp':
                    $wrapper = &new AA_FTP_File_Wrapper($url, $info);
                    break;
                default:
                    $wrapper = &new AA_File_Wrapper($url, $info);
            }
        }
        return $wrapper;
    }
}


/**
 * HTTP protocol class.
 */
class AA_HTTP_File_Wrapper extends AA_File_Wrapper {
    var $headers;
    var $defaultPort;
    var $defaultHost;
    var $defaultPath;

    function AA_HTTP_File_Wrapper($url, &$info) {
        parent::AA_File_Wrapper($url, $info);
        $this->setDefaultPort(80);
        $this->setDefaultHost('localhost');
        $this->setDefaultPath('/');
    }

    function setDefaultPort($port) {
        $this->defaultPort = $port;
    }

    function setDefaultHost($host) {
        $this->defaultHost = $host;
    }

    function setDefaultPath($path) {
        $this->defaultPath = $path;
    }

    function addHeader($name, $value) {
        if (!isset($this->headers)) {
            $this->headers = array();
        }
        $this->headers[$name] = $value;
    }

    function open($mode = 'r') {
        $host = isset($this->info['host']) ? $this->info['host'] : $this->defaultHost;
        $port = isset($this->info['port']) ? (int)$this->info['port'] : $this->defaultPort;
        $path = isset($this->info['path']) ? $this->info['path'] : $this->defaultPath;
        if (isset($this->info['query'])) $path .= '?' . $this->info['query'];

        if (!($this->fp = fsockopen($host, $port, $errno, $errstr))) {
            return false;
        }

        $additionalHeadersString = '';
        if (is_array($this->headers)) foreach ($this->headers as $name => $value) {
            $additionalHeadersString .= "$name: $value\r\n";
        }

        $request = "GET $path HTTP/1.0\r\n" .
            "Host: $host\r\n" .
            $additionalHeadersString .
            "Connection: Close\r\n\r\n";
        fwrite($this->fp, $request);

        $response = fgets($this->fp, 4096);
        $rc = 0;
        sscanf($response, "HTTP/%*s %u %*[^\r\n]\r\n", $rc);
        if ($rc == 200) {
            while(fgets($this->fp, 4096) !== "\r\n");
            return true;
        }
        $this->close();
        return false;
    }
}

/**
 * HTTPS protocol class.
 */
class AA_HTTPS_File_Wrapper extends AA_HTTP_File_Wrapper {
    function AA_HTTPS_File_Wrapper($url, &$info) {
        parent::AA_HTTP_File_Wrapper($url, $info);
        $this->setDefaultPort(443);
        $this->setDefaultHost('ssl://localhost');
        if (isset($this->info['host'])) {
            $this->info['host'] = 'ssl://' . $this->info['host'];
        }
    }
}

/**
 * FTP protocol class.
 */
class AA_FTP_File_Wrapper extends AA_File_Wrapper {

    var $ctrl;

    function open($mode = 'r') {
        $user = isset($this->info['user']) ? $this->info['user'] : 'anonymous';
        $pass = isset($this->info['pass']) ? $this->info['pass'] : 'user@example.com';
        $host = isset($this->info['host']) ? $this->info['host'] : 'localhost';
        $port = isset($this->info['port']) ? (int)$this->info['port'] : 21;
        $path = isset($this->info['path']) ? $this->info['path'] : '/';

        if (!($this->ctrl = fsockopen($host, $port, $errno, $errstr)))
            return false;

        if ($this->_open($user, $pass, $path))
            return true;

        $this->close();
        return false;
    }

    function close() {
        if ($this->fp) {
            parent::close();
            $rc = $this->_receive(); // FIXME Check rc == 226 ?
        }

        $this->_send('QUIT'); // FIXME Check rc == 221?
        $rc = $this->_receive();

        fclose($this->ctrl);
        $this->ctrl = null;
    }

    function _open($user, $pass, $path) {
        // Connection establishment
        if ($this->_receive() != '220')
            return false;

        // Authentication
        $this->_send('USER', $user);
        $rc = $this->_receive();
        if ($rc == '331') {
            $this->_send('PASS', $pass);
            $rc = $this->_receive();
        }
        if ($rc != '230')
            return false;

        // Binary transfer mode
        $this->_send('TYPE', 'I');
        if ($this->_receive() != '200')
            return false;

        // Enter passive mode and open data transfer connection
        $this->_send('PASV');
        if ($this->_receiveLine($line) != '227')
            return false;

        if (!preg_match('/(\d+),(\d+),(\d+),(\d+),(\d+),(\d+)/', $line, $matches))
            return false;
        list($tmp, $h1, $h2, $h3, $h4, $p1, $p2) = $matches;

        $host = "$h1.$h2.$h3.$h4";
        $port = ($p1 << 8) + $p2;

        if (!($this->fp = fsockopen($host, $port, $errno, $errstr)))
            return false;

        // Retrieve file
        $this->_send('RETR', $path);
        $rc = $this->_receive();
        if ($rc != '125' && $rc != '150')
            return false;

        return true;
    }

    function _send($command, $data = '') {
        return fwrite($this->ctrl, $command . (empty($data) ? '' : ' ' . $data) . "\r\n");
    }

    function _receive() {
        return $this->_receiveLine($line);
    }

    function _receiveLine(&$line) {
        do {
            $line = fgets($this->ctrl);
        } while($line !== false && ($tmp = substr(trim($line), 3, 1)) != ' ' && $tmp != '');

        if ($line !== false) {
            return substr($line, 0, 3);
        }
        return false;
    }
}

?>