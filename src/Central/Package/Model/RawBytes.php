<?php

declare(strict_types=1);

namespace AA\Central\Package\Model;

/** A value the collector could not convert from its module charset to UTF-8: the source bytes as
 *  they are. Writer always carries it as base64 - also when the bytes
 *  happen to be valid UTF-8, so the import never reads the source bytes as UTF-8 text. */
final readonly class RawBytes
{
    public function __construct(public string $bytes) {}
}
