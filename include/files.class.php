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

use AA\Util\Langs;
use AA\Util\LastErrTrait;
use AA\Util\Txt;

const FILE_ERROR_NO_SOURCE        = 100;
const FILE_ERROR_COPY_FAILED      = 101;
const FILE_ERROR_DST_DIR_FAILED   = 102;
const FILE_ERROR_NOT_UPLOADED     = 104;
const FILE_ERROR_TYPE_NOT_ALLOWED = 105; // type of uploaded file not allowed
const FILE_ERROR_DIR_CREATE       = 106; // can't create directory for image uploads
const FILE_ERROR_CHMOD            = 107;
const FILE_ERROR_WRITE            = 108;
const FILE_ERROR_NO_DESTINATION   = 109;
const FILE_ERROR_READ             = 110;
const FILE_COPY_OK                = 199;

const AA_DIRECTORY_WRAPPER_ERROR_NOT_DIR = 150;


class Files
{
    use LastErrTrait;

    /** Maximum length (bytes) of a filename produced by {@see escape()}.
     *  Nothing in AA used to bound it, so a caption pasted as a filename reached
     *  the disk in full (biom.cz has live uploads of 209 bytes). That breaks well
     *  beyond AA: MissCache builds its cache filename from the source name plus
     *  the phpThumb parameters and its own extension, so a ~200-byte source
     *  overflows NAME_MAX (255 bytes) and the thumbnail cannot be written at all;
     *  Windows caps MAX_PATH at 260 bytes for the whole path and eCryptfs caps
     *  NAME_MAX at 143, so such a file will not check out, zip or rsync
     *  everywhere. 140 bytes leaves MissCache room for its parameters and keeps
     *  the name portable, while still being far longer than any name a human
     *  types.
     */
    public const MAX_FILENAME_LENGTH = 140;

    /** Delimiter wrapped around the digest that replaces the elided middle in
     *  {@see shortenFilename()}. "__" and not "--" because
     *  ConvertCharset::escape() collapses "--" to "-" but leaves "_" alone, so
     *  escaping an already-elided name stays stable.
     */
    private const ELISION_MARKER = '__';

    /** Hex digits of the name digest kept between the elision markers. */
    private const ELISION_DIGEST_LENGTH = 6;

    /** destinationDir function
     *  Prepares slice directories for uploaded file and returns destination
     *  dir name
     *
     * @param ?AA_Slice $slice
     *
     * @return string|false
     */
    public static function destinationDir(?AA_Slice $slice): string|false
    {
        if (!$slice) {
            // self::lastErr(AA_DIRECTORY_WRAPPER_ERROR_NOT_DIR, _m("No slice specified"));  // could ba added this more info
            return false;
        }
        $upload = $slice->getUploadBase();
        return self::_destinationDirCreate((string) $upload['path'], (int) $upload['perms']);
    }

    /** aadestinationDir
     *  Prepares global AA directory for uploaded file and returns destination
     *  dir name
     */
    public static function aadestinationDir(): string|false
    {
        return self::_destinationDirCreate(IMG_UPLOAD_PATH . AA_ID, (int) IMG_UPLOAD_DIR_MODE);
    }

    /** _destinationDirCreate function
     *  Prepares directory for uploaded file and returns destination dir name
     *
     * @return string|false
     */
    public static function _destinationDirCreate(string $path, int $perms): string|false
    {
        if ($path === '') {
            self::lastErr(FILE_ERROR_NO_DESTINATION, _m('No destination directory for image uploads specified'));  // set error code
            return false;
        }
        if (!is_dir($path) && !self::createFolder($path, $perms)) {
            self::lastErr(FILE_ERROR_DIR_CREATE, _m("Can't create directory for image uploads"));
            return false;
        }
        return $path;
    }

    /** genereateUnusedFilename function
     *  checks, if the file is not exist and in case it exist, it finds similar
     *  file name which does not exist. If modificator specified, then it is
     *  added to fileneme (like '_thumb')
     * @param $file_name
     * @param $modificator
     * @return string
     */
    public static function generateUnusedFilename($file_name, $modificator = ''): string
    {
        $path_parts = pathinfo($file_name);

        // we have to process dot (extension do not contain it)
        // we must think about the files as 'test' or '.test' as well
        if (!str_contains($path_parts['basename'], '.')) {
            $extension = '';
            $base      = $path_parts['basename'] . $modificator;
        } else {
            $extension = '.' . $path_parts['extension'];
            $base = substr($path_parts['basename'], 0, -strlen($extension)) . $modificator;
        }
        $add = '';
        $i   = 0;
        while (file_exists($dest_file = self::makeFile($path_parts['dirname'], "$base$add$extension"))) {
            $add = '_' . (++$i);
        }
        return $dest_file;
    }

    /** getUploadedFile function
     *  Returns all content of the uploaded file as the string.
     *  The file is deleted after read
     *
     * @param  string  $filevarname  - name of form variable, where the file is stored
     *
     * @return false|string
     */
    public static function getUploadedFile(string $filevarname): false|string
    {
        $file_name  = self::getTmpFilename('tmp');

        // upload file - todo: error is not returned, if not exist

        if (($dest_file = self::uploadFile($filevarname, self::aadestinationDir(), '', 'overwrite', $file_name)) === false) {
            return false;  // error code is already set from Files::uploadFile()
        }

        if (($text = file_get_contents($dest_file)) === false) {
            self::lastErr(FILE_ERROR_READ, _m("Can't read the file %1", [$dest_file]));  // set error code
            return false;
        }

        // delete files older than one week in the img_upload directory
        self::deleteTmpFiles('tmp');

        return $text;
    }

