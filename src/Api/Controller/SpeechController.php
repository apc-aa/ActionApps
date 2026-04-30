<?php
/**
 * /api/v1/speech/transcribe — proxies uploaded audio to OpenAI's
 * /v1/audio/transcriptions endpoint so the API key never leaves the server.
 *
 * Authentication: requires a logged-in AA user ($auth->is_user()).
 * Config: AA_APIKEY_OPENAI (required), AA_SPEECH_MAX_SECONDS (default 120).
 *
 * @package Api
 */

namespace AA\Api\Controller;

use AA\Api\JsonResponse;

class SpeechController
{
    /** Model used for transcription. gpt-4o-transcribe outperforms Whisper-1 on Czech. */
    protected const MODEL = 'gpt-4o-transcribe';

    /** Upper bound on audio size per second (bytes). 128 KB/s ≈ 1 Mbps covers any
     *  bitrate a browser MediaRecorder will produce, well below OpenAI's 25 MB cap. */
    protected const BYTES_PER_SECOND = 131072;

    public function transcribe(): JsonResponse
    {
        /** @var \AA_Auth|null $auth */
        global $auth;

        if (!is_object($auth) || !$auth->is_user()) {
            return JsonResponse::error(401, 'Unauthorized');
        }
        if (!$this->getApiKey()) {
            return JsonResponse::error(503, 'Speech transcription not configured');
        }

        $file = $_FILES['audio'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return JsonResponse::error(400, 'Missing or invalid audio upload');
        }

        $maxSeconds = (int)(defined('AA_SPEECH_MAX_SECONDS') ? AA_SPEECH_MAX_SECONDS : 120);
        if ($maxSeconds <= 0) {
            $maxSeconds = 120;
        }
        $maxBytes = $maxSeconds * self::BYTES_PER_SECOND;
        if ((int)$file['size'] > $maxBytes) {
            return JsonResponse::error(413, "Audio exceeds configured max length of {$maxSeconds} s");
        }

        $lang = $this->sanitizeLang($_POST['lang'] ?? null);

        $result = $this->callOpenAi(
            (string)$file['tmp_name'],
            (string)($file['name'] ?? 'audio.webm'),
            (string)($file['type'] ?? 'audio/webm'),
            $lang
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
        return (defined('AA_APIKEY_OPENAI') && AA_APIKEY_OPENAI) ? (string)AA_APIKEY_OPENAI : null;
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
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'status' => 502, 'message' => 'OpenAI request failed: ' . $err];
        }
        $payload = json_decode((string)$body, true);
        if ($status >= 400 || !is_array($payload)) {
            $msg = is_array($payload) && isset($payload['error']['message'])
                ? (string)$payload['error']['message']
                : 'OpenAI returned HTTP ' . $status;
            return ['ok' => false, 'status' => 502, 'message' => $msg];
        }
        return ['ok' => true, 'text' => (string)($payload['text'] ?? '')];
    }
}
