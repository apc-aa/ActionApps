<?php

/**
 *
 * PHP version 8.2+
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Mail;

use Net_SMTP;
use PEAR_Error;
use RuntimeException;

/**
 * SMTP transport wrapper around pear/net_smtp.
 *
 * Defends against the gaps the audit surfaced in Net_SMTP:
 *   - STARTTLS-stripping: call starttls() explicitly, treat `false` as fatal
 *   - Explicit ssl[] options with verify_peer=true and TLSv1_2|TLSv1_3 floor
 *   - Non-zero socket timeout (Net_Socket::readLine() infinite-loops on 0)
 *   - PEAR_Error -> exception on every call (library never throws on its own)
 *   - setDebug never enabled (debug stream logs base64 PLAIN credentials)
 *   - safe()-equivalent CR/LF stripping on server-supplied text before logging
 *   - CR/LF/NUL validation on caller-supplied helo/user/pass/envelope/rcpts
 */
class SmtpTransport
{
    /** Values for AA_MAIL_TRANSPORT. */
    public const TRANSPORT_MAIL = 'mail';
    public const TRANSPORT_SMTP = 'smtp';

    /** Values for AA_SMTP_ENCRYPTION. */
    public const ENC_TLS  = 'tls';   // STARTTLS on a plaintext-opened port (submission 587)
    public const ENC_SSL  = 'ssl';   // implicit TLS, ssl:// stream wrapper (SMTPS 465)
    public const ENC_NONE = 'none';  // cleartext, dev/test only

    /** sendRaw() return values. */
    public const RESULT_SENT      = 1;
    public const RESULT_PERMANENT = 0;
    public const RESULT_TRANSIENT = -1;

    private string $host;
    private int    $port;
    private string $helo;
    private string $encryption;
    private string $user;
    private string $pass;
    private int    $timeout;
    private string $cafile;

    private string $lastError = '';

    public function __construct()
    {
        $this->host       = defined('AA_SMTP_HOST') ? (string) AA_SMTP_HOST : '';
        $this->port       = defined('AA_SMTP_PORT') ? (int) AA_SMTP_PORT : 587;
        $this->encryption = defined('AA_SMTP_ENCRYPTION') ? (string) AA_SMTP_ENCRYPTION : self::ENC_TLS;
        $this->user       = defined('AA_SMTP_USER') ? (string) AA_SMTP_USER : '';
        $this->pass       = defined('AA_SMTP_PASS') ? (string) AA_SMTP_PASS : '';
        $this->timeout    = defined('AA_SMTP_TIMEOUT') ? max(1, (int) AA_SMTP_TIMEOUT) : 15;
        $this->cafile     = defined('AA_SMTP_CAFILE') ? (string) AA_SMTP_CAFILE : '';
        $this->helo       = self::resolveHostname();

        if ($this->host === '') {
            throw new RuntimeException('SmtpTransport: AA_SMTP_HOST is not configured');
        }
        if (!in_array($this->encryption, [self::ENC_TLS, self::ENC_SSL, self::ENC_NONE], true)) {
            throw new RuntimeException('SmtpTransport: AA_SMTP_ENCRYPTION must be tls|ssl|none');
        }
        self::assertNoControl($this->helo, 'helo');
        if ($this->user !== '') {
            self::assertNoControl($this->user, 'user');
            self::assertNoControl($this->pass, 'pass');
        }
    }