    /** uploadFile function
     *  Uploads file to slice's directory
     *
     * @param $filevarname                      - name of form variable containing the uploaded data
     *                        (like "upfile") or array with uploaded file
     *                        parameters as provided by $_FILES
     * @param string|bool $dest_dir
     * @param $type                             - allowed file types (like 'image/jpeg', 'image/*')
     * @param $replacemethod                    - how to handle conflicts with existing file
     *                            'new'       - stored as new (unused) filename
     *                            'overwrite' - the old file is overwriten
     *                            'backup'    - old file is backuped to new
     *                                          (unused) filename
     * @param $filename                         - the name of file as you want to store it (if you
     *                        do not want to use original name)
     *
     * @return false|string
     */
    public static function uploadFile($filevarname, $dest_dir, $type = '', $replacemethod = 'new', $filename = null): false|string
    {
        $up_file = is_array($filevarname) ? $filevarname : $_FILES[$filevarname];

        if (!$dest_dir) {
            self::lastErr(FILE_ERROR_NO_DESTINATION, _m('No destination file specified'));  // set error code
            return false;
        }
        $dest_file = self::makeFile($dest_dir, self::escape($filename ?: basename($up_file['name'])));

        // look if the uploaded file exists
        if (!is_uploaded_file($up_file['tmp_name'])) {
            self::lastErr(FILE_ERROR_NOT_UPLOADED);  // set error code
            return false;
        }

        // look if type of file is allowed
        if ($type != '') {
            $file_type = (str_ends_with($type, '*')) ? substr($type, 0, strpos($type, '/')) : $type;

            if ((@strpos($up_file['type'], $file_type) === false)) {
                self::lastErr(FILE_ERROR_TYPE_NOT_ALLOWED, _m('type of uploaded file not allowed'));  // set error code
                return false;
            }
        }

        switch ($replacemethod) {
            case 'overwrite':
                // nothing to do - file is overwriten, if already exists
                break;
            case 'backup':
                if (self::backupFile($dest_file) === false) {
                    return false;
                }
                break; // current file will be overwritten
            case 'new':
            default:
                // find new name for the file, if the file already exists
                $dest_file = self::generateUnusedFilename($dest_file);
        }  // else - mode 'overwrite' - file is overwritten

        // copy the file from the temp directory to the upload directory, and test for success
        // (if the file already exists, move_uploaded_file will overwrite it!)
        if (!move_uploaded_file($up_file['tmp_name'], $dest_file)) {
            $last_error = error_get_last();
            self::lastErr(FILE_ERROR_COPY_FAILED, _m("Can't move image  %1 to %2 (%3)", [$up_file['tmp_name'], $dest_file, $last_error['message']]));  // set error code
            return false;
        }

        // now change permissions (if we have to)
        $perms = defined('IMG_UPLOAD_FILE_MODE') ? (int) IMG_UPLOAD_FILE_MODE : 0;
        if ($perms && !chmod($dest_file, $perms)) {
            self::lastErr(FILE_ERROR_CHMOD, _m("Can't change permissions on uploaded file: %1 - %2. See IMG_UPLOAD_FILE_MODE in your config.php", [$dest_file, $perms]));  // set error code
            return false;
        }

        return $dest_file;
    }

    /** createFileFromString function
     *  Creates or rewrites file in slice's directory and stores there the $text
     *
     * @param $text
     * @param $dest_dir
     * @param $filename
     *
     * @return false|string
     */
    public static function createFileFromString($text, $dest_dir, $filename): false|string
    {
        $dest_file = self::makeFile($dest_dir, self::escape($filename));
        if ($dest_file === '') {
            // lastErr is already set from destinationFile ?;
            return false;
        }

        if (!($handle = fopen($dest_file, 'wb'))) {
            self::lastErr(FILE_ERROR_WRITE, _m("Can't open file for writing: %1", [$dest_file]));  // set error code
            return false;
        }

        // Write $somecontent to our opened file.
        if (fwrite($handle, $text) === false) {
            self::lastErr(FILE_ERROR_WRITE, _m("Can't write to file: %1", [$dest_file]));  // set error code
            return false;
        }
        fclose($handle);

        return $dest_file;
    }

    /** getTmpFilename function
     * @param $ident
     * @return string
     */
    public static function getTmpFilename($ident): string
    {
        return $ident . "_" . hash('md5', uniqid('', true)) . "_" . date("mdY");
    }

