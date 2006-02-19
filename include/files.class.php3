<?
/**
 * File Utilities.
 * @author $Author$
 * @version $Id$
 * @package ImageManager
 */

define('FILE_ERROR_NO_SOURCE', 100);
define('FILE_ERROR_COPY_FAILED', 101);
define('FILE_ERROR_DST_DIR_FAILED', 102);
define('FILE_ERROR_NOT_UPLOADED', 104);
define('FILE_ERROR_TYPE_NOT_ALLOWED', 105); // type of uploaded file not allowed
define('FILE_ERROR_DIR_CREATE', 106);       // can't create directory for image uploads
define('FILE_ERROR_CHMOD', 107);
define('FILE_ERROR_WRITE', 108);
define('FILE_ERROR_NO_DESTINATION', 109);
define('FILE_ERROR_READ', 110);
define('FILE_COPY_OK', 199);


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

    /** Get the base for the uploads */
    function getUploadBase(&$slice) {
        $ret = array();
        $fileman_dir = $slice->getfield('fileman_dir');
        if ($fileman_dir AND is_dir(FILEMAN_BASE_DIR.$fileman_dir)) {
            $ret['path']  = FILEMAN_BASE_DIR.$fileman_dir."/items";
            $ret['perms'] = $GLOBALS['FILEMAN_MODE_DIR'];
        } else {
            // files are copied to subdirectory of IMG_UPLOAD_PATH named as slice_id
            $ret['path']  = IMG_UPLOAD_PATH. $slice->unpacked_id();
            $ret['perms'] = (int)IMG_UPLOAD_DIR_MODE;
        }
        return $ret;
    }

    /** Try to transform file path to file url - based on setting of file
     *  uploads or filemanager */
    function getUrlFromPath($filename) {
        if (strpos($filename, IMG_UPLOAD_PATH) === 0) {
            return IMG_UPLOAD_URL. substr($filename,strlen(IMG_UPLOAD_PATH));
        }
        if (strpos($filename, FILEMAN_BASE_DIR) === 0) {
            return FILEMAN_BASE_URL. substr($filename,strlen(FILEMAN_BASE_DIR));
        }
        return $filename;
    }

    /** Prepares slice directories for uploaded file and returns destination
     *  dir name
     */
    function destinationDir(&$slice) {
        return Files::_destinationDirCreate(Files::getUploadBase($slice));
    }

    /** Prepares global AA directory for uploaded file and returns destination
     *  dir name
     */
    function aadestinationDir() {
        $dest_dir['path']  = IMG_UPLOAD_PATH. AA_ID;
        $dest_dir['perms'] = (int)IMG_UPLOAD_DIR_MODE;
        return Files::_destinationDirCreate($dest_dir);
    }

    /** Prepares directory for uploaded file and returns destination dir name
     */
    function _destinationDirCreate($dest_dir) {
        if (!$dest_dir['path'] OR !is_dir($dest_dir['path'])) {
            if (!Files::CreateFolder($dest_dir['path'], $dest_dir['perms'])) {
                Files::lastErr(FILE_ERROR_DIR_CREATE, _m("Can't create directory for image uploads"));  // set error code
                return false;
            }
        }
        return $dest_dir['path'];
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
            Files::lastErr(FILE_ERROR_TYPE_NOT_ALLOWED, _m("Can't move image  %1 to %2", $up_file['tmp_name'], $dest_file));  // set error code
            return false;
        }

        // now change permissions (if we have to)
        $parms = (int)IMG_UPLOAD_FILE_MODE;
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
            $dir = Files::getUploadBase($slice);
            $upload_dir = $dir['path'];
        }
        if ($handle = opendir($upload_dir)) {
            while (false !== ($file = readdir($handle))) {
                if (strlen($ident)+42 != strlen($file) || (substr($file,0,strlen($file)-42) != $ident))
                    continue;
                $date=mktime(0,0,0,date("m"),date("d")-7,date("Y")) ;
                $filedate = mktime (0,0,0,substr($file,-8,2) ,substr($file,-6,2),substr($file,-4,4));
                $fileName = $upload_dir . $file;
                if ($filedate < $date) {
                    if (unlink($fileName)) {
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
    function copyFile($source, $destination_dir, $destination_file, $unique=true)
    {
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
    function createFolder($newFolder, $perm=0777)
    {
        mkdir ($newFolder, $perm);
        return chmod($newFolder, $perm);
    }


    /**
     * Escape the filenames, any non-word characters will be
     * replaced by an underscore.
     * @param string $filename the orginal filename
     * @return string the escaped safe filename
     */
    function escape($filename)
    {
        return preg_replace('/[^\w\._]/', '_', $filename);
    }

    /**
     * Delete a file.
     * @param string $file file to be deleted
     * @return boolean true if deleted, false otherwise.
     */
    function delFile($file)
    {
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
    function delFolder($folder, $recursive=false)
    {
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
    function fixPath($path)
    {
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
    function makePath($pathA, $pathB)
    {
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
    function makeFile($pathA, $pathB)
    {
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
    function formatSize($size)
    {
        if ($size < 1024) {
            return $size.' bytes';
        } elseif ($size >= 1024 && $size < 1024*1024) {
            return sprintf('%01.2f',$size/1024.0).' Kb';
        } else {
            return sprintf('%01.2f',$size/(1024.0*1024)).' Mb';
        }
    }
}

?>