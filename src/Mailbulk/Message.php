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
    /** headers that add recipients or that the mail sets itself */
    private const OWN_HEADERS = ['to', 'cc', 'bcc', 'from', 'subject'];

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
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]*$/', (string) $name) || in_array(strtolower((string) $name), self::OWN_HEADERS, true)) {
                throw new InvalidArgumentException('Message: header not allowed: ' . preg_replace('/[^\x21-\x7e]/', '?', (string) $name));
            }
        }
    }
}