    /** deleteTmpFiles function
     *  Delete all files with the format : {ident}_{hash20}_mmddyyyy older than
     *  7 days (used as temporary upload files)
     * @param $ident
     * @param $slice
     */
    public static function deleteTmpFiles($ident, $slice = null): void
    {
        if (!$slice) {
            $upload_dir = IMG_UPLOAD_PATH . AA_ID;
        } else {
            $dir = $slice->getUploadBase();
            $upload_dir = $dir['path'];
        }
        if ($handle = opendir($upload_dir)) {
            while (false !== ($file = readdir($handle))) {
                if (!str_starts_with($file, $ident)) {
                    continue;
                }
                if (strlen($ident) + 42 !== strlen($file)) {
                    continue;
                }
                $date = mktime(0, 0, 0, date("m"), date("d") - 7, date("Y")) ;
                $filedate = mktime(0, 0, 0, substr($file, -8, 2), substr($file, -6, 2), substr($file, -4, 4));
                $fileName = self::makeFile($upload_dir, $file);
                if ($filedate < $date) {
                    if (self::delFile($fileName)) {
                        AA_Log::write("FILE IMP.", '', new AA_Logentry_File(['outcome' => 'deleted', 'file' => $fileName]));
                    } else {
                        AA_Log::write("FILE IMP.", '', new AA_Logentry_File(['outcome' => 'delete failed', 'file' => $fileName]));
                    }
                }
            }
            closedir($handle);
        } else {
            AA_Log::write("FILE IMP:", '', new AA_Logentry_File(['outcome' => 'invalid directory', 'file' => $upload_dir]));
        }
    }


    /** backupFile function
     *  Create backup copy of the file
     *  @param string $source
     *  @return false|string - whole filename of the backup file (or empty string, if
     *           the source file do not exists; returns false if backup fails
     */
    public static function backupFile($source): false|string
    {
        if (!is_file($source)) {
            return "";
        }

        $destination = self::generateUnusedFilename($source);
        if (copy($source, $destination) && is_file($destination)) {
            return $destination;
        }

        self::lastErr(FILE_ERROR_COPY_FAILED, _m('can\'t create backup of the file'));  // set error code
        return false;
    }


    /** copyFile function
     * Copy a file from source to destination. If unique == true, then if
     * the destination exists, it will be renamed by appending an increamenting
     * counting number.
     *
     * @param  string   $source            where the file is from, full path to the files required
     * @param  string   $destination_dir   where the files, just the destination dir,
     *                                     e.g., /www/html/gallery/
     * @param  string   $destination_file  name of the new file, just the filename
     * @param  boolean  $unique            create unique destination file if true.
     *
     * @return int|string the new copied filename, else error if anything goes bad.
     */
    public function copyFile($source, $destination_dir, $destination_file, $unique = true): int|string
    {
        if (!(file_exists($source) && is_file($source))) {
            return FILE_ERROR_NO_SOURCE;
        }

        $destination_dir = self::fixPath($destination_dir);

        if (!is_dir($destination_dir)) {
            return FILE_ERROR_DST_DIR_FAILED;
        }

        $destination = self::makeFile($destination_dir, self::escape($destination_file));

        if ($unique) {
            $destination = self::generateUnusedFilename($destination);
        }

        if (!copy($source, $destination)) {
            return FILE_ERROR_COPY_FAILED;
        }

        //verify that it copied, new file must exists
        return is_file($destination) ? basename($destination) : FILE_ERROR_COPY_FAILED;
    }


    /**
     * Create a new folder.
     *
     * @param  string  $newFolder  specify the full path of the new folder.
     * @param  int     $perms
     *
     * @return boolean true if the new folder is created, false otherwise.
     */
    public static function createFolder(string $newFolder, int $perms = 0777): bool
    {
        if (!mkdir($newFolder, $perms) && !is_dir($newFolder)) {
            return false;
        }
        // chmod: the umask must not strip the group write bit. Keep the setgid bit the new
        // directory inherited, though - it hands the upload group down to what goes inside,
        // and the other PHP users sharing the uploads write there through that group
        return chmod($newFolder, $perms | (fileperms($newFolder) & 0o2000));
    }


    /** The _#name, _#ext and _#full aliases of a "Filename template" (AA_Inserter_Fil) for an
     *  uploaded file - escaped before they reach the template: the name is whatever the
     *  uploader sent (filler.php takes anonymous uploads), and "{...}" in it would otherwise
     *  be expanded as an AA expression by AA::Un()
     * @return array<string, string>
     */
    public static function nameTemplateAliases(string $file_name): array
    {
        return [
            '_#name' => self::escape(pathinfo($file_name, PATHINFO_FILENAME)),
            '_#ext'  => self::escape(pathinfo($file_name, PATHINFO_EXTENSION)),
            '_#full' => self::escape(pathinfo($file_name, PATHINFO_BASENAME)),
        ];
    }

    /** escape function
     * Escape the filenames, any non-word characters will be
     * replaced by an underscore.
     * @param string $filename the orginal filename
     * @return string the escaped safe filename
     */
    public static function escape($filename): string
    {
        return self::shortenFilename(ConvertCharset::singleton()->escape($filename, Langs::getCharset(), true));
    }

