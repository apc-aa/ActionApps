<?php
/**
 *
 * File Utilities.
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
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


class Files {
    /** lastErr function
     *  Method returns or sets last file error
     *  The trick for static class variables is used
     * @param $err_id
     * @param $err_msg
     * @param $getmsg
     */
    function lastErr($err_id = null, $err_msg = null, $getmsg = false) {
        static $lastErr;
        static $lastErrMsg;
        if (!is_null($err_id)) {
            $lastErr    = $err_id;
            $lastErrMsg = $err_msg;
        }
        return $getmsg ? $lastErrMsg : $lastErr;
    }

    /** lastErrMsg function
     *  Return last error message - it is grabbed from static variable
     *  of lastErr() method
     */
    function lastErrMsg() {
        return Files::lastErr(null, null, true);
    }

    /** destinationDir function
     *  Prepares slice directories for uploaded file and returns destination
     *  dir name
     * @param $slice
     */
    function destinationDir(&$slice) {
        $upload = $slice->getUploadBase();
        return Files::_destinationDirCreate($upload['path'], $upload['perms']);
    }

    /** aadestinationDir
     *  Prepares global AA directory for uploaded file and returns destination
     *  dir name
     */
    function aadestinationDir() {
        return Files::_destinationDirCreate(IMG_UPLOAD_PATH. AA_ID, (int)IMG_UPLOAD_DIR_MODE);
    }

    /** _destinationDirCreate function
     *  Prepares directory for uploaded file and returns destination dir name
     * @param $path
     * @param $perms
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

    /** genereateUnusedFilename function
     *  checks, if the file is not exist and in case it exist, it finds similar
     *  file name which do not exists. If modificator specified, then it is
     *  added to fileneme (like '_thumb')
     * @param $file_name
     * @param $modificator
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

    /** getUploadedFile function
     *  Returns all content of the uploaded file as the string.
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

    /** uploadFile function
     *  Uploads file to slice's directory
     *  @param $filevarname - name of form variable containing the uploaded data
     *                        (like "upfile") or array with uploaded file
     *                        parameters as provided by $_FILES
     *  @param $dest_dir
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
        $up_file = is_array($filevarname) ? $filevarname : $_FILES[$filevarname];

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
        if ($type != '') {
            $file_type = (substr($type,-1)=='*') ? substr($type,0,strpos($type,'/')) : $type;

            if ((@strpos($up_file['type'],$file_type)===false)) {
                Files::lastErr(FILE_ERROR_TYPE_NOT_ALLOWED, _m('type of uploaded file not allowed'));  // set error code
                return false;
            }
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

    /** createFileFromString function
     *  Creates or rewrites file in slice's directory and stores there the $text
     * @param $text
     * @param $dest_dir
     * @param $filename
     */
    function createFileFromString(&$text, $dest_dir, $filename) {
        $dest_file = Files::makeFile($dest_dir, Files::escape($filename));
        if ($dest_file === false) {
            // lastErr is already set from destinationFile;
            return false;
        }

        if (!($handle = fopen($dest_file, 'w'))) {
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
    /** getTmpFilename function
     * @param $ident
     */
    function getTmpFilename($ident) {
        return $ident . "_" . md5(uniqid(rand(),1))  . "_" . date("mdY");
    }

    /** deleteTmpFiles function
     *  Delete all files with the format : {ident}_{hash20}_mmddyyyy older than
     *  7 days (used as temporary upload files)
     * @param $ident
     * @param $slice
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
                        AA_Log::write("FILE IMP.",_m("Ok : file deleted "). $fileName);
                    } else {
                        AA_Log::write("FILE IMP.",_m("Error: Cannot delete file"). $fileName);
                    }
                }
            }
            closedir($handle);
        } else {
            AA_Log::write("FILE IMP:",_m("Error: Invalid directory") .$upload_dir);
        }
    }


    /** backupFile function
     *  Create backup copy of the file
     *  @param $source
     *  @return whole filename of the backup file (or empty string, if
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



    /** copyFile function
     * Copy a file from source to destination. If unique == true, then if
     * the destination exists, it will be renamed by appending an increamenting
     * counting number.
     * @param string $source where the file is from, full path to the files required
     * @param string $destination_file name of the new file, just the filename
     * @param string $destination_dir where the files, just the destination dir,
     *                  e.g., /www/html/gallery/
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


    /** createFolder function
     * Create a new folder.
     * @param string $newFolder specifiy the full path of the new folder.
     * @param $perms
     * @return boolean true if the new folder is created, false otherwise.
     */
    function createFolder($newFolder, $perms=0777) {
        mkdir($newFolder, $perms);
        return chmod($newFolder, $perms);
    }


    /** escape function
     * Escape the filenames, any non-word characters will be
     * replaced by an underscore.
     * @param string $filename the orginal filename
     * @return string the escaped safe filename
     */
    function escape($filename) {
        return preg_replace('/[^\w\._]/', '_', $filename);
    }

    /** delFile function
     * Delete a file.
     * @param string $file file to be deleted
     * @return boolean true if deleted, false otherwise.
     */
    function delFile($file) {
        return @is_file($file) ? @unlink($file) : false;
    }

    /** delFolder function
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

    /** fixPath function
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

    /** makePath function
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

    /** makeFile function
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


    /** formatSize function
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

    /** sourceType function
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

    /** AA_File_Wrapper function
     * Constructor.
     * @param $url string
     * @param $info array
     */
    function AA_File_Wrapper($url, &$info) {
        $this->url = $url;
        $this->info = $info;
    }

    /** contents function
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

    /** open function
     * Open the file.
     * @param $mode string only 'r' (read-only) is currently supported
     * @return boolean
     */
    function open($mode = 'r') {
        $this->fp = null;
        $this->fp = @fopen($this->url, $mode);
        return $this->fp;
    }

    /** close function
     * Close the file.
     */
    function close() {
        fclose($this->fp);
        unset($this->fp);
    }

    /** read function
     * Read from the file.
     * @param $len int
     * @return string
     */
    function read($len = 8192) {
        return fread($this->fp, $len);
    }

    /** eof function
     * Check for end-of-file.
     * @return boolean
     */
    function eof() {
        return feof($this->fp);
    }

    function getExif(){
        return new AA_Exif($this->url);
    }

    function getUrl(){
        return $this->url;
    }

    //
    // Static
    //

    /** &wrapper function
     * Return instance of a class for reading the specified URL.
     * @param $url string
     * @return AA_File_Wrapper
     */
    function &wrapper($url) {
        $info = parse_url($url);
        if (ini_get('allow_url_fopen')) {
            $wrapper = new AA_File_Wrapper($url, $info);
        } else {
            switch (@$info['scheme']) {
                case 'http':
                    $wrapper = new AA_HTTP_File_Wrapper($url, $info);
                    break;
                case 'https':
                    $wrapper = new AA_HTTPS_File_Wrapper($url, $info);
                    break;
                case 'ftp':
                    $wrapper = new AA_FTP_File_Wrapper($url, $info);
                    break;
                default:
                    $wrapper = new AA_File_Wrapper($url, $info);
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
    /** AA_HTTP_File_Wrapper function
     * @param $url
     * @param $info
     */
    function AA_HTTP_File_Wrapper($url, &$info) {
        parent::AA_File_Wrapper($url, $info);
        $this->setDefaultPort(80);
        $this->setDefaultHost('localhost');
        $this->setDefaultPath('/');
    }
    /** setDefaultPort function
     * @param $port
     */
    function setDefaultPort($port) {
        $this->defaultPort = $port;
    }
    /** setDefaultHost function
     * @param $port
     */
    function setDefaultHost($host) {
        $this->defaultHost = $host;
    }
    /** setDefaultPath function
     * @param $port
     */
    function setDefaultPath($path) {
        $this->defaultPath = $path;
    }
    /** addHeader function
     * @param $name
     * @param $value
     */
    function addHeader($name, $value) {
        if (!isset($this->headers)) {
            $this->headers = array();
        }
        $this->headers[$name] = $value;
    }
    /** open function
     * @param $mode
     */
    function open($mode = 'r') {
        $host = isset($this->info['host']) ? $this->info['host'] : $this->defaultHost;
        $port = isset($this->info['port']) ? (int)$this->info['port'] : $this->defaultPort;
        $path = isset($this->info['path']) ? $this->info['path'] : $this->defaultPath;
        if (isset($this->info['query'])) {
            $path .= '?' . $this->info['query'];
        }

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
    /** AA_HTTPS_File_Wrapper function
     * @param $url
     * @param $info
     */
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
    /** open function
     * @param $mode
     */
    function open($mode = 'r') {
        $user = isset($this->info['user']) ? $this->info['user'] : 'anonymous';
        $pass = isset($this->info['pass']) ? $this->info['pass'] : 'user@example.com';
        $host = isset($this->info['host']) ? $this->info['host'] : 'localhost';
        $port = isset($this->info['port']) ? (int)$this->info['port'] : 21;
        $path = isset($this->info['path']) ? $this->info['path'] : '/';

        if (!($this->ctrl = fsockopen($host, $port, $errno, $errstr))) {
            return false;
        }

        if ($this->_open($user, $pass, $path)){
            return true;
        }

        $this->close();
        return false;
    }
    /** close function
     *
     */
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
    /** _open function
     * @param $user
     * @param $pass
     * @param $path
     */
    function _open($user, $pass, $path) {
        // Connection establishment
        if ($this->_receive() != '220') {
            return false;
        }

        // Authentication
        $this->_send('USER', $user);
        $rc = $this->_receive();
        if ($rc == '331') {
            $this->_send('PASS', $pass);
            $rc = $this->_receive();
        }
        if ($rc != '230') {
            return false;
        }

        // Binary transfer mode
        $this->_send('TYPE', 'I');
        if ($this->_receive() != '200') {
            return false;
        }

        // Enter passive mode and open data transfer connection
        $this->_send('PASV');
        if ($this->_receiveLine($line) != '227') {
            return false;
        }

        if (!preg_match('/(\d+),(\d+),(\d+),(\d+),(\d+),(\d+)/', $line, $matches)) {
            return false;
        }
        list($tmp, $h1, $h2, $h3, $h4, $p1, $p2) = $matches;

        $host = "$h1.$h2.$h3.$h4";
        $port = ($p1 << 8) + $p2;

        if (!($this->fp = fsockopen($host, $port, $errno, $errstr))) {
            return false;
        }

        // Retrieve file
        $this->_send('RETR', $path);
        $rc = $this->_receive();
        if ($rc != '125' && $rc != '150') {
            return false;
        }

        return true;
    }
    /** _send function
     * @param $command
     * @param $data
     */
    function _send($command, $data = '') {
        return fwrite($this->ctrl, $command . (empty($data) ? '' : ' ' . $data) . "\r\n");
    }
    /** _receive function
     *
     */
    function _receive() {
        return $this->_receiveLine($line);
    }
    /** _receiveLine function
     * @param $line
     */
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

/**
 * AA_File_Info class
 *
 * Copyright (c) 2007 Jan Cerny
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 * (@see http://pkp.sfu.ca/ojs)
 *
 * Class abstracting getting of file metadata
 *
 * @todo:
 *
 * Usage:  $file_info = &AA_File_info::wrapper
 */

class AA_File_Info {
    /** @var $url string URL to the file */
    var $url;
    /** @var $size int bytes of the file */
    var $size;

    /**
     * Constructor.
     * @param $url string
     */
    function AA_File_Info($url) {
        $this->url = $url;
        $this->size = filesize($this->url);
    }

    /**
     * Read and return size of file
     * @return array of string
     */
    function getInfo() {
        return array('file_size' => $this->getSize(true));
    }

    /**
     * Read and return size of file
     * @param $optimize boolean
     * @return int / string file size without / with unit
     */
    function getSize($optimize = false) {
        if ($optimize) {
            if ($this->size < 1024) {
                return $this->size . ' bytes';
            } elseif ($this->size >= 1024 && $this->size < 1024*1024) {
                return sprintf('%01.2f',$this->size/1024.0).' Kb';
            } else {
                return sprintf('%01.2f',$this->size/(1024.0*1024)).' Mb';
            }
        } else {
            return $this->size;
        }
    }

    /**
     * Return instance of a class for reading the specified URL.
     * @param $url string
     * @return file info wrapper (type by $url)
     */
    function &wrapper($url) {
        $info = parse_url($url);
        /*if (ini_get('allow_url_fopen')) {
            $wrapper = new AA_File_Wrapper($url, $info);
        } else {*/
        switch (strtolower(substr($info['path'], strrpos($info['path'], '.')+1 ))) { //parse extension
            case 'jpg':
                    $wrapper = new AA_Jpg_Image_File_Info($url);
                    break;
            case 'gif':
            case 'png':
                    $wrapper = new  AA_Image_File_Info($url);
                default:
                    $wrapper = new AA_File_Info($url);
            }
        return $wrapper;
    }
}

/**
 * AA_Image_File_Info class
 */
class AA_Image_File_Info extends AA_File_Info {
    /** @var $img_height int height of image */
    var $img_height;
    /** @var $img_width int width of image */
    var $img_width;
    /** @var img_type string type of image according PHP IMAGETYPE_XXX constants */
    var $img_type;

    /**
     * Constructor.
     * @param $url string
     */
    function AA_Image_File_Info($url) {
        parent::AA_File_Info($url);
        //print_r(getimagesize($this->url));
        $size = getimagesize($this->url);
        list($this->img_width, $this->img_height) = $size;
        $this->img_type = $size['mime'];
    }

    /**
     * Read and return infor array
     * @return array of strings - infos
     */
    function getInfo() {
        $return_array = parent::getInfo();
        $return_array += array( 'img_height' => $this->getImgHeight(),
                                'img_width'  => $this->getImgWidth(),
                                'img_type'   => $this->getImgType());
        return $return_array;
    }

    function getImgHeight() {
        return $this->img_height;
    }

    function getImgWidth() {
        return $this->img_width;
    }

    function getImgType() {
        return $this->img_type;
    }
}

/**
 * AA_Jpg_Image_File_Info class
 */
class AA_Jpg_Image_File_Info extends AA_Image_File_Info {
    /** @var $make string Exif make name */
    var $make;
    /** @var $model string Exif model name */
    var $model;
    /** @var $exposure_time string Exif exposure time */
    var $exposure_time;
    /** @var $aperture string Exif aperture */
    var $aperture;
    /** @var $focal_length string Exif foca_length */
    var $focal_length;
    /** @var $data_taken string Exif data taken*/
    var $data_taken;
    /** @var $whole_exif string whole Exif as a string */
    var $whole_exif;
    /** @var $orientation int Exif orientation of image */
    var $orientation; //only for rotation

    /**
     * Constructor.
     * @param $url string
     */
    function AA_Jpg_Image_File_Info($url) {
        parent::AA_Image_File_Info($url);
        $this->exifParse();
    }

    /**
     * Read and return infor array
     * @return array of strings - infos
     */
    function getInfo() {
        $return_array = parent::getInfo();
        $return_array += array( 'make'          => $this->getMake(),
                                'model'         => $this->getModel(),
                                'exposure_time' => $this->getExposureTime(),
                                'aperture'      => $this->getAperture(),
                                'focal_length'  => $this->getFocalLength(),
                                'data_taken'    => $this->getDataTaken(),
                                'orientation'   => $this->getOrientation(),
                                'whole_exif'    => $this->getWholeExif());
        return $return_array;
    }

    /**
     * Read and parse the Exif of a picture
     * @return boolean wheather read
     */
    function exifParse() {
        if (!is_readable($this->url)) return false;

        //if ($this->img_type != IMAGETYPE_JPEG) return false; //not a jpg file

        $exif = exif_read_data($this->url,ANY_TAG,true);
        if (!$exif) return false;

        $this->exifParsed = array();

        if (isset($exif['IFD0']['Make'])) {
            $this->make = $exif['IFD0']['Make'];
        }
        if (isset($exif['IFD0']['Model'])) {
            $this->model = $exif['IFD0']['Model'];
        }

        if (isset($exif['COMPUTED']['ExposureTime'])) {
            $this->exposure_time = $exif['COMPUTED']['ExposureTime'];
        } elseif (isset($exif['EXIF']['ExposureTime'])){
            $this->exifParseFracval($exif['EXIF']['ExposureTime'], $num, $den);
            $exTime = $num / ($den ? $den : 1);
            if ($exTime <= 0.5 ) {
                $this->exposure_time = sprintf("%0.3f s (1/%d)", $exTime, 1/$exTime);
            } else {
                $this->exposure_time = sprintf("%3.2f s", $exTime);
            }
        }

        if (isset($exif['EXIF']['FNumber'])){
            $this->exifParseFracval($exif['EXIF']['FNumber'], $num, $den);
            $this->aperture = "f/".$num / ($den ? $den : 1);
        } elseif (isset($exif['COMPUTED']['ApertureFNumber'])) {
            $this->aperture = $exif['COMPUTED']['ApertureFNumber'];
        }

        if (isset($exif['EXIF']['FocalLength'])){
            $this->exifParseFracval($exif['EXIF']['FocalLength'], $num, $den);
            $this->focal_length = sprintf("%d mm", $num / ($den ? $den : 1));
        }

        if (isset($exif['EXIF']['DateTimeDigitized'])) {
            $this->data_taken = $exif['EXIF']['DateTimeDigitized'];
        }

        foreach ($exif as $key => $section) {
            foreach ($section as $name => $val) {
                $this->whole_exif .= "$key.$name: $val<br />\n";
            }
        }

        if (isset($exif['IFD0']['Orientation'])) {
            $this->orientation = $exif['IFD0']['Orientation'];
        }

        return true;
    }

    function exifParseFracval($val, &$num, &$den) {
       $num = intval(strtok($val, "/"));
       $den = intval(strtok("/"));
    }

    function getMake() {
        return $this->make;
    }

    function getModel() {
        return $this->model;
    }

    function getExposureTime() {
        return $this->exposure_time;
    }

    function getAperture() {
        return $this->aperture;
    }
    function getFocalLength() {
        return $this->focal_length;
    }

    function getDataTaken() {
        return $this->data_taken;
    }
    function getWholeExif() {
        return $this->whole_exif;
    }
    function getOrientation() {
        return $this->orientation;
    }
}

/**
 * AA_Image_Manipulator class
 *
 * Copyright (c) 2007 Jan Cerny
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 * (@see http://pkp.sfu.ca/ojs)
 *
 * Class abstracting manipulating with images
 *
 * @todo:
 *
 */

class AA_Image_Manipulator {
    /** @var $url string URL to the file */
    var $url;

    /**
     * Constructor.
     * @param $url string
     */
    function AA_Image_Manipulator($url) {
        $this->url = $url;
    }

    /**
     * Rotate image by given angle
     * @param $angle float angle of rotation
     * @param $bg_color int color of uncovered zone after rotation
     * @return return value of imagejpeg() function
     */
    function rotateIt($angle, $bg_color) {
        $rot_img = imagecreatefromjpeg($this->url);
        $rot_img = imagerotate($rot_img, $angle, $bg_color);
        $ret_val = imagejpeg($rot_img , $this->url);
        imagedestroy($rot_img);
        return $ret_val;
    }

    /**
     * Rotate image by given Exif orientation or read it themself
     * @param $orientation int Exif orientation
     * @return return value of $this->rotateIt() function
     */
    function rotateAccordingToExif($orientation = 0) {
        if (!is_readable($this->url)) return false;

        if ($orientation == 0) {
            $file_info = new AA_Jpg_Image_File_Info($this->url);
            $orientation = $file_info->getOrientation();
        }

        switch($orientation) {
            case 3: //if upper left
                $ret_val = $this->rotateIt(180, 0);
                break;
            case 6: //if lower right
               $ret_val = $this->rotateIt(270, 0);
               break;
            case 8: // end if upper right
               $ret_val = $this->rotateIt(90, 0);
               break;
            default: // no rotate needed
               $ret_val = false;
        }

        return $ret_val;
    }
}

/**
 * AA_Directory_Wrapper class
 *
 * Copyright (c) 2007 Jan Cerny
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 * (@see http://pkp.sfu.ca/ojs)
 *
 * Class abstracting operations for reading remote directories using various
 * protocols.
 *
 * @todo:
 *     - Other protocols?
 *     - adding slash is unix like / (not sure with at win)
 *     - where to check for posting .. (go up) as args
 *     - cache the scaned structure somewhere?
 *
 * Usage:  $dir = &AA_Directory_Wrapper::wrapper($dirname);
 *         if (!$dir->open()) {
 *			   $result = false;
 *			   return $result;
 *		   }
 *		   while ($data = $dir->read()) {
 *             //...
 *         }
 *		   $dir->close();
 *
 */
class AA_Directory_Wrapper {

    /** @var $url string URL to the directory */
    var $url;

    /** @var $info array parsed URL info */
    var $info;

    /** @var $dp int the directory descriptor */
    var $dp;

    /** @var $is_read boolean specified if the content od directory is read */
    var $is_read;

    /** @var $subdir_names array of subdir names */
    var $subdir_names;

    /** @var $file_names array of file names */
    var $file_names;

    /** @var $reg_file_filter aplied to file names */
    var $reg_file_filter;

    /**
     * Constructor.
     * @param $url string
     * @param $info array
     */
    function AA_Directory_Wrapper($url, &$info) {
        $this->url             = Files::fixPath($url);
        $this->info            = $info;
        $this->dp              = NULL;
        $this->is_read         = false;
        $this->subdir_names    = array();
        $this->file_names      = array();
        $this->reg_file_filter = false;
    }

    /** Method returns or sets last error
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
        return self::lastErr(null, null, true);
    }

    /** Repository of static variables (trick for PHP4)
     *  The trick for static class variables is used */
    function _setStatic($varname, $value, $set=true) {
        static $variables;
        if ( $set ) {
            $variables[$varname] = $value;
        }
        return $variables[$varname];
    }

    /** Repository of static variables (trick for PHP4)
     *  The trick for static class variables is used */
    function _getStatic($varname) {
        return _setStatic($varname, null, false);
    }

    /**
     * Open the dir.
     * @return int directory descriptor or false if $url id not directory
     */
    function open() {
        if ($this->dp) {
            $this->close();
        }

        if (is_dir ($this->url)) {
            $this->dp = opendir($this->url);
            return $this->dp;
        } else {
            self::lastErr(AA_DIRECTORY_WRAPPER_ERROR_NOT_DIR, $this->url . _m(": No such directory"));
            return false;
        }
    }

    /**
     * Close the dir.
     */
    function close() {
        if ($this->dp) {
            closedir($this->dp);
        }
        $this_dp = NULL;
    }

    /**
     * Read one item from dir.
     * @return string next file in directory
     */
    function read() {
        return readdir($this->dp);
    }

    /**
     * Read whole content of directory to class arrays if needed, fill the class arrays
     */
    function readWholeDir() {
        if (!$this->is_read) {
            if ($this->open()) {
                while ($file_name = $this->read()) {
                    if (is_dir($this->url . $file_name)) {
                        if ($file_name != "." && $file_name != "..") {
                            $this->subdir_names[] = $file_name;
                        }
                    }
                    elseif(is_file($this->url . $file_name)) {
                        $this->file_names[] = $file_name;
                    }
                }
            } else {
                return false;
            }
            $this->is_read = true;
            $this->close();
        }
        return true;
    }

    /**
     * Reload dir to class arrays
     */
    function reloadWholeDir() {
        $this->is_read = false;
        $this->subdir_names = array();
        $this->file_names = array();
        $this->readWholeDir();
    }

    /**
     * Make file wrapper from file array
     * @return array of AA_File_Wrapper instances
     */
    function makeFileWrappers() {
        foreach ($this->file_names as $file_name) {
            $return_array[] = AA_File_Wrapper::wrapper($file_name);
        }
        return $return_array;
    }

    /**
     * Make subdir wrapper from subdir array
     * @return array of AA_Directory_Wrapper instances
     */
    function makeSubdirWrappers() {
        foreach ($this->subdir_names as $subdir_name) {
            $return_array[] = AA_Directory_Wrapper::wrapper($subdir_name);
        }
        return $return_array;
    }

    /**
     * Read and return the contents of the directory.
     * @return array of AA_File_Wrapper instances
     */
    function getFiles() {
        if ($this->readWholeDir()) {
            return $this->makeFileWrappers();
        } else {
            return false;
        }
    }

    /**
     * Read and return wrapped subdirs
     * @return array of directory wrappers (type by my type)
     */
    function getSubdirs() {
        if ($this->readWholeDir()) {
            return $this->makeSubdirWrappers();
        } else {
            return false;
        }
    }

    /**
     * Read and return file names
     * @param $full_path
     * @return array of strings file names
     */
    function getFileNames($full_path = false) {
        if ($this->readWholeDir()) {
            if ($full_path) {
                foreach ($this->file_names as $file_name) {
                    $return_array[] = $this->url . $file_name;
                }
                return $return_array;
            } else {
                return $this->file_names;
            }
        } else {
            return false;
        }
    }

    /**
     * Read and return filtered file names
     * @param $full_path
     * @return array of strings filtered file names
     */
    function getRegFilteredFileNames($full_path = false) {
        if (!is_array($all_file_names = $this->getFileNames($full_path))) {
            return false;
        }
        if ($this->reg_file_filter) {
            $return_array = array();
            foreach ($all_file_names as $file_name) {
                if (eregi($this->reg_file_filter, $file_name)) {
                    $return_array[] = $file_name;
                }
            }
            return $return_array;
        } else {
            return false;
        }
    }

    /**
     * Read and return subdir names
     * @param $full_path
     * @return array of strings subdir names
     */
    function getSubdirNames($full_path = false) {
        if ($this->readWholeDir()) {
            if ($full_path) {
                foreach ($this->subdir_names as $subdir_name) {
                    $return_array[] = $this->url . $subdir_name;
                }
                return $return_array;
            } else {
                return $this->subdir_names;
            }
        } else {
            return false;
        }
    }

    /**
     * Read and return complete subdirtree
     * @return array of arrays indexed by values
     */
    function getSubdirTree() {
        return $this->getSubdirNamesRecur();
    }

    /**
     * Read and return complete subdirtree - internal
     * @return array of arrays indexed by values
     */
    function  getSubdirNamesRecur() {
        if ($this->readWholeDir()) {
            //compare if is it prefered subdir
            if ($this->url == self::_getStatic('needed_subdir_name')) {
                self::_setStatic('subdir_file_names', $this->file_names);
            }
            if (empty($this->subdir_names)) {
                return array();
            }

            foreach ($this->subdir_names as $subdir_name) {
                $subdir = AA_Directory_Wrapper::wrapper($this->url . $subdir_name);
                $return_array[$subdir_name] = $subdir->getSubdirNamesRecur();
            }
            return $return_array;

        } else {
            return false;
        }
    }

    /**
     * Return file names in specified directory
     * @return array of string
     */
    function getSubdirFileNames() {
        return self::_getStatic('subdir_file_names');
    }

    /**
     * Set the $reg_file_filter variable
     * @param $filter string
     */
    function setRegFileFilter($filter = false) {
        $this->reg_file_filter = $filter;
    }

    /**
     * Set the $needed_subdir_name variable
     * @param $name string
     */
    function setNeededSubdir($name) {
        self::_setStatic('needed_subdir_name', $name);
    }

    //
    // Static
    //

    /**
     * Return instance of a class for reading the specified URL.
     * @param $url string
     * @return directory wrapper (type by $url)
     */
    function &wrapper($url) {
        $info = parse_url($url);
        /*if (ini_get('allow_url_fopen')) {
            $wrapper = new AA_File_Wrapper($url, $info);
        } else {*/

            switch (@$info['scheme']) {
                case 'ftp':
                    echo "ftp directory not yet implemented\n";
                    //$wrapper = new AA_FTP_Directory_Wrapper($url, $info);
                    break;
                default:
                    $wrapper = new AA_Directory_Wrapper($url, $info);
            }
        return $wrapper;
    }
}

?>
