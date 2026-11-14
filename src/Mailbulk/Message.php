<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use InvalidArgumentException;

/**
 * A finished mail for one recipient. Values may come from item fields - a line break in them is folded
 * when the mail is built, but a header name is written as it is, so it is checked here.
 */
final class Message
{
    /** headers that add recipients or that the mail sets itself; Resent-* and Content-* as well */
    private const OWN_HEADERS = [
        'to', 'cc', 'bcc', 'from', 'subject', 'sender', 'reply-to', 'errors-to', 'return-path', 'mime-version', 'message-id', 'date',
    ];

    /** @param array<string, string> $headers name => value, e.g. List-Unsubscribe */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $from,
        public readonly array $headers = [],
    ) {
        if (preg_match('/[\r\n\x00,;]/', $to)) {
            throw new InvalidArgumentException('Message: one recipient address expected');
        }
        foreach (array_keys($headers) as $name) {
            if (!self::allowedHeader((string) $name)) {
                throw new InvalidArgumentException('Message: header not allowed: ' . preg_replace('/[^\x21-\x7e]/', '?', (string) $name));
            }
        }
    }

    private static function allowedHeader(string $name): bool
    {
        // \z: `$` would let a trailing line break through
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*\z/', $name)) {
            return false;
        }
        $name = strtolower($name);
        return !in_array($name, self::OWN_HEADERS, true) && !str_starts_with($name, 'resent-') && !str_starts_with($name, 'content-');
    }
}
