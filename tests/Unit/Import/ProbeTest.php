<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Probe;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProbeTest extends TestCase
{
    public function test_construct_rejectsUnknownState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Probe('maybe');
    }

    public function test_construct_keepsStateAndVersion(): void
    {
        $probe = new Probe(Probe::CHANGED, '2024');

        self::assertSame([Probe::CHANGED, '2024'], [$probe->state, $probe->version]);
    }
}
