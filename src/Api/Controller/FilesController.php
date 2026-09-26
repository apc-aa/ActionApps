<?php

declare(strict_types=1);

/**
 * Files of a slice for the rich-text editor: upload one, list them for the picker.
 * Replaces KCFinder, which let anybody upload, list and rename. Who may do what
 * decides AA\Upload\UploadPolicy; the X-AA-Upload-Token header (AA\Upload\UploadToken)
 * proves AA rendered the editor.
 *
 * @package Api
 */

namespace AA\Api\Controller;

use AA\Api\JsonResponse;
use AA\Api\SameHostGate;
use AA\Upload\UploadPolicy;
use AA\Upload\UploadToken;
use AA\Util\Imgfunc;
use AA\Util\LogOnce;
use AA_Log;
use AA_Slice;
use AA_Stringexpand_Img;
use Files;
use finfo;
use RuntimeException;

class FilesController
{
    use SameHostGate;

    /** log.type of a refused upload, once a day per slice and reason (AA\Optimize\CheckUploadRefusals) */
    public const EVENT_REFUSED = 'UPLOAD_NO';
    /** log.type of every anonymous upload: slice; file, size, address */
    public const EVENT_ANON = 'UPLOAD_ANO';
    /** the picker shows the newest files - a slice directory can hold thousands */
    public const LIST_MAX = 500;

    public function upload(): JsonResponse
    {
        if (!$this->sameHostRequest()) {
            return JsonResponse::error(401, 'Unauthorized');
        }
        // over post_max_size PHP drops the whole body: $_POST and $_FILES come empty
        if (!$_POST && ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0)) {
            return JsonResponse::error(413, _m('The file is too big'));
        }
        $slice_id = (string) ($_POST['slice'] ?? '');
        if (!is_long_id($slice_id)) {
            return JsonResponse::error(400, 'Bad request');
        }
        $mode = $this->mode($slice_id);
        if ($mode === UploadPolicy::NONE) {
            return $this->refuse($slice_id, 'rights', 403, _m('You may not upload files here. If you were editing, your login may have expired - reload the page.'));
        }
        $file  = $_FILES['file'] ?? null;
        $error = is_array($file) ? (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->refuse($slice_id, 'size', 413, _m('The file is too big'));
        }
        if (!is_array($file) || ($error !== UPLOAD_ERR_OK)) {
            return JsonResponse::error(400, _m('No file received'));
        }

        $name   = UploadPolicy::safeName($this->escape((string) $file['name']));
        $mime   = $this->mimeOf((string) $file['tmp_name']);
        $ext    = pathinfo($name, PATHINFO_EXTENSION);
        $reason = UploadPolicy::refusal($mode, $name, $mime, (int) $file['size']);
        if ($reason === 'type') {
            return $this->refuse($slice_id, "type $ext", 415, _m('Files of this type cannot be uploaded here: %1', [$ext]));
        }
        if ($reason === 'mime') {
            return $this->refuse($slice_id, "mime $mime", 415, _m('The content of the file does not match its extension %1', [$ext]));
        }
        if ($reason === 'size') {
            return $this->refuse($slice_id, 'size', 413, _m('The image is bigger than %1 MB', [UploadPolicy::ANON_MAX_BYTES >> 20]));
        }

