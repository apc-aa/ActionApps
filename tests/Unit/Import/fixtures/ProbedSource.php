<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA\Import\Context;
use AA\Import\Probe;
use AA\Import\Probeable;
use AA\Import\Stream;

require_once __DIR__ . '/ScriptedSource.php';

/** A ScriptedSource that answers probe() as the test sets it, and records the calls */
final class ProbedSource extends ScriptedSource implements Probeable
{
    /** @var array<string, Probe> stream key => answer; a stream not listed is changed */
    public array $probes = [];

    /** @var list<array{stream: string, known: ?string}> */
    public array $probeCalls = [];

    public function probe(Stream $s, Context $ctx, ?string $known): Probe
    {
        $this->probeCalls[] = ['stream' => $s->key, 'known' => $known];
        return $this->probes[$s->key] ?? new Probe(Probe::CHANGED, 'v-' . $s->key);
    }
}