    /** shortenFilename function
     * Cap an already-escaped filename at {@see MAX_FILENAME_LENGTH} bytes by
     * eliding its MIDDLE, keeping the extension whole.
     *
     * The middle is dropped rather than the end because the tail of a filename
     * carries the more identifying part - a numbered series, an author, a variant
     * suffix - so cutting the end would collapse distinct uploads into one name.
     *
     * The dropped middle is replaced by a short digest of the whole name, not by
     * a fixed marker: without it any two names sharing their head and tail would
     * fold onto one filename, which loses the part that told them apart and -
     * for an upload field whose policy is "overwrite" or "backup"
     * ({@see uploadFile()}) - would let one upload replace an unrelated file.
     *
     * Only ever shortens: a name within the cap is returned untouched, and the
     * result is itself within the cap, so escaping an already-escaped name is
     * stable.
     *
     * @param string $filename escaped filename (output of ConvertCharset::escape())
     * @return string the filename, at most MAX_FILENAME_LENGTH bytes long
     */
    private static function shortenFilename(string $filename): string
    {
        if (strlen($filename) <= self::MAX_FILENAME_LENGTH) {
            return $filename;
        }

        // Split off a real extension only. The last dot of a long name is often
        // part of the text itself ("...klimatu.bez-ohledu-na-ge"), which must be
        // shortened like the rest, not preserved as an extension.
        $extension = '';
        $base      = $filename;
        $dot       = strrpos($filename, '.');
        if ($dot !== false && $dot > 0 && preg_match('/^[\da-z]{1,10}$/', substr($filename, $dot + 1))) {
            $extension = substr($filename, $dot);
            $base      = substr($filename, 0, $dot);
        }

        // Digest of the full name, so two names differing only in their (dropped)
        // middle never produce the same filename.
        $marker = self::ELISION_MARKER
            . substr(hash('sha256', $filename), 0, self::ELISION_DIGEST_LENGTH)
            . self::ELISION_MARKER;

        // Txt::shortenMiddle() counts graphemes while MAX_FILENAME_LENGTH is a byte
        // budget; the two coincide here because ConvertCharset::escape() has already
        // reduced $base to the ASCII set [0-9a-z._-].
        $shortened = Txt::shortenMiddle($base, self::MAX_FILENAME_LENGTH - strlen($extension), $marker);

        // Drop separators the cut left exposed beside the marker, so the result
        // keeps escape()'s shape (no run of separators, none at either end).
        return preg_replace('/[-.]*' . preg_quote($marker, '/') . '[-.]*/', $marker, $shortened) . $extension;
    }

    /** delFile function
     * Delete a file.
     * @param string $file file to be deleted
     * @return boolean true if deleted, false otherwise.
     */
    public static function delFile($file): bool
    {
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
    public function delFolder($folder, $recursive = false): bool
    {
        $deleted = true;
        if ($recursive) {
            $d = dir($folder);
            while (false !== ($entry = $d->read())) {
                if ($entry !== '.' && $entry !== '..') {
                    $obj = self::fixPath($folder) . $entry;
                    if (is_file($obj)) {
                        $deleted &= self::delFile($obj);
                    } elseif (is_dir($obj)) {
                        $deleted &= self::delFolder($obj, $recursive);
                    }
                }
            }
            $d->close();
        }

        return $deleted & (is_dir($folder) ? rmdir($folder) : false);
    }

    /**  Append a / to the path if required.
     * @param string $path the path
     * @return string path with trailing /
     */
    public static function fixPath($path): string
    {
        $path = (string) $path;
        //append a slash to the path if it doesn't exist.
        if (!(str_ends_with($path, '/'))) {
            $path .= '/';
        }
        return $path;
    }

    /** makeFile function
     * Similar to makePath, but the second parameter
     * is not only a path, it may contain say a file ending.
     * @param string $pathA the leading path
     * @param string $pathB the ending path with file
     * @return string combined file path.
     */
    public static function makeFile($pathA, $pathB): string
    {
        $pathA = self::fixPath($pathA);
        if (str_starts_with($pathB, '/')) {
            $pathB = substr($pathB, 1);
        }
        return $pathA . $pathB;
    }


    /** formatSize function
     * Format the file size, limits to Mb.
     * @param int $size the raw filesize
     * @return string formated file size.
     */
    public function formatSize($size): string
    {
        if ($size < 1024) {
            return $size . ' bytes';
        }

        if ($size < 1024 * 1024) {
            return sprintf('%01.2f', $size / 1024.0) . ' Kb';
        }

        return sprintf('%01.2f', $size / (1024.0 * 1024)) . ' Mb';
    }

    /** sourceType function
     * Returns type of the source
     * @param string $filename the name of file (with path, protocol, ...)
     * @return string FILE, HTTP, HTTPS, ...
     */
    public static function sourceType($filename): string
    {
        if (stripos($filename, 'HTTPS') === 0) {
            return 'HTTPS';
        }
        if (stripos($filename, 'HTTP') === 0) {
            return 'HTTP';
        }
        if (stripos($filename, 'FTP') === 0) {
            return 'FTP';
        }
        return 'FILE';
    }

    //****************  Secure file_get_content and readfile form https ***************/

    /**
     * Safe file_get_contents() variant (HTTPS + allowlist + size limit).
     */
    public static function safe_file_get_contents_https(string $url, array $allowedHosts = [], int $maxBytes = 0): string   // int $maxBytes = 5_000_000, int $timeoutSeconds = 10
    {
        self::safe_https_validate_url($url, $allowedHosts);

        $ctx = self::safe_https_stream_context(); // $timeoutSeconds

        $data = file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if ($data === false) {
            throw new RuntimeException('Download failed.');
        }
        if ($maxBytes && strlen($data) > $maxBytes) {
            throw new RuntimeException('Response too large.');
        }

        return $data;
    }

    /**
     * Safe readfile() variant (streams to output; HTTPS + allowlist + size limit).
     * @return int bytes streamed
     */
    public static function safe_readfile_https(string $url, array $allowedHosts = [], int $maxBytes = 0): int    // int $maxBytes = 10_000_000, int $timeoutSeconds = 10
    {
        self::safe_https_validate_url($url, $allowedHosts);

        $ctx = self::safe_https_stream_context();  // $timeoutSeconds

        $in = @fopen($url, 'rb', false, $ctx);
        if (!$in) {
            throw new RuntimeException('Failed to open URL.');
        }

        $bytes = 0;
        while (!feof($in)) {
            $chunk = fread($in, 8192);
            if ($chunk === false) {
                break;
            }

            $bytes += strlen($chunk);
            if ($maxBytes && $bytes > $maxBytes) {
                fclose($in);
                throw new RuntimeException('Response too large.');
            }

            echo $chunk;
        }

        fclose($in);
        return $bytes;
    }

    private static function safe_https_stream_context()
    {
        return stream_context_create([
            'http' => [
                'method' => 'GET',
                // 'timeout' => $timeoutSeconds,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
    }

    /** allow only safe https urls - no localhosts... */
    private static function safe_https_validate_url(string $url, array $allowedHosts): array
    {
        $u = parse_url($url);
        if (!$u || empty($u['scheme']) || empty($u['host'])) {
            throw new RuntimeException('Invalid URL.');
        }
        if (strtolower($u['scheme']) !== 'https') {
            throw new RuntimeException('Only https:// URLs are allowed.');
        }
        if (!empty($u['user']) || !empty($u['pass'])) {
            throw new RuntimeException('Credentials in URL are not allowed.');
        }

        $host = $u['host'];

        // IDN -> ASCII (optional)
        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                $host = $ascii;
            }
        }

        $host = strtolower(rtrim($host, '.'));
        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            throw new RuntimeException('Local hosts are not allowed.');
        }

        // Allowlist (recommended)
        if ($allowedHosts) {
            $allowed = array_map(fn($h) => strtolower(rtrim($h, '.')), $allowedHosts);
            if (!in_array($host, $allowed, true)) {
                throw new RuntimeException('Host not allowed.');
            }
        }

        // DNS resolve + block private/loopback/link-local/reserved
        $ips = self::safe_dns_resolve_ips($host);
        foreach ($ips as $ip) {
            if (self::is_disallowed_ip($ip)) {
                throw new RuntimeException("Disallowed target IP: {$ip}");
            }
        }

        $u['host']   = $host;
        $u['scheme'] = 'https';
        return $u;
    }

    private static function safe_dns_resolve_ips(string $host): array
    {
        // If host is already an IP literal, validate directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $recs = dns_get_record($host, DNS_A + DNS_AAAA);
        if (!$recs) {
            throw new RuntimeException('DNS resolution failed.');
        }

        $ips = [];
        foreach ($recs as $r) {
            if (!empty($r['ip'])) {
                $ips[] = $r['ip'];
            }   // A
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            } // AAAA
        }
        $ips = array_values(array_unique($ips));

        if (!$ips) {
            throw new RuntimeException('DNS returned no usable IPs.');
        }
        return $ips;
    }

