<?php

declare(strict_types=1);

namespace AA\IO;

use AA\Http\Client;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException;
use InvalidArgumentException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The data file of the CSV import (admin/se_csv_import*.php). Step 1 stores the data - uploaded,
 * pasted or downloaded - in the slice's upload directory under a generated name, and only that
 * name travels to step 2, which reads the file and deletes it after Finish. A request therefore
 * cannot make the import read or delete any other file of the server.
 */
final class ImportFile
{
    /** start of the generated name (Files::getTmpFilename(), which also cleans up week-old ones) */
    public const PREFIX = 'importdata';

    /** whole download, seconds */
    private const FETCH_SECONDS = 120;

    /** largest download - it is held in memory before it is stored */
    private const FETCH_MAX_BYTES = 50 * 1024 * 1024;

    /**
     * Full path of the import file $name in $dir; null when $name is not such a file there.
     * realpath() also refuses a link of that name that leads out of the directory.
     */
    public static function path(string $dir, string $name): ?string
    {
        // realpath('') is the current directory
        if (($dir === '') || !preg_match('/^' . self::PREFIX . '_[0-9a-f]{32}_[0-9]{8}\z/', $name)) {
            return null;
        }
        $base = realpath($dir);
        $file = realpath($dir . '/' . $name);
        return (($base !== false) && ($file !== false) && (dirname($file) === $base) && is_file($file)) ? $file : null;
    }

    /**
     * Content of a CSV at an http(s) address; null when it cannot be read. Other schemes would let
     * the stream wrappers read local files (file://, php://filter).
     * @param ?HttpClientInterface $transport the connection under AA\Http\Client; another one for tests only
     * @param int                  $max_bytes larger data are refused
     */
    public static function fetch(string $url, ?HttpClientInterface $transport = null, int $max_bytes = self::FETCH_MAX_BYTES): ?string
    {
        if (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        try {
            $answer = (new Client(timeout: self::FETCH_SECONDS, idle: 30, redirects: 5, http: $transport, maxBytes: $max_bytes))->request('GET', $url);
        } catch (TransportException|ResponseTooLargeException|InvalidArgumentException) {   // no connection, too large, a malformed URL
            return null;
        }
        return $answer->ok() ? $answer->body : null;
    }
}