    /**
     * Resolve the EHLO/HELO hostname, also used as the Message-ID hostname.
     *
     * Priority:
     *   AA_SMTP_HELO -> AA_MAIL_HOSTNAME -> parse_url(AA_HTTP_DOMAIN)['host']
     *   -> $_SERVER['HTTP_HOST'] -> $_SERVER['SERVER_NAME'] -> 'localhost'
     *
     * The AA_HTTP_DOMAIN fallback matters on cron-driven installs where
     * $_SERVER['HTTP_HOST'] is empty -- without it, Message-IDs end up as
     * "<...@localhost>" which hurts DKIM alignment.
     */
    public static function resolveHostname(): string
    {
        foreach (['AA_SMTP_HELO', 'AA_MAIL_HOSTNAME'] as $const) {
            if (defined($const) && constant($const) !== '') {
                return (string) constant($const);
            }
        }
        if (defined('AA_HTTP_DOMAIN') && AA_HTTP_DOMAIN !== '') {
            $host = parse_url((string) AA_HTTP_DOMAIN, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            return (string) $_SERVER['HTTP_HOST'];
        }
        if (!empty($_SERVER['SERVER_NAME'])) {
            return (string) $_SERVER['SERVER_NAME'];
        }
        return 'localhost';
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Send one pre-built RFC822 message to one or more envelope recipients.
     *
     * @param string   $envelopeFrom MAIL FROM address (bare addr, no brackets)
     * @param string[] $rcpts        RCPT TO addresses; To+Cc+Bcc merged by caller
     * @param string   $rfc822       Full headers + blank line + body
     * @return int one of self::RESULT_*
     */
    public function sendRaw(string $envelopeFrom, array $rcpts, string $rfc822): int
    {
        if ($envelopeFrom === '' || $rcpts === []) {
            return self::RESULT_PERMANENT;
        }
        self::assertNoControl($envelopeFrom, 'envelopeFrom');
        foreach ($rcpts as $r) {
            self::assertNoControl($r, 'rcpt');
        }

        $connectHost = ($this->encryption === self::ENC_SSL) ? 'ssl://' . $this->host : $this->host;
        $sslOptions  = [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'crypto_method'     => self::cryptoFloor(),
            'SNI_enabled'       => true,
        ];
        if ($this->cafile !== '') {
            $sslOptions['cafile'] = $this->cafile;
        }

        /* Debug stream of Net_SMTP logs base64 PLAIN credentials -- never enable. */
        $smtp = new Net_SMTP($connectHost, $this->port, $this->helo, false, $this->timeout, ['ssl' => $sslOptions]);
        $smtp->setDebug(false);

        try {
            $this->check($smtp->connect($this->timeout), 'connect');

            if ($this->encryption === self::ENC_TLS) {
                $starttls = $smtp->starttls();
                /* Net_SMTP returns boolean false (not a PEAR_Error) when STARTTLS is not
                 * advertised by the server -- the classic MitM strip scenario. Treat as
                 * fatal so we don't fall through to cleartext AUTH. */
                if ($starttls === false) {
                    throw new RuntimeException(
                        'SmtpTransport: server did not offer STARTTLS but AA_SMTP_ENCRYPTION=tls',
                    );
                }
                $this->check($starttls, 'starttls');
            }

            if ($this->user !== '') {
                /* $tls=false because we already called starttls() above. */
                $this->check($smtp->auth($this->user, $this->pass, '', false), 'auth');
            }

            $this->check($smtp->mailFrom($envelopeFrom), 'mailFrom');
            foreach ($rcpts as $rcpt) {
                $this->check($smtp->rcptTo($rcpt), 'rcptTo');
            }
            $this->check($smtp->data($rfc822), 'data');
        } catch (TransientSmtpException $e) {
            $this->lastError = $e->getMessage();
            $this->safeDisconnect($smtp);
            return self::RESULT_TRANSIENT;
        } catch (PermanentSmtpException $e) {
            $this->lastError = $e->getMessage();
            $this->safeDisconnect($smtp);
            return self::RESULT_PERMANENT;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $this->safeDisconnect($smtp);
            return self::RESULT_TRANSIENT;
        }

        $this->safeDisconnect($smtp);
        return self::RESULT_SENT;
    }

    /**
     * Translate a Net_SMTP return value into the right exception kind. The
     * library never throws -- every call must be checked.
     *
     * @param mixed $result true on success, PEAR_Error on failure
     */
    private function check($result, string $context): void
    {
        if ($result === true) {
            return;
        }
        if (!($result instanceof PEAR_Error)) {
            return;
        }
        $code = (int) $result->getCode();
        $msg  = self::stripControl($result->getMessage());
        $full = "SmtpTransport[$context] $code: $msg";

        if ($code >= 400 && $code < 500) {
            throw new TransientSmtpException($full, $code);
        }
        if ($code >= 500 && $code < 600) {
            throw new PermanentSmtpException($full, $code);
        }
        throw new TransientSmtpException($full, $code);
    }

    private function safeDisconnect(Net_SMTP $smtp): void
    {
        try {
            $smtp->disconnect();
        } catch (\Throwable) {
            /* connection already closed or never opened; nothing to do */
        }
    }

    /** TLSv1_2 + TLSv1_3 floor; never SSLv2/v3/TLSv1.0/1.1. */
    private static function cryptoFloor(): int
    {
        $method = 0;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if ($method === 0 && defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')) {
            $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        }
        return $method;
    }

    /** Prevents SMTP command injection via helo/user/pass/envelope/rcpt. */
    private static function assertNoControl(string $value, string $what): void
    {
        if (preg_match('/[\r\n\x00]/', $value)) {
            throw new RuntimeException("SmtpTransport: $what contains forbidden control characters");
        }
    }

    /** Neutralize CR/LF in server-supplied text before it lands in a log line. */
    private static function stripControl(string $s): string
    {
        return preg_replace('/[\r\n\x00]+/', ' ', $s) ?? '';
    }
}
