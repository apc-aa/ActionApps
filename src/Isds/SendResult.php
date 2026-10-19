<?php

declare(strict_types=1);

namespace AA\Isds;

/** ISDS accepted a message (CreateMessage) */
final readonly class SendResult
{
    /**
     * @param string $dmId the message's id in ISDS, at most 20 characters
     * @param string $code '0000', or another 00xx ISDS counts as success too
     * @param string $text ISDS's words for $code - escape it (safe()) when shown
     */
    public function __construct(public string $dmId, public string $code, public string $text) {}
}
