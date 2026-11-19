<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA\Util\Secret;
use AA\Util\Txt;

/**
 * The signed part of a tracked address in a mail: which list, item and reader opened the mail
 * or clicked, and for a click where to go. Only a valid token redirects, so the hit address
 * is no open redirect. Tokens never expire - old mails must keep working.
 * Binary ids and a truncated MAC keep it short: it sits in every link of every mail.
 */
final class TrackToken
{
    public const OPEN  = 'o';
    public const CLICK = 'c';

    /** 96 bits: a forgery has to be guessed online, one request per try */
    private const MAC_BYTES = 12;

    private function __construct(
        public readonly string $list,
        public readonly string $itemId,
        public readonly string $readerId,
        public readonly string $kind,
        public readonly string $url,
    ) {}

    /** @throws \InvalidArgumentException a malformed id, kind or url - the caller leaves such a link untracked */
    public static function make(string $list, string $itemId, string $readerId, string $kind, string $url = ''): string
    {
        $itemId   = strtolower($itemId);
        $readerId = strtolower($readerId);
        if (!self::valid($list, $itemId, $readerId, $kind, $url)) {
            throw new \InvalidArgumentException('Malformed tracked address');
        }
        $data = $kind . chr(strlen($list)) . $list . hex2bin($itemId) . hex2bin($readerId) . $url;
        return Txt::bin2url(self::mac($data) . $data);
    }

    /** @return ?self null when forged or malformed */
    public static function parse(string $token): ?self
    {
        $raw = Txt::url2bin($token, true);
        if (($raw === false) || (strlen($raw) < self::MAC_BYTES + 2 + 32)) {
            return null;
        }
        $data = substr($raw, self::MAC_BYTES);
        if (!hash_equals(self::mac($data), substr($raw, 0, self::MAC_BYTES))) {
            return null;
        }
        $len = ord($data[1]);
        if (strlen($data) < 2 + $len + 32) {
            return null;
        }
        $token = new self(substr($data, 2, $len), bin2hex(substr($data, 2 + $len, 16)), bin2hex(substr($data, 2 + $len + 16, 16)), $data[0], substr($data, 2 + $len + 32));
        return self::valid($token->list, $token->itemId, $token->readerId, $token->kind, $token->url) ? $token : null;
    }

    private static function valid(string $list, string $itemId, string $readerId, string $kind, string $url): bool
    {
        if (!preg_match('/\A[A-Za-z0-9]{1,6}\z/', $list) || !preg_match('/\A[0-9a-f]{32}\z/', $itemId) || !preg_match('/\A[0-9a-f]{32}\z/', $readerId)) {
            return false;
        }
        return match ($kind) {
            self::OPEN  => ($url === ''),
            // control characters would split the Location header of the redirect
            self::CLICK => (bool) preg_match('~\Ahttps?://[^\x00-\x1f\x7f]+\z~i', $url),
            default     => false,
        };
    }

    private static function mac(string $data): string
    {
        // the key derivation would otherwise run for every link of every recipient
        static $key = null;
        $key ??= Secret::key('mail-track');
        return substr(hash_hmac('sha256', $data, $key, true), 0, self::MAC_BYTES);
    }
}