        $dir = $this->destinationDir($slice_id);
        if ($dir === null) {
            return $this->refuse($slice_id, 'dir', 500, _m('The upload directory of the slice is not writable'));
        }
        try {
            $path = ($mode === UploadPolicy::ANON)
                ? $this->storeReencoded((string) $file['tmp_name'], $dir, pathinfo($name, PATHINFO_FILENAME) . '.' . UploadPolicy::anonExtension($mime))
                : $this->store($file, $dir, $name);
        } catch (RuntimeException $e) {
            return JsonResponse::error(500, $e->getMessage());
        }
        $stored = basename($path);
        $size   = (int) filesize($path);
        if ($mode === UploadPolicy::ANON) {
            $this->log(self::EVENT_ANON, $slice_id, [$stored, (string) $size, $this->ip()]);
        }
        $url = $this->urlOf($slice_id, $stored);
        return JsonResponse::ok(['location' => $url, 'url' => $url, 'name' => $stored, 'size' => $size, 'type' => $mime]);
    }

    public function list(): JsonResponse
    {
        if (!$this->sameHostRequest()) {
            return JsonResponse::error(401, 'Unauthorized');
        }
        $slice_id = (string) ($_GET['slice'] ?? '');
        if (!is_long_id($slice_id)) {
            return JsonResponse::error(400, 'Bad request');
        }
        if ($this->mode($slice_id) !== UploadPolicy::USER) {
            return JsonResponse::error(403, _m('Only editors of the slice can browse its files'));
        }
        $dir   = $this->destinationDir($slice_id);
        $files = ($dir === null) ? [] : self::filesIn($dir, ($_GET['kind'] ?? '') === 'image', (string) ($_GET['q'] ?? ''), self::LIST_MAX);
        $out   = [];
        foreach ($files as $f) {
            $url   = $this->urlOf($slice_id, $f['name']);
            $out[] = $f + ['url' => $url, 'thumb' => in_array($f['type'], UploadPolicy::IMAGES, true) ? $this->thumbOf($url) : ''];
        }
        return JsonResponse::ok(['files' => $out]);
    }

    /**
     * Files in $dir, newest first - no dot files, no directories.
     * @return list<array{name: string, size: int, mtime: int, type: string}>
     */
    public static function filesIn(string $dir, bool $images_only, string $q, int $limit): array
    {
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            $path = rtrim($dir, '/') . '/' . $name;
            if (str_starts_with($name, '.') || !is_file($path)) {
                continue;
            }
            $type = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (($images_only && !in_array($type, UploadPolicy::IMAGES, true)) || (($q !== '') && (mb_stripos($name, $q) === false))) {
                continue;
            }
            $files[] = ['name' => $name, 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path), 'type' => $type];
        }
        usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return array_slice($files, 0, $limit);
    }

    /** what this request may do: what its token offered, if the viewer may still do it */
    private function mode(string $slice_id): string
    {
        $viewer = $this->viewer($slice_id);
        if ($viewer === null) {
            return UploadPolicy::NONE;
        }
        $granted = UploadToken::verify((string) ($_SERVER['HTTP_X_AA_UPLOAD_TOKEN'] ?? ''), $slice_id, $this->uid(), time());
        return UploadPolicy::effective($granted, $viewer['editor'], $viewer['anonymous']);
    }

    private function refuse(string $slice_id, string $reason, int $status, string $message): JsonResponse
    {
        $this->logRefusal($slice_id, $reason);
        return JsonResponse::error($status, $message);
    }

    /** @return ?array{editor: bool, anonymous: bool} */
    protected function viewer(string $slice_id): ?array
    {
        return UploadPolicy::viewer($slice_id);
    }

    protected function uid(): string
    {
        return AuthGetUid();
    }

    protected function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    protected function escape(string $name): string
    {
        return Files::escape($name);
    }

    protected function mimeOf(string $file): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->file($file);
    }

    protected function destinationDir(string $slice_id): ?string
    {
        $slice = AA_Slice::getModule($slice_id);
        $dir   = ($slice instanceof AA_Slice) ? Files::destinationDir($slice) : false;
        return (is_string($dir) && is_writable($dir)) ? $dir : null;
    }

    /** @param array<string, mixed> $file one entry of $_FILES */
    protected function store(array $file, string $dir, string $name): string
    {
        $path = Files::uploadFile($file, $dir, '', 'new', $name);
        if ($path === false) {
            throw new RuntimeException(Files::lastErrMsg());
        }
        return $path;
    }

    protected function storeReencoded(string $tmp, string $dir, string $name): string
    {
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException(_m('No file received'));
        }
        $path  = Files::generateUnusedFilename(Files::makeFile($dir, $name));
        $error = Imgfunc::reencode($tmp, $path, UploadPolicy::ANON_MAX_PX);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
        $perms = defined('IMG_UPLOAD_FILE_MODE') ? (int) constant('IMG_UPLOAD_FILE_MODE') : 0;
        if ($perms) {
            chmod($path, $perms);
        }
        return $path;
    }

    protected function urlOf(string $slice_id, string $name): string
    {
        $slice = AA_Slice::getModule($slice_id);
        return ($slice instanceof AA_Slice) ? rtrim((string) $slice->getUploadBase()['url'], '/') . '/' . rawurlencode($name) : '';
    }

    protected function thumbOf(string $url): string
    {
        return AA_Stringexpand_Img::getUrl($url, 'w=160&h=120', 'abs_raw');
    }

    /** @param list<string> $params */
    protected function log(string $event, string $selector, array $params): void
    {
        AA_Log::write($event, $selector, $params);
    }

    protected function logRefusal(string $slice_id, string $reason): void
    {
        LogOnce::daily(self::EVENT_REFUSED, ParamImplode([$slice_id, $reason]), $this->ip());
    }
}