    /** true = block (private/loopback/link-local/multicast/reserved...) */
    private static function is_disallowed_ip(string $ip): bool
    {
        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }

            // Blocks: 0/8, 10/8, 100.64/10, 127/8, 169.254/16, 172.16/12, 192.168/16,
            // plus documentation/reserved/multicast etc.
            $blocked = [
                ['0.0.0.0', 8],
                ['10.0.0.0', 8],
                ['100.64.0.0', 10],
                ['127.0.0.0', 8],
                ['169.254.0.0', 16],
                ['172.16.0.0', 12],
                ['192.0.0.0', 24],
                ['192.0.2.0', 24],
                ['192.168.0.0', 16],
                ['198.18.0.0', 15],
                ['198.51.100.0', 24],
                ['203.0.113.0', 24],
                ['224.0.0.0', 4],
                ['240.0.0.0', 4],
            ];

            foreach ($blocked as [$net, $mask]) {
                $netLong = ip2long($net);
                $m       = -1 << (32 - $mask);
                if (($long & $m) === ($netLong & $m)) {
                    return true;
                }
            }
            return false;
        }

        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);

            // Unspecified / loopback
            if ($lower === '::' || $lower === '::1') {
                return true;
            }

            // ULA fc00::/7 (covers fc.. and fd..)
            if (preg_match('/^(fc|fd)/', $lower)) {
                return true;
            }

            // Link-local fe80::/10 (rough check)
            if (str_starts_with($lower, 'fe80:')) {
                return true;
            }

            // Multicast ff00::/8
            if (str_starts_with($lower, 'ff')) {
                return true;
            }

            // Documentation 2001:db8::/32
            if (str_starts_with($lower, '2001:db8:')) {
                return true;
            }

            // IPv4-mapped IPv6 ::ffff:0:0/96 -> validate embedded IPv4 too
            if (str_starts_with($lower, '::ffff:')) {
                $v4 = substr($lower, 7);
                return !filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || self::is_disallowed_ip($v4);
            }

            return false;
        }

        return true;
    }
}

