<?php
/**
 *
 * File Utilities.
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/

DEFINE('FILE_ERROR_NO_SOURCE',        100);
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

    use \AA\Util\LastErrTrait;

    /** destinationDir function
     *  Prepares slice directories for uploaded file and returns destination
     *  dir name
     * @param $slice
     * @return bool
     */
    static function destinationDir($slice) {
        $upload = $slice->getUploadBase();
        return Files::_destinationDirCreate($upload['path'], $upload['perms']);
    }

    /** aadestinationDir
     *  Prepares global AA directory for uploaded file and returns destination
     *  dir name
     */
    static function aadestinationDir() {
        return Files::_destinationDirCreate(IMG_UPLOAD_PATH. AA_ID, (int)IMG_UPLOAD_DIR_MODE);
    }

    /** _destinationDirCreate function
     *  Prepares directory for uploaded file and returns destination dir name
     * @param $path
     * @param $perms
     * @return bool
     */
    static function _destinationDirCreate($path, $perms) {
        if (!$path OR !is_dir($path)) {
            if (!Files::createFolder($path, $perms)) {
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
     * @return string
     */
    static function generateUnusedFilename($file_name, $modificator='') {
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
     * @param string $filevarname - name of form variable, where the file is stored
     * @return bool|string
     */
    static function getUploadedFile($filevarname) {
        $file_name  = Files::getTmpFilename('tmp');

        // upload file - todo: error is not returned, if not exist

        if (($dest_file = Files::uploadFile($filevarname, Files::aadestinationDir(), '', 'overwrite', $file_name)) === false) {
            return false;  // error code is already set from Files::uploadFile()
        }

        if (($text = file_get_contents($dest_file)) === false) {
            Files::lastErr(FILE_ERROR_READ, _m("Can't read the file %1", [$dest_file]));  // set error code
            return false;
        }

        // delete files older than one week in the img_upload directory
        Files::deleteTmpFiles('tmp');

        return $text;
    }

    /** uploadFile function
     *  Uploads file to slice's directory
     * @param $filevarname - name of form variable containing the uploaded data
     *                        (like "upfile") or array with uploaded file
     *                        parameters as provided by $_FILES
     * @param $dest_dir
     * @param $type - allowed file types (like 'image/jpeg', 'image/*')
     * @param $replacemethod - how to handle conflicts with existing file
     *                            'new'       - stored as new (unused) filename
     *                            'overwrite' - the old file is overwriten
     *                            'backup'    - old file is backuped to new
     *                                          (unused) filename
     * @param $filename - the name of file as you want to store it (if you
     *                        do not want to use original name)
     * @return bool|string
     */
    static function uploadFile($filevarname, $dest_dir, $type='', $replacemethod='new', $filename=null) {
        $up_file = is_array($filevarname) ? $filevarname : $_FILES[$filevarname];

        $dest_file = Files::makeFile($dest_dir, Files::escape($filename ?: basename($up_file['name'])));
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
            Files::lastErr(FILE_ERROR_TYPE_NOT_ALLOWED, _m("Can't move image  %1 to %2", [$up_file['tmp_name'], $dest_file]));  // set error code
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
     * @return bool|string
     */
    static function createFileFromString($text, $dest_dir, $filename) {
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
     * @return string
     */
    static function getTmpFilename($ident) {
        return $ident . "_" . hash('md5', uniqid('',true)) . "_" . date("mdY");
    }

    /** deleteTmpFiles function
     *  Delete all files with the format : {ident}_{hash20}_mmddyyyy older than
     *  7 days (used as temporary upload files)
     * @param $ident
     * @param $slice
     */
    static function deleteTmpFiles($ident, $slice=null) {
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
                        AA_Log::write("FILE IMP.", '', _m("Ok : file deleted ") . $fileName);
                    } else {
                        AA_Log::write("FILE IMP.", '', _m("Error: Cannot delete file") . $fileName);
                    }
                }
            }
            closedir($handle);
        } else {
            AA_Log::write("FILE IMP:", '', _m("Error: Invalid directory") . $upload_dir);
        }
    }


    /** backupFile function
     *  Create backup copy of the file
     *  @param string $source
     *  @return false|string - whole filename of the backup file (or empty string, if
     *           the source file do not exists; returns false if backup fails
     */
    static function backupFile($source) {
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
    static function createFolder($newFolder, $perms=0777) {
        mkdir($newFolder, $perms);
        return chmod($newFolder, $perms);
    }


    /** escape function
     * Escape the filenames, any non-word characters will be
     * replaced by an underscore.
     * @param string $filename the orginal filename
     * @return string the escaped safe filename
     */
    static function escape($filename) {
        return ConvertCharset::singleton()->escape($filename, AA_Langs::getCharset(), true);
    }

    /** delFile function
     * Delete a file.
     * @param string $file file to be deleted
     * @return boolean true if deleted, false otherwise.
     */
    static function delFile($file) {
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
    static function fixPath($path) {
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
    static function makeFile($pathA, $pathB) {
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
     static function sourceType($filename) {
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

	/** @var string URL to the file */
    var $url;
	/** @var array parsed URL info */
    var $info;
	/** @var int the file descriptor */
    var $fp;
    /** @var int used in http */
    var $redirects;

    /** AA_File_Wrapper function
     * Constructor.
     * @param $url string
     * @param $info array
     */
	function __construct($url, $info) {
        $this->url = $url;
        $this->info = $info;
        $this->redirects = 5;
    }
	/**
     * Read and return the contents of the file (like file_get_contents()).
     * @return string
     */
    function contents() {
        $contents = '';
		if ($retval = $this->open()) {
			if (is_object($retval)) { // It may be a redirect
				return $retval->contents();
			}
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
        $this->fp = @fopen($this->url, $mode);
		return ($this->fp !== false);
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

    function getUrl(){
        return $this->url;
    }

    //
    // Static
    //
	/**
     * Return instance of a class for reading the specified URL.
	 * @param $source mixed; URL, filename, or resources
     * @return AA_File_Wrapper
     */
    static function wrapper($url) {
        $info = parse_url($url);
        if (ini_get('allow_url_fopen')) {
            $wrapper = new AA_File_Wrapper($url, $info);
        } else {
            switch (@$info['scheme']) {
                case 'http':
                    $wrapper = new AA_File_HTTP_Wrapper($url, $info);
                    break;
                case 'https':
                    $wrapper = new AA_File_HTTPS_Wrapper($url, $info);
                    break;
                case 'ftp':
                    $wrapper = new AA_File_FTP_Wrapper($url, $info);
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
class AA_File_HTTP_Wrapper extends AA_File_Wrapper {
    var $headers;
    var $defaultPort;
    var $defaultHost;
    var $defaultPath;

    /** AA_File_HTTP_Wrapper function
     * @param $url
     * @param $info
     */
	function __construct($url, $info) {
        parent::__construct($url, $info);
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
            $this->headers = [];
        }
        $this->headers[$name] = $value;
    }

    /** open function
     * @param $mode
     * @return AA_File_Wrapper|bool
     */
    function open($mode = 'r') {
        $host = isset($this->info['host']) ? $this->info['host'] : $this->defaultHost;
        $port = isset($this->info['port']) ? (int)$this->info['port'] : $this->defaultPort;
        $path = isset($this->info['path']) ? $this->info['path'] : $this->defaultPath;
        if (isset($this->info['query'])) {
            $path .= '?' . $this->info['query'];
        }
		if (!($this->fp = fsockopen($host, $port))) {
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
		if(preg_match('!^3\d\d$!', $rc) && $this->redirects >= 1) {
			for($response = '', $time = time(); !feof($this->fp) && $time >= time() - 15; ) $response .= fgets($this->fp, 128);
			if (preg_match('!^(?:(?:Location)|(?:URI)|(?:location)): ([^\s]+)[\r\n]!m', $response, $matches)) {
				$this->close();
				$location = $matches[1];
				if (preg_match('!^[a-z]+://!', $location)) {
					$this->url = $location;
				} else {
					$newPath = ($this->info['path'] !== '' && strpos($location, '/') !== 0  ? dirname($this->info['path']) . '/' : (strpos($location, '/') === 0 ? '' : '/')) . $location;
					$this->info['path'] = $newPath;
					$this->url = $this->glue_url($this->info);
				}
				$returner = AA_File_Wrapper::wrapper($this->url);
				$returner->redirects = $this->redirects - 1;
				return $returner;
			}
		}
        $this->close();
        return false;
    }
	function glue_url ($parsed)
    {
        // Thanks to php dot net at NOSPAM dot juamei dot com
        // See http://www.php.net/manual/en/function.parse-url.php
        if (!is_array($parsed)) return false;
        $uri = isset($parsed['scheme']) ? $parsed['scheme'] . ':' . ((strtolower($parsed['scheme']) == 'mailto') ? '' : '//') : '';
        $uri .= isset($parsed['user']) ? $parsed['user'] . ($parsed['pass'] ? ':' . $parsed['pass'] : '') . '@' : '';
        $uri .= isset($parsed['host']) ? $parsed['host'] : '';
        $uri .= isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $uri .= isset($parsed['path']) ? $parsed['path'] : '';
        $uri .= isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $uri .= isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        return $uri;
    }
}

/**
 * HTTPS protocol class.
 */
class AA_File_HTTPS_Wrapper extends AA_File_HTTP_Wrapper {
    /** AA_File_HTTPS_Wrapper function
     * @param $url
     * @param $info
     */
    function __construct($url, $info) {
        parent::__construct($url, $info);
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
class AA_File_FTP_Wrapper extends AA_File_Wrapper {
	/** @var Control socket */
    var $ctrl;
	/**
	 * Open the file.
	 * @param $mode string See fopen for mode string options.
	 * @return boolean True iff success.
     */
    function open($mode = 'r') {
        $user = isset($this->info['user']) ? $this->info['user'] : 'anonymous';
        $pass = isset($this->info['pass']) ? $this->info['pass'] : 'user@example.com';
        $host = isset($this->info['host']) ? $this->info['host'] : 'localhost';
        $port = isset($this->info['port']) ? (int)$this->info['port'] : 21;
        $path = isset($this->info['path']) ? $this->info['path'] : '/';
		if (!($this->ctrl = fsockopen($host, $port))) {
            return false;
        }

        if ($this->_open($user, $pass, $path)){
            return true;
        }

        $this->close();
        return false;
    }
	/**
	 * Close an open file.
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
	/**
	 * Internal function to open a connection.
	 * @param $user string Username
	 * @param $pass string Password
	 * @param $path string Path to file
	 * @return boolean True iff success
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
        [, $h1, $h2, $h3, $h4, $p1, $p2] = $matches;

        $host = "$h1.$h2.$h3.$h4";
        $port = ($p1 << 8) + $p2;
		if (!($this->fp = fsockopen($host, $port))) {
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
	/**
	 * Internal function to write to the connection.
	 * @param $command string FTP command
	 * @param $data string FTP data
	 * @return boolean True iff success
     */
    function _send($command, $data = '') {
        return fwrite($this->ctrl, $command . (empty($data) ? '' : ' ' . $data) . "\r\n");
    }
	/**
	 * Internal function to read a line from the connection.
	 * @return string|false Resulting string, or false indicating error
     */
    function _receive() {
        return $this->_receiveLine($line);
    }
	/**
	 * Internal function to receive a line from the connection.
	 * @param $line string Reference to receive read data
	 * @return string|false
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

    use \AA\Util\LastErrTrait;

    /** @var $url string URL to the directory */
    var $url;

    /** @var $info array parsed URL info */
    var $info;

    /** @var $dp int the directory descriptor */
    var $dp;

    /** @var $is_read bool specified if the content od directory is read */
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
    function __construct($url, $info) {
        $this->url             = Files::fixPath($url);
        $this->info            = $info;
        $this->dp              = NULL;
        $this->is_read         = false;
        $this->subdir_names    = [];
        $this->file_names      = [];
        $this->reg_file_filter = false;
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
        return self::_setStatic($varname, null, false);
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
                    elseif (is_file($this->url . $file_name)) {
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
        $this->subdir_names = [];
        $this->file_names = [];
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
     * @return AA_Directory_Wrapper[] instances
     */
    function makeSubdirWrappers() {
        foreach ($this->subdir_names as $subdir_name) {
            $return_array[] = AA_Directory_Wrapper::wrapper($subdir_name);
        }
        return $return_array;
    }

    /**
     * Read and return the contents of the directory.
     * @return false|AA_File_Wrapper[] instances
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
     * @return false|AA_Directory_Wrapper[] (type by my type)
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
     * @param bool $full_path
     * @return false|string[] - of strings file names
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
     * @return false|string[] - of strings filtered file names
     */
    function getRegFilteredFileNames($full_path = false) {
        if (!is_array($all_file_names = $this->getFileNames($full_path))) {
            return false;
        }
        if ($this->reg_file_filter) {
            $return_array = [];
            foreach ($all_file_names as $file_name) {
                if (preg_match('`'.str_replace('`','\`',$this->reg_file_filter).'`i', $file_name)) {
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
            }
            return $this->subdir_names;
        }
        return [];
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
        $return_array = [];
        if ($this->readWholeDir()) {
            //compare if is it prefered subdir
            if ($this->url == self::_getStatic('needed_subdir_name')) {
                self::_setStatic('subdir_file_names', $this->file_names);
            }
            if (empty($this->subdir_names)) {
                return [];
            }

            foreach ($this->subdir_names as $subdir_name) {
                $subdir = AA_Directory_Wrapper::wrapper($this->url . $subdir_name);
                $return_array[$subdir_name] = $subdir->getSubdirNamesRecur();
            }
            return $return_array;

        } else {
            return [];
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
     * @param string $url
     * @return AA_Directory_Wrapper directory wrapper (type by $url)
     */
    static function &wrapper($url) {
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


