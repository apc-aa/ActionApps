/*!
 * aa-speech — voice dictation for AA TxtWidget
 *
 * Attaches a mic + clear button to a <textarea> enabled by the widget's
 * `audio_enable` property. Two record-then-transcribe paths, both batched:
 *
 *   1. Web Speech API — browser-native recognition; no server round-trip,
 *      no API key cost. Collects pause-separated finals, one per line.
 *   2. OpenAI gpt-4o-transcribe — records to a Blob, uploads to AA's
 *      api/transcribe.php proxy endpoint. Server-side key, single paragraph
 *      returned.
 *
 * Which path runs is controlled by the `audio_mode` widget property:
 *   'auto' (default) — Web Speech when available, else OpenAI.
 *   'webspeech'      — Web Speech only; error if unsupported.
 *   'openai'         — OpenAI only; skip Web Speech entirely.
 *
 * UI states: ready → recording → transcribing → done → ready.
 */
(() => {
    'use strict';

    const DEFAULT_API_URL = '/api/transcribe.php';
    const DEFAULT_MAX_SECONDS = 120;
    const DONE_FLASH_MS = 700;

    // Step-by-step diagnostics. Toggle via window.AA_SPEECH_DEBUG = false to silence.
    if (typeof window.AA_SPEECH_DEBUG === 'undefined') { window.AA_SPEECH_DEBUG = true; }
    const log = (...args) => {
        if (!window.AA_SPEECH_DEBUG) { return; }
        console.log('[aa-speech]', ...args);
    };

    const ICONS = {
        mic:   '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z"/></svg>',
        stop:  '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><rect x="6" y="6" width="12" height="12" rx="1.5" fill="currentColor"/></svg>',
        spin:  '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" class="aa-speech-spin"><path fill="currentColor" d="M12 4V1L7 6l5 5V7a5 5 0 0 1 0 10v3a8 8 0 0 0 0-16z"/></svg>',
        check: '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="m9 16.17-3.88-3.88L3.7 13.7 9 19l12-12-1.41-1.41z"/></svg>',
        clear: '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.4l-6.3 6.3-1.41-1.41 6.3-6.29-6.3-6.29 1.41-1.42 6.3 6.3 6.3-6.3z"/></svg>'
    };

    /**
     * Attach dictation UI to a <textarea>.
     * @param {HTMLTextAreaElement} textarea
     * @param {{maxSec?:number, lang?:string, apiUrl?:string, sliceId?:string, fieldId?:string, itemId?:string}} [opts]
     */
    const attach = (textarea, opts = {}) => {
        if (!textarea) { log('attach: no textarea given, skipping'); return; }
        if (textarea.dataset.aaSpeechAttached === '1') {
            log('attach: textarea already attached', textarea.id);
            return;
        }
        textarea.dataset.aaSpeechAttached = '1';

        const maxSec  = Math.max(5, parseInt(opts.maxSec, 10) || DEFAULT_MAX_SECONDS);
        const lang    = opts.lang || inferLang(textarea);
        const apiUrl  = opts.apiUrl || DEFAULT_API_URL;
        const mode    = (opts.mode || 'auto').toLowerCase();   // 'auto' | 'webspeech' | 'openai'
        const sliceId = opts.sliceId || '';
        const fieldId = opts.fieldId || '';
        const itemId  = opts.itemId  || '';
        // Re-evaluated at each mic click so the path decision honours the live
        // widget mode AND the debug override (window.AA_SPEECH_FORCE_OPENAI=true).
        const shouldUseWebSpeech = () => {
            if (window.AA_SPEECH_FORCE_OPENAI) { return false; }
            if (mode === 'openai')             { return false; }
            if (mode === 'webspeech')          { return true;  }    // may fail if unsupported
            return supportsWebSpeech();                              // mode=auto
        };

        log('attach:', {
            id: textarea.id,
            maxSec,
            lang,
            apiUrl,
            mode,
            path: shouldUseWebSpeech() ? 'webspeech' : 'openai-batch',
            hasMediaRecorder: !!window.MediaRecorder,
            hasMediaDevices:  !!(navigator && navigator.mediaDevices),
            hasSpeechRecognition: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
            isSecureContext: window.isSecureContext
        });

        const host = createHost(textarea);
        const micBtn = createMicButton();
        const clearBtn = createClearButton(textarea);
        host.appendChild(micBtn);
        host.appendChild(clearBtn);

        // Path-specific state (captured in closure so each attach() is independent).
        let state = 'ready';
        let recorder = null;
        let recognition = null;
        let chunks = [];
        let stream = null;
        let countdownTimer = null;
        let collectedFinals = [];   // Web Speech: finalised utterances accumulated during the session.

        setState('ready');

        micBtn.addEventListener('click', () => {
            log('mic click, state =', state);
            if      (state === 'ready')     { start(); }
            else if (state === 'recording') { stop();  }
        });

        const start = () => shouldUseWebSpeech() ? startWebSpeech() : startMediaRecorder();

        const stop = () => {
            if (recognition) {
                try { recognition.stop(); } catch { /* noop */ }
                setState('transcribing');  // onend is async; show spinner in the gap
            }
            if (recorder && recorder.state !== 'inactive') {
                try { recorder.stop(); } catch { /* noop */ }
            }
            cancelCountdown();
        };

        // ---- Web Speech path (browser-native, no server round-trip) ----
        function startWebSpeech() {
            const Ctor = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!Ctor) {
                notifyError('Voice recognition not supported by this browser.');
                return;
            }
            log('startWebSpeech: creating SpeechRecognition with lang', lang);
            recognition = new Ctor();
            recognition.lang = lang;
            recognition.continuous = true;
            recognition.interimResults = false;   // batch-style: we only care about finals

            collectedFinals = [];

            recognition.onresult = (event) => {
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    const r = event.results[i];
                    if (r.isFinal) {
                        const t = r[0].transcript.trim();
                        if (t) { collectedFinals.push(t); log('onresult final=', JSON.stringify(t)); }
                    }
                }
            };
            recognition.onerror = (e) => {
                log('recognition.onerror:', e.error, e.message || '');
                cancelCountdown();
                if (e.error !== 'no-speech' && e.error !== 'aborted') {
                    setState('ready');
                    notifyError(`Voice recognition error: ${e.error}`);
                }
            };
            recognition.onstart       = () => log('recognition.onstart fired');
            recognition.onaudiostart  = () => log('recognition.onaudiostart fired');
            recognition.onspeechstart = () => log('recognition.onspeechstart fired');
            recognition.onspeechend   = () => log('recognition.onspeechend fired');
            recognition.onend = () => {
                log('recognition.onend fired, finals collected=', collectedFinals.length);
                cancelCountdown();
                if (collectedFinals.length) {
                    insertFinalText(collectedFinals.join('\n'));
                }
                collectedFinals = [];
                flashDone();
            };

            try { recognition.start(); log('recognition.start() called'); }
            catch (e) { log('recognition.start threw:', e.message); notifyError(`Cannot start recognition: ${e.message}`); return; }
            setState('recording');
            startCountdown(stop);
        }

        /** Common insertion point for both paths — trim, add trailing newline, drop at caret. */
        function insertFinalText(text) {
            const clean = String(text).trim();
            if (!clean) { return; }
            insertAtCaret(textarea, clean + '\n');
            log('inserted text at caret:', JSON.stringify(clean));
        }

        // ---- MediaRecorder + OpenAI proxy path ----
        async function startMediaRecorder() {
            log('startMediaRecorder: requesting getUserMedia');
            if (!window.MediaRecorder || !navigator.mediaDevices) {
                notifyError('Your browser does not support audio recording.');
                return;
            }
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                log('getUserMedia: stream granted');
            } catch (e) {
                log('getUserMedia: rejected', e.name, e.message);
                notifyError(micErrorMessage(e));
                return;
            }
            recorder = new MediaRecorder(stream);
            chunks = [];
            recorder.ondataavailable = (e) => {
                if (e.data?.size) { chunks.push(e.data); log('recorder ondataavailable: bytes=', e.data.size); }
            };
            recorder.onstop = () => {
                log('recorder.onstop: total chunks=', chunks.length, 'mime=', recorder.mimeType);
                releaseStream();
                setState('transcribing');
                uploadForTranscription(new Blob(chunks, { type: recorder.mimeType || 'audio/webm' }));
            };
            recorder.start();
            log('recorder.start() called, state=', recorder.state);
            setState('recording');
            startCountdown(stop);
        }

        async function uploadForTranscription(blob) {
            const fd = new FormData();
            // OpenAI's transcriptions endpoint dispatches by filename extension,
            // so the extension MUST match the actual codec/container — iOS Safari's
            // MediaRecorder produces audio/mp4 (AAC), Chrome produces audio/webm,
            // Firefox audio/ogg. Mismatch → "Audio file might be corrupted".
            const ext = extForMime(blob.type);
            fd.append('audio', blob, `speech.${ext}`);
            if (lang)    { fd.append('lang',     lang); }
            // Context for server-side audio storage. The server only stores
            // when the field's audio_store property is set; these fields just
            // tell it WHERE.
            if (sliceId) { fd.append('slice_id', sliceId); }
            if (fieldId) { fd.append('field_id', fieldId); }
            if (itemId)  { fd.append('item_id',  itemId);  }
            log('uploadForTranscription: POSTing', apiUrl, 'blob bytes=', blob.size, 'ext=', ext, 'lang=', lang, 'slice=', sliceId, 'field=', fieldId, 'item=', itemId);

            try {
                const resp = await fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
                log('fetch response status=', resp.status, 'content-type=', resp.headers.get('content-type'));
                const data = await resp.json();
                log('fetch result ok=', resp.ok, 'body=', data);
                if (!resp.ok) { throw new Error(data?.error || 'Transcription failed'); }
                insertFinalText(data?.text || '');
                flashDone();
            } catch (e) {
                log('fetch failed:', e.message);
                setState('ready');
                notifyError(e.message || 'Transcription failed');
            }
        }

        function releaseStream() {
            stream?.getTracks().forEach((t) => t.stop());
            stream = null;
        }

        // ---- UI state machine ----
        function setState(next) {
            state = next;
            micBtn.dataset.state = next;
            micBtn.className = `aa-speech-mic aa-speech-mic--${next}`;
            micBtn.disabled = (next === 'transcribing');
            const icons = { ready: ICONS.mic, recording: ICONS.stop, transcribing: ICONS.spin, done: ICONS.check };
            const labels = {
                ready:        'Start voice dictation',
                recording:    'Stop recording',
                transcribing: 'Transcribing…',
                done:         'Done'
            };
            micBtn.innerHTML = icons[next];
            micBtn.setAttribute('aria-label', labels[next]);
            micBtn.title = labels[next];
            if (next !== 'recording') { delete micBtn.dataset.countdown; }
        }

        function flashDone() {
            setState('done');
            setTimeout(() => { if (state === 'done') { setState('ready'); } }, DONE_FLASH_MS);
        }

        function startCountdown(onElapsed) {
            const endAt = Date.now() + maxSec * 1000;
            const tick = () => {
                const left = Math.max(0, Math.ceil((endAt - Date.now()) / 1000));
                micBtn.dataset.countdown = left;
                micBtn.title = `Stop recording (${left} s left)`;
                if (left <= 0) { cancelCountdown(); onElapsed(); }
            };
            tick();
            countdownTimer = setInterval(tick, 250);
        }

        function cancelCountdown() {
            if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
        }

        function notifyError(msg) {
            console.warn('[aa-speech]', msg);
            window.alert(msg);
        }
    };

    // ---- Shared helpers ----

    /**
     * Map a MediaRecorder blob mime to a file extension OpenAI's
     * /v1/audio/transcriptions endpoint accepts. The endpoint accepts:
     * flac, m4a, mp3, mp4, mpeg, mpga, oga, ogg, wav, webm.
     * iOS Safari emits audio/mp4 (AAC); Chrome emits audio/webm; Firefox ogg.
     */
    function extForMime(mimeType) {
        const base = String(mimeType || '').toLowerCase().split(';')[0].trim();
        switch (base) {
            case 'audio/webm':              return 'webm';
            case 'audio/ogg':               return 'ogg';
            case 'audio/mp4':
            case 'audio/x-m4a':
            case 'audio/aac':               return 'mp4';   // iOS Safari path
            case 'audio/mpeg':              return 'mp3';
            case 'audio/wav':
            case 'audio/x-wav':             return 'wav';
            case 'audio/flac':              return 'flac';
            default:                        return 'webm'; // safest default for Chromium
        }
    }


    /**
     * Map a getUserMedia rejection to a user-actionable message. The default
     * browser error is too vague on mobile — users don't know that "Permission
     * denied" really means "Settings → Safari → Microphone" or that an in-app
     * browser is the actual culprit.
     */
    function micErrorMessage(err) {
        const ua = navigator?.userAgent || '';
        const inApp =
            /FBAN|FBAV|FB_IAB|Instagram|Line|MicroMessenger|TikTok|GSA\//i.test(ua) ||
            (/LinkedInApp/i.test(ua));
        if (inApp) {
            return 'Voice input does not work in this in-app browser. Tap the menu (⋯ or share icon) and choose "Open in Safari" / "Open in Chrome".';
        }
        const isIOS     = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
        const isAndroid = /Android/.test(ua);
        const settings  = isIOS     ? 'Settings → Safari → Microphone (and reload this page)'
                       : isAndroid  ? 'tap the lock icon next to the address bar → Permissions → Microphone → Allow'
                       :              'click the lock/info icon in the address bar → Site settings → Microphone → Allow';
        switch (err?.name) {
            case 'NotAllowedError':
            case 'SecurityError':
                return `Microphone access was blocked. To enable it: ${settings}.`;
            case 'NotFoundError':
            case 'DevicesNotFoundError':
                return 'No microphone was detected on this device.';
            case 'NotReadableError':
            case 'TrackStartError':
                return 'The microphone is busy in another app. Close it and try again.';
            case 'OverconstrainedError':
                return 'Microphone does not meet the required constraints.';
            case 'AbortError':
                return 'Microphone request was cancelled. Tap the mic again to retry.';
            default:
                return `Microphone error (${err?.name || 'unknown'}): ${err?.message || ''}`.trim();
        }
    }

    function supportsWebSpeech() {
        const hasSR = !!(window.SpeechRecognition || window.webkitSpeechRecognition);
        if (!hasSR) { return false; }
        // Chrome/Edge on all major platforms support cs-CZ and en-*; Safari's
        // SpeechRecognition is unreliable. Heuristic: skip on Safari so iOS/macOS
        // Safari falls back to the OpenAI path.
        const ua = navigator?.userAgent ?? '';
        const isSafari = /Safari/.test(ua) && !/Chrome|Chromium|Edg|OPR/.test(ua);
        return !isSafari;
    }

    function inferLang(textarea) {
        const el = textarea.closest('[lang]');
        if (el?.getAttribute('lang')) { return el.getAttribute('lang'); }
        return (document.documentElement.getAttribute('lang') || 'cs').slice(0, 5);
    }

    function createHost(textarea) {
        const parent = textarea.parentNode;
        const host = document.createElement('span');
        host.className = 'aa-speech-host';
        parent.insertBefore(host, textarea.nextSibling);
        // Parent must be position:relative for the absolute-positioned buttons
        // to anchor correctly. If not already, apply it.
        if (getComputedStyle(parent).position === 'static') {
            parent.style.position = 'relative';
        }
        return host;
    }

    function createMicButton() {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'aa-speech-mic aa-speech-mic--ready';
        btn.innerHTML = ICONS.mic;
        return btn;
    }

    function createClearButton(textarea) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'aa-speech-clear';
        btn.innerHTML = ICONS.clear;
        btn.setAttribute('aria-label', 'Clear textarea');
        btn.title = 'Clear textarea';
        btn.addEventListener('click', () => {
            if (textarea.value === '') { return; }
            if (window.confirm('Clear the text in this field?')) {
                textarea.value = '';
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                textarea.focus();
            }
        });
        return btn;
    }

    const selectionStart = (el) => typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
    const selectionEnd   = (el) => typeof el.selectionEnd   === 'number' ? el.selectionEnd   : el.value.length;

    function replaceRange(el, start, end, text) {
        el.value = el.value.slice(0, start) + text + el.value.slice(end);
        const pos = start + text.length;
        el.selectionStart = el.selectionEnd = pos;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function insertAtCaret(el, text) {
        const start = selectionStart(el);
        const end = selectionEnd(el);
        replaceRange(el, start, end, text);
        el.focus();
    }

    /**
     * Scan the document for <textarea data-aa-speech="1"> and attach dictation
     * UI to any that are not yet attached. Reads opts from data attributes:
     *   data-aa-speech-max-sec, data-aa-speech-lang, data-aa-speech-api-url.
     * Safe to call multiple times; already-attached textareas are skipped.
     */
    const autoInit = (root) => {
        const tas = (root || document).querySelectorAll('textarea[data-aa-speech="1"]');
        log('autoInit: found', tas.length, 'textarea(s) with data-aa-speech=1 under', root ? (root.id || root.tagName) : 'document');
        tas.forEach((ta) => {
            attach(ta, {
                maxSec:  parseInt(ta.getAttribute('data-aa-speech-max-sec'), 10) || DEFAULT_MAX_SECONDS,
                lang:    ta.getAttribute('data-aa-speech-lang')     || '',
                apiUrl:  ta.getAttribute('data-aa-speech-api-url')  || DEFAULT_API_URL,
                mode:    ta.getAttribute('data-aa-speech-mode')     || 'auto',
                sliceId: ta.getAttribute('data-aa-speech-slice')    || '',
                fieldId: ta.getAttribute('data-aa-speech-field')    || '',
                itemId:  ta.getAttribute('data-aa-speech-item')     || ''
            });
        });
    };

    log('aa-speech module loaded, readyState=', document.readyState, 'isSecureContext=', window.isSecureContext);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => { log('DOMContentLoaded fired'); autoInit(document); });
    } else {
        autoInit(document);
    }

    window.AA_Speech = { attach, autoInit };
})();