/**
 * AA_File_Wrapper class
 *
 * Copyright (c) 2003-2006 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 * @see https://gitlab.liu.se/edver36/pkp-lib/-/blob/ojs-3_0b1/classes/file/FileWrapper.inc.php
 * @see http://pkp.sfu.ca/ojs
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
class AA_File_Wrapper
{
    /** @var string URL to the file */
    public $url;
    /** @var array parsed URL info */
    public $info;
    /** @var resource the file descriptor */
    public $fp;
    /** @var int used in http */
    public $redirects = 5;

    /** AA_File_Wrapper function
     * Constructor.
     * @param $url string
     * @param $info array
     */
    public function __construct($url, $info)
    {
        $this->url = $url;
        $this->info = $info;
    }

    /**
     * Read and return the contents of the file (like file_get_contents()).
     * @return string
     */
    public function contents(): string
    {
        return trim($this->url) !== '' ? file_get_contents($this->url) : '';
    }

    /**
     * Open the file.
     *
     * @param $mode string only 'r' (read-only) is currently supported
     */
    public function open(string $mode = 'rb')
    {
        $this->fp = null;
        try {
            $this->fp = fopen($this->url, $mode);
        } catch (Throwable $e) {  // Exceptions and Errors
            return false;
        }
        return ($this->fp !== false);
    }

    /**
     * Close the file.
     */
    public function close(): void
    {
        fclose($this->fp);
        unset($this->fp);
    }
    /**
     * Read from the file.
     * @param $len int
     * @return string
     */
    public function read($len = 8192): string
    {
        return fread($this->fp, $len);
    }
    /**
     * Check for end-of-file.
     * @return boolean
     */
    public function eof(): bool
    {
        return feof($this->fp);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getMimeType(): false|string
    {
        return mime_content_type($this->fp);
    }

    //
    // Static
    //
    /**
     * Return instance of a class for reading the specified URL.
     *
     * @param $url
     *
     */
    public static function wrapper($url): AA_File_HTTPS_Wrapper|AA_File_Wrapper|AA_File_HTTP_Wrapper|AA_File_FTP_Wrapper
    {
        $info = parse_url($url);
        if (ini_get('allow_url_fopen')) {
            return new AA_File_Wrapper($url, $info);
        }
        return match (@$info['scheme']) {
            'http'  => new AA_File_HTTP_Wrapper($url, $info),
            'https' => new AA_File_HTTPS_Wrapper($url, $info),
            'ftp'   => new AA_File_FTP_Wrapper($url, $info),
            default => new AA_File_Wrapper($url, $info),
        };
    }
}

/** Just to have common content function for next wrappers */
class AA_File_Wrapper_Ext extends AA_File_Wrapper
{
    /**
     * Read and return the contents of the file (like file_get_contents()).
     * @return string
     */
    public function contents(): string
    {
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

}


/**
 * HTTP protocol class.
 *
 * for proxy (Proxy-Authorization) see https://gitlab.liu.se/edver36/pkp-lib/-/blob/ojs-stable-3_1_1/classes/file/wrappers/HTTPFileWrapper.inc.php?ref_type=heads
 */
class AA_File_HTTP_Wrapper extends AA_File_Wrapper_Ext
{
    public $headers;
    public $defaultPort;
    public $defaultHost;
    public $defaultPath;

    /** AA_File_HTTP_Wrapper function
     * @param $url
     * @param $info
     */
    public function __construct($url, $info)
    {
        parent::__construct($url, $info);
        $this->setDefaultPort(80);
        $this->setDefaultHost('localhost');
        $this->setDefaultPath('/');
    }
    /** setDefaultPort function
     * @param $port
     */
    public function setDefaultPort($port): void
    {
        $this->defaultPort = $port;
    }

    /** setDefaultHost function
     *
     * @param $host
     */
    public function setDefaultHost($host): void
    {
        $this->defaultHost = $host;
    }

    /** setDefaultPath function
     *
     * @param $path
     */
    public function setDefaultPath($path): void
    {
        $this->defaultPath = $path;
    }
    /** addHeader function
     * @param $name
     * @param $value
     */
    public function addHeader($name, $value): void
    {
        if (!isset($this->headers)) {
            $this->headers = [];
        }
        $this->headers[$name] = $value;
    }

