<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\ImportAuthority;

/** ImportAuthority for the writer tests: $may answers (default yes), $error is thrown instead; $asked logs
 *  every user asked about */
final class FakeImportAuthority implements ImportAuthority
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(public bool $may = true, public ?\Throwable $error = null) {}

    public function mayInstall(string $user): bool
    {
        $this->asked[] = $user;
        if ($this->error) {
            throw $this->error;
        }
        return $this->may;
    }
}
