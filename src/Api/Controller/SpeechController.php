<?php

/**
 * Speech-to-text controller — proxies uploaded audio to OpenAI's
 * /v1/audio/transcriptions endpoint so the API key never leaves the server.
 * Invoked from api/transcribe.php (direct PHP entry point).
 *
 * Authentication: same-host gate only (Origin/Referer host must equal
 * HTTP_HOST) — anonymous-allowed public-form dictation, not a logged-in check.
 * Config: AA_APIKEY_OPENAI (required), AA_SPEECH_MAX_SECONDS (default 120).
 *
 * @package Api
 */

namespace AA\Api\Controller;

use AA\Api\JsonResponse;
use AA\Api\SameHostGate;

class SpeechController
{
    use SameHostGate;

    /** Model used for transcription. gpt-4o-transcribe outperforms Whisper-1 on Czech. */
    protected const MODEL = 'gpt-4o-transcribe';

    /** Upper bound on audio size per second (bytes). 128 KB/s ≈ 1 Mbps covers any
     *  bitrate a browser MediaRecorder will produce, well below OpenAI's 25 MB cap. */
    protected const BYTES_PER_SECOND = 131072;

    public function transcribe(): JsonResponse
    {
        // Anonymous-allowed (public-form dictation). Same-host gate only —
        // Origin or Referer must point at this AA host. Spoofable but blocks
        // off-site browsers and casual curl. TODO: add per-slice opt-in +
        // rate limit before opening to many sites.
        if (!$this->sameHostRequest()) {
            return JsonResponse::error(401, 'Unauthorized');
        }
        if (!$this->getApiKey()) {
            return JsonResponse::error(503, 'Speech transcription not configured');
        }

        $file = $_FILES['audio'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return JsonResponse::error(400, 'Missing or invalid audio upload');
        }

        $maxSeconds = (int) (defined('AA_SPEECH_MAX_SECONDS') ? AA_SPEECH_MAX_SECONDS : 120);
        if ($maxSeconds <= 0) {
            $maxSeconds = 120;
        }
        $maxBytes = $maxSeconds * self::BYTES_PER_SECOND;
        if ((int) $file['size'] > $maxBytes) {
            return JsonResponse::error(413, "Audio exceeds configured max length of {$maxSeconds} s");
        }

        $lang = $this->sanitizeLang($_POST['lang'] ?? null);

        // Persist the recording first (best-effort) so it survives even if the
        // OpenAI call fails. The store call uses Files::uploadFile() which does
        // move_uploaded_file() — afterwards $file['tmp_name'] is gone, so the
        // OpenAI request below MUST read from $stored_path when storage ran.
        $stored_path = $this->maybeStoreAudio(
            (string) ($file['type'] ?? 'audio/webm'),
            (string) ($_POST['slice_id'] ?? ''),
            (string) ($_POST['field_id'] ?? ''),
            (string) ($_POST['item_id']  ?? ''),
        );

        $result = $this->callOpenAi(
            $stored_path ?? (string) $file['tmp_name'],
            (string) ($file['name'] ?? 'audio.webm'),
            (string) ($file['type'] ?? 'audio/webm'),
            $lang,
        );
        if (!$result['ok']) {
            return JsonResponse::error($result['status'], $result['message']);
        }
        return JsonResponse::ok([
            'text'  => $result['text'],
            'model' => self::MODEL,
        ]);
    }

    /** Override point for tests; production reads from the PHP define. */
    protected function getApiKey(): ?string
    {
        return (defined('AA_APIKEY_OPENAI') && AA_APIKEY_OPENAI) ? (string) AA_APIKEY_OPENAI : null;
    }

    /** Persist the uploaded audio to the slice's upload directory if the
     *  field's TxtWidget has audio_store=true. Server-truth check — the flag
     *  comes from stored field config, not the request. Reuses AA's standard
     *  upload pipeline (Files::destinationDir + Files::uploadFile) so dir
     *  creation, permissions, conflict handling and last-error reporting all
     *  follow the same code path as every other upload in AA. Returns the
     *  final stored path on success, null on any miss (off / bad context /
     *  upload failure). */
    protected function maybeStoreAudio(string $mimeType, string $sliceId, string $fieldId, string $itemId): ?string
    {
        if ($sliceId === '' || $fieldId === '' || !\is_long_id($sliceId)) {
            return null;
        }
        $slice = \AA_Slice::getModule($sliceId);
        if (!is_object($slice)) {
            return null;
        }
        $field = $slice->getField($fieldId);
        if (!is_object($field)) {
            return null;
        }
        $widget = $field->getWidget();
        if (!is_object($widget) || !$widget->getProperty('audio_store', false)) {
            return null;
        }

        $dest_dir = \Files::destinationDir($slice);
        if ($dest_dir === false) {
            error_log('[aa-speech] Files::destinationDir failed: ' . \Files::lastErrMsg());
            return null;
        }

        $filename = sprintf(
            'audio-%s-%s-%s.%s',
            \Files::escape($itemId === '' ? 'new' : $itemId),
            \Files::escape($fieldId),
            date('Ymd-His'),
            $this->extensionForMime($mimeType),
        );

        // 'new' so a same-second collision yields _1, _2, … via
        // Files::generateUnusedFilename(). uploadFile() does
        // move_uploaded_file() under the hood — the tmp file is gone after
        // this returns, see comment in transcribe().
        $stored = \Files::uploadFile($_FILES['audio'], $dest_dir, '', 'new', $filename);
        if ($stored === false) {
            error_log('[aa-speech] Files::uploadFile failed: ' . \Files::lastErrMsg());
            return null;
        }
        return $stored;
    }

    /** Map the browser-recorded mime type to a sane file extension. */
    protected function extensionForMime(string $mimeType): string
    {
        $base = strtolower(trim(explode(';', $mimeType)[0]));
        return match ($base) {
            'audio/webm'      => 'webm',
            'audio/ogg'       => 'ogg',
            'audio/mp4'       => 'm4a',
            'audio/mpeg'      => 'mp3',
            'audio/wav',
            'audio/x-wav'     => 'wav',
            'audio/flac'      => 'flac',
            default           => 'bin',
        };
    }

    /** Accept only "xx" or "xx-YY" locale codes; reject anything else. */
    protected function sanitizeLang(mixed $lang): ?string
    {
        if (!is_string($lang) || $lang === '') {
            return null;
        }
        return preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang) ? $lang : null;
    }

    /**
     * Perform the multipart POST to OpenAI. Split out so unit tests can override
     * without hitting the network.
     *
     * @return array{ok:true,text:string}|array{ok:false,status:int,message:string}
     */
    protected function callOpenAi(string $tmpPath, string $filename, string $mimeType, ?string $lang): array
    {
        $post = [
            'file'            => new \CURLFile($tmpPath, $mimeType, $filename),
            'model'           => self::MODEL,
            'response_format' => 'json',
        ];
        if ($lang !== null) {
            $post['language'] = substr($lang, 0, 2);
        }

        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->getApiKey()],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => 502, 'message' => 'OpenAI request failed: ' . $err];
        }
        $payload = json_decode((string) $body, true);
        if ($status >= 400 || !is_array($payload)) {
            $msg = is_array($payload) && isset($payload['error']['message'])
                ? (string) $payload['error']['message']
                : 'OpenAI returned HTTP ' . $status;
            return ['ok' => false, 'status' => 502, 'message' => $msg];
        }
        return ['ok' => true, 'text' => (string) ($payload['text'] ?? '')];
    }
}