    /**
     * Read and return the contents of the file (like file_get_contents()).
     * @return string
     */
    public function contents(): string
    {
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


    /** open function
     *
     * @param  string $mode
     *
     * @return AA_File_Wrapper|bool
     */
    public function open(string $mode = 'rb')
    {
        $host = $this->info['host'] ?? $this->defaultHost;
        $port = isset($this->info['port']) ? (int) $this->info['port'] : $this->defaultPort;
        $path = $this->info['path'] ?? $this->defaultPath;
        if (isset($this->info['query'])) {
            $path .= '?' . $this->info['query'];
        }
        if (!($this->fp = fsockopen($host, $port))) {
            return false;
        }

        $additionalHeadersString = '';
        if (is_array($this->headers)) {
            foreach ($this->headers as $name => $value) {
                $additionalHeadersString .= "$name: $value\r\n";
            }
        }

        $request = "GET $path HTTP/1.0\r\n"
            . "Host: $host\r\n"
            . $additionalHeadersString
            . "Connection: Close\r\n\r\n";
        fwrite($this->fp, $request);

        $response = fgets($this->fp, 4096);
        $rc = 0;
        sscanf($response, "HTTP/%*s %u %*[^\r\n]\r\n", $rc);
        if ($rc == 200) {
            while (fgets($this->fp, 4096) !== "\r\n") {
                ;
            }
            return true;
        }
        if (preg_match('!^3\d\d$!', $rc) && $this->redirects >= 1) {
            for ($response = '', $time = time(); !feof($this->fp) && $time >= time() - 15;) {
                $response .= fgets($this->fp, 128);
            }
            if (preg_match('!^(?:(?:Location)|(?:URI)|(?:location)): ([^\s]+)[\r\n]!m', $response, $matches)) {
                $this->close();
                $location = $matches[1];
                if (preg_match('!^[a-z]+://!', $location)) {
                    $this->url = $location;
                } else {
                    $newPath = ($this->info['path'] !== '' && !str_starts_with($location, '/') ? dirname($this->info['path']) . '/' : (str_starts_with($location, '/') ? '' : '/')) . $location;
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
    public function glue_url($parsed): false|string
    {
        // Thanks to php dot net at NOSPAM dot juamei dot com
        // See http://www.php.net/manual/en/function.parse-url.php
        if (!is_array($parsed)) {
            return false;
        }
        $uri = isset($parsed['scheme']) ? $parsed['scheme'] . ':' . ((strtolower($parsed['scheme']) === 'mailto') ? '' : '//') : '';
        $uri .= isset($parsed['user']) ? $parsed['user'] . ($parsed['pass'] ? ':' . $parsed['pass'] : '') . '@' : '';
        $uri .= $parsed['host'] ?? '';
        $uri .= isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $uri .= $parsed['path'] ?? '';
        $uri .= isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $uri .= isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        return $uri;
    }
}

/**
 * HTTPS protocol class.
 */
class AA_File_HTTPS_Wrapper extends AA_File_HTTP_Wrapper
{
    /** AA_File_HTTPS_Wrapper function
     * @param $url
     * @param $info
     */
    public function __construct($url, $info)
    {
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
class AA_File_FTP_Wrapper extends AA_File_Wrapper_Ext
{
    /** @var resource Control socket */
    public $ctrl;
    /**
     * Open the file.
     *
     * @param $mode string See fopen for mode string options.
     *
     * @return boolean True iff success.
     */
    public function open(string $mode = 'rb')
    {
        $user = $this->info['user'] ?? 'anonymous';
        $pass = $this->info['pass'] ?? 'user@example.com';
        $host = $this->info['host'] ?? 'localhost';
        $port = isset($this->info['port']) ? (int) $this->info['port'] : 21;
        $path = $this->info['path'] ?? '/';
        if (!($this->ctrl = fsockopen($host, $port))) {
            return false;
        }

        if ($this->_open($user, $pass, $path)) {
            return true;
        }

        $this->close();
        return false;
    }

    /**
     * Close an open file.
     */
    public function close(): void
    {
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
    public function _open($user, $pass, $path): bool
    {
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
        $port = ((int) $p1 << 8) + (int) $p2;
        if (!($this->fp = fsockopen($host, $port))) {
            return false;
        }

        // Retrieve file
        $this->_send('RETR', $path);
        $rc = $this->_receive();
        if ($rc == '125') {
            return true;
        }
        if ($rc == '150') {
            return true;
        }
        return false;
    }
    /**
     * Internal function to write to the connection.
     * @param $command string FTP command
     * @param $data string FTP data
     * @return boolean True iff success
     */
    public function _send($command, $data = ''): bool
    {
        return fwrite($this->ctrl, $command . (empty($data) ? '' : ' ' . $data) . "\r\n");
    }
    /**
     * Internal function to read a line from the connection.
     * @return string|false Resulting string, or false indicating error
     */
    public function _receive(): false|string
    {
        return $this->_receiveLine($line);
    }
    /**
     * Internal function to receive a line from the connection.
     * @param $line string Reference to receive read data
     * @return string|false
     */
    public function _receiveLine(&$line): string|false
    {
        do {
            $line = fgets($this->ctrl);
        } while ($line !== false && ($tmp = substr(trim($line), 3, 1)) !== ' ' && $tmp != '');

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
class AA_Directory_Wrapper
{
    use LastErrTrait;

    /** @var $url string URL to the directory */
    public $url;

    /** @var $info array parsed URL info */
    public $info;

    /** @var $dp int the directory descriptor */
    public $dp = null;

    /** @var $is_read bool specified if the content od directory is read */
    public $is_read = false;

    /** @var $subdir_names array of subdir names */
    public $subdir_names = [];

    /** @var $file_names array of file names */
    public $file_names = [];

    /** @var string $reg_file_filter filter applied to file names */
    public $reg_file_filter = false;

    /**
     * Constructor.
     * @param $url string
     * @param $info array
     */
    public function __construct($url, $info)
    {
        $this->url             = Files::fixPath($url);
        $this->info            = $info;
    }

    /** Repository of static variables (trick for PHP4)
     *  The trick for static class variables is used */
    public static function _setStatic($varname, $value, $set = true)
    {
        static $variables;
        if ($set) {
            $variables[$varname] = $value;
        }
        return $variables[$varname];
    }

    /** Repository of static variables (trick for PHP4)
     *  The trick for static class variables is used */
    public static function _getStatic($varname)
    {
        return self::_setStatic($varname, null, false);
    }

    /**
     * Open the dir.
     * @return int directory descriptor or false if $url id not directory
     */
    public function open()
    {
        if ($this->dp) {
            $this->close();
        }

        if (is_dir($this->url)) {
            $this->dp = opendir($this->url);
            return $this->dp;
        }

        self::lastErr(AA_DIRECTORY_WRAPPER_ERROR_NOT_DIR, $this->url . _m(": No such directory"));
        return false;
    }

    /**
     * Close the dir.
     */
    public function close(): void
    {
        if ($this->dp) {
            closedir($this->dp);
        }
        $this_dp = null;
    }

    /**
     * Read one item from dir.
     * @return string next file in directory
     */
    public function read(): string
    {
        return readdir($this->dp);
    }

    /**
     * Read whole content of directory to class arrays if needed, fill the class arrays
     */
    public function readWholeDir(): bool
    {
        if (!$this->is_read) {
            if ($this->open()) {
                while ($file_name = $this->read()) {
                    if (is_dir($this->url . $file_name)) {
                        if ($file_name !== "." && $file_name !== "..") {
                            $this->subdir_names[] = $file_name;
                        }
                    } elseif (is_file($this->url . $file_name)) {
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
    public function reloadWholeDir(): void
    {
        $this->is_read = false;
        $this->subdir_names = [];
        $this->file_names = [];
        $this->readWholeDir();
    }

    /**
     * Make file wrapper from file array
     * @return array of AA_File_Wrapper instances
     */
    public function makeFileWrappers(): array
    {
        foreach ($this->file_names as $file_name) {
            $return_array[] = AA_File_Wrapper::wrapper($file_name);
        }
        return $return_array;
    }

    /**
     * Make subdir wrapper from subdir array
     * @return AA_Directory_Wrapper[] instances
     */
    public function makeSubdirWrappers(): array
    {
        foreach ($this->subdir_names as $subdir_name) {
            $return_array[] = self::wrapper($subdir_name);
        }
        return $return_array;
    }

    /**
     * Read and return the contents of the directory.
     * @return false|AA_File_Wrapper[] instances
     */
    public function getFiles(): array|false
    {
        if ($this->readWholeDir()) {
            return $this->makeFileWrappers();
        }

        return false;
    }

    /**
     * Read and return wrapped subdirs
     * @return false|AA_Directory_Wrapper[] (type by my type)
     */
    public function getSubdirs(): false|array
    {
        if ($this->readWholeDir()) {
            return $this->makeSubdirWrappers();
        }

        return false;
    }

    /**
     * Read and return file names
     * @param bool $full_path
     * @return false|string[] - of strings file names
     */
    public function getFileNames($full_path = false): array|false
    {
        if ($this->readWholeDir()) {
            if ($full_path) {
                foreach ($this->file_names as $file_name) {
                    $return_array[] = $this->url . $file_name;
                }
                return $return_array;
            }

            return $this->file_names;
        }

        return false;
    }

    /**
     * Read and return filtered file names
     * @param $full_path
     * @return false|string[] - of strings filtered file names
     */
    public function getRegFilteredFileNames($full_path = false): array|false
    {
        if (!is_array($all_file_names = $this->getFileNames($full_path))) {
            return false;
        }
        if ($this->reg_file_filter) {
            $return_array = [];
            foreach ($all_file_names as $file_name) {
                if (preg_match('`' . str_replace('`', '\`', $this->reg_file_filter) . '`i', $file_name)) {
                    $return_array[] = $file_name;
                }
            }
            return $return_array;
        }

        return false;
    }

    /**
     * Read and return subdir names
     * @param $full_path
     * @return array of strings subdir names
     */
    public function getSubdirNames($full_path = false): array
    {
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
    public function getSubdirTree(): array
    {
        return $this->getSubdirNamesRecur();
    }

    /**
     * Read and return complete subdirtree - internal
     * @return array of arrays indexed by values
     */
    public function getSubdirNamesRecur(): array
    {
        $return_array = [];
        if ($this->readWholeDir()) {
            //compare if is it prefered subdir
            if ($this->url === self::_getStatic('needed_subdir_name')) {
                self::_setStatic('subdir_file_names', $this->file_names);
            }
            if (empty($this->subdir_names)) {
                return [];
            }

            foreach ($this->subdir_names as $subdir_name) {
                $subdir = self::wrapper($this->url . $subdir_name);
                $return_array[$subdir_name] = $subdir->getSubdirNamesRecur();
            }
            return $return_array;

        }

        return [];
    }

    /**
     * Return file names in specified directory
     * @return array of string
     */
    public function getSubdirFileNames(): array
    {
        return self::_getStatic('subdir_file_names');
    }

    /**
     * Set the $reg_file_filter variable
     * @param $filter string
     */
    public function setRegFileFilter($filter = false): void
    {
        $this->reg_file_filter = $filter;
    }

    /**
     * Set the $needed_subdir_name variable
     * @param $name string
     */
    public function setNeededSubdir($name): void
    {
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
    public static function &wrapper($url): AA_Directory_Wrapper
    {
        $info = parse_url($url);
        /*if (ini_get('allow_url_fopen')) {
            $wrapper = new AA_File_Wrapper($url, $info);
        } else {*/

        switch ($info['scheme'] ?? '') {   // a local path has no scheme
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
