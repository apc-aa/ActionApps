<?php

declare(strict_types=1);

/**
 * Files of a slice for the rich-text editor: upload one, list them for the picker, and hand
 * an editor written in a template the token AA would have rendered with it.
 * Who may do what decides AA\Upload\UploadPolicy; the X-AA-Upload-Token header (AA\Upload\UploadToken)
 * proves the request comes from a page of this AA.
 *
 * @package Api
 */

namespace AA\Api\Controller;

use AA\Api\JsonResponse;
use AA\Api\SameHostGate;
use AA\IO\DB\DB_AA;
use AA\Upload\RefusedImage;
use AA\Upload\UploadPolicy;
use AA\Upload\UploadToken;
use AA\Util\Imgfunc;
use AA\Widget\RichtextMarkup;
use AA\Util\LogOnce;
use AA_Log;
use AA_Logentry;
use AA_Logentry_Un;
use AA_Logentry_Unv;
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
    /** anonymous uploads an hour: the token of a public page is public, and every
     *  re-encode costs a decoded canvas of server memory */
    public const ANON_PER_IP_HOUR    = 20;
    public const ANON_PER_SLICE_HOUR = 200;

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
        $viewer = $this->viewer($slice_id);
        if ($viewer === null) {
            // not logged: random ids would fill the log and push real findings out of State Check
            return JsonResponse::error(404, 'Unknown slice');
        }
        $mode = $this->mode($slice_id, $viewer);
        if ($mode === UploadPolicy::NONE) {
            return $this->refuse($slice_id, 'rights', 403, _m('You may not upload files here. If you were editing, your login may have expired - reload the page.'));
        }
        if (($mode === UploadPolicy::ANON) && $this->anonLimited($slice_id)) {
            return $this->refuse($slice_id, 'rate', 429, _m('Too many uploads - try again in an hour'), $this->ip());
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
            return $this->refuse($slice_id, 'type', 415, _m('Files of this type cannot be uploaded here: %1', [$ext]), $ext);
        }
        if ($reason === 'mime') {
            return $this->refuse($slice_id, 'mime', 415, _m('The content of the file does not match its extension %1', [$ext]), "$ext $mime");
        }
        if ($reason === 'size') {
            return $this->refuse($slice_id, 'size', 413, _m('The image is bigger than %1 MB', [UploadPolicy::ANON_MAX_BYTES >> 20]));
        }

        $dir = $this->destinationDir($slice_id);
        if ($dir === null) {
            return $this->refuse($slice_id, 'dir', 500, _m('The upload directory of the slice is not writable'));
        }
        try {
            // an anonymous file gets a name its uploader cannot choose - no password-reset.jpg
            // on the organisation's domain
            $path = ($mode === UploadPolicy::ANON)
                ? $this->storeReencoded((string) $file['tmp_name'], $dir, bin2hex(random_bytes(8)) . '.' . UploadPolicy::anonExtension($mime))
                : $this->store($file, $dir, $name);
        } catch (RefusedImage $e) {
            // the visitor's file - too big a canvas, damaged, or not the image its type says
            return ($e->reason === 'size')
                ? $this->refuse($slice_id, 'size', 413, _m('The image has more than %1 megapixels', [UploadPolicy::ANON_MAX_PIXELS / 1000000]))
                : $this->refuse($slice_id, 'image', 415, $e->getMessage(), $e->getMessage());
        } catch (RuntimeException $e) {
            // the message names server paths: for State Check, not for the browser
            return $this->refuse($slice_id, 'store', 500, _m('The file could not be stored'), $e->getMessage());
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
        $viewer = $this->viewer($slice_id);
        if (($viewer === null) || ($this->mode($slice_id, $viewer) !== UploadPolicy::USER)) {
            return JsonResponse::error(403, _m('Only editors of the slice can browse its files'));
        }
        $dir   = $this->destinationDir($slice_id);
        $files = ($dir === null) ? [] : self::filesIn($dir, ($_GET['kind'] ?? '') === 'image', (string) ($_GET['q'] ?? ''), self::LIST_MAX);
        $out   = [];
        $base  = $this->urlOf($slice_id, '');  // once - the slice lookup is not free, the list can hold 500 files
        foreach ($files as $f) {
            $url   = $base . rawurlencode($f['name']);
            $out[] = $f + ['url' => $url, 'thumb' => in_array($f['type'], UploadPolicy::IMAGES, true) ? $this->thumbOf($url) : ''];
        }
        return JsonResponse::ok(['files' => $out]);
    }

    /**
     * The upload-* attributes for an <aa-richtext> AA did not render (a form in a template):
     * upload="none" when this viewer may not upload, and for a slice or item that does not exist -
     * no 404 to probe ids with. GET ask, slice - or item, for its slice.
     * Only a script of a page of this AA gets them: it sends X-AA-Upload-Ask, a foreign page cannot
     * without the browser asking first (CORS), and could not read the answer. No Origin or Referer
     * check: a same-origin GET carries no Origin, and a page may send no Referer.
     */
    public function ask(): JsonResponse
    {
        $fetch_site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin');    // older browsers do not send it
        if ((($_SERVER['HTTP_X_AA_UPLOAD_ASK'] ?? '') !== '1') || ($fetch_site !== 'same-origin')) {
            return JsonResponse::error(401, 'Unauthorized');
        }
        $slice = is_string($_GET['slice'] ?? null) ? $_GET['slice'] : '';
        $item  = is_string($_GET['item'] ?? null) ? $_GET['item'] : '';
        if (!is_long_id($slice) && !is_long_id($item)) {
            return JsonResponse::error(400, 'Bad request');
        }
        $slice_id = is_long_id($slice) ? $slice : $this->sliceOfItem($item);
        $viewer   = ($slice_id === null) ? null : $this->viewer($slice_id);
        if ($viewer === null) {
            return JsonResponse::ok(['attrs' => RichtextMarkup::viewerAttrs('', UploadPolicy::NONE, '', time())]);
        }
        $this->bindLanguage((string) $slice_id);
        return JsonResponse::ok(['attrs' => RichtextMarkup::viewerAttrs((string) $slice_id, UploadPolicy::mode($viewer['editor'], $viewer['anonymous']), $this->uid(), time())]);
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
            // a name from an old cp1250 upload would break the JSON of the whole list
            if (str_starts_with($name, '.') || !mb_check_encoding($name, 'UTF-8') || !is_file($path)) {
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

    /**
     * what this request may do: what its token offered, if the viewer may still do it
     * @param array{editor: bool, anonymous: bool} $viewer
     */
    private function mode(string $slice_id, array $viewer): string
    {
        $granted = UploadToken::verify((string) ($_SERVER['HTTP_X_AA_UPLOAD_TOKEN'] ?? ''), $slice_id, $this->uid(), time());
        return UploadPolicy::effective($granted, $viewer['editor'], $viewer['anonymous']);
    }

    /** $reason is one of a few words, so a flood of odd names cannot open new State Check rows */
    private function refuse(string $slice_id, string $reason, int $status, string $message, string $detail = ''): JsonResponse
    {
        $this->logRefusal($slice_id, $reason, $detail);
        return JsonResponse::error($status, $message);
    }

    private function anonLimited(string $slice_id): bool
    {
        $ip       = $this->ip();
        $by_ip    = 0;
        $by_slice = 0;
        foreach ($this->anonUploadsLastHour() as $row) {
            $by_slice += (int) ($row['selector'] === $slice_id);
            $by_ip    += (int) ((ParamExplode($row['params'])[2] ?? '') === $ip);
        }
        return ($by_ip >= self::ANON_PER_IP_HOUR) || ($by_slice >= self::ANON_PER_SLICE_HOUR);
    }

    /** @return ?array{editor: bool, anonymous: bool} */
    protected function viewer(string $slice_id): ?array
    {
        return UploadPolicy::viewer($slice_id);
    }

    /** the slice of an item, null for none - no read check: GetItemContent() keeps the slice of every item */
    protected function sliceOfItem(string $item_id): ?string
    {
        $content = GetItemContent(new \zids($item_id, 'l'), false, false, ['slice_id........']);
        $slice   = $content ? (reset($content)['u_slice_id......'][0]['value'] ?? null) : null;
        return is_string($slice) ? $slice : null;
    }

    /** the texts of the editor in the language of the slice's pages, not of the AA install - only a UTF-8
     *  one: the texts of an old windows-1250 language would not go into the JSON */
    protected function bindLanguage(string $slice_id): void
    {
        $slice = AA_Slice::getModule($slice_id);
        if (($slice instanceof AA_Slice) && (strtolower($slice->getCharset()) === 'utf-8')) {
            mgettext_bind($slice->getLang(), 'news');
        }
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
        $path = Files::generateUnusedFilename(Files::makeFile($dir, $name));
        self::reencode($tmp, $path);
        $perms = defined('IMG_UPLOAD_FILE_MODE') ? (int) constant('IMG_UPLOAD_FILE_MODE') : 0;
        if ($perms) {
            chmod($path, $perms);
        }
        return $path;
    }

    /**
     * $tmp written again to $path, as an anonymous upload is - the pixels are counted before GD decodes anything
     * @throws RefusedImage     the visitor's file: too many pixels, or not an image AA can read - the message is for them
     * @throws RuntimeException the server failed: $tmp not readable or $path not written
     */
    protected static function reencode(string $tmp, string $path): void
    {
        $error = Imgfunc::reencode($tmp, $path, UploadPolicy::ANON_MAX_PX, UploadPolicy::ANON_MAX_PIXELS, refusal: $refusal);
        if ($error === null) {
            return;
        }
        if ($refusal !== null) {
            throw new RefusedImage($refusal, $error);
        }
        // GD opens the file before it writes - what a failed write left must not stay public
        if (is_file($path)) {
            unlink($path);
        }
        throw new RuntimeException($error);
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

    protected function logRefusal(string $slice_id, string $reason, string $detail): void
    {
        $params = new AA_Logentry_Unv(['ip' => $this->ip(), 'detail' => $detail]);
        $params->trimToFit(['detail'], AA_Logentry::PARAMS_MAX);
        LogOnce::daily(self::EVENT_REFUSED, (new AA_Logentry_Un(['slice' => $slice_id, 'reason' => $reason]))->serializeToString(true), $params->serializeToString());
    }

    /** @return list<array{selector: string, params: string}> */
    protected function anonUploadsLastHour(): array
    {
        return DB_AA::select([], 'SELECT `selector`, `params` FROM `log`', [['type', self::EVENT_ANON], ['time', time() - 3600, '>=']]);
    }
}
