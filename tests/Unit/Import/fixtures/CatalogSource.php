<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA\Import\Cataloged;
use AA\Import\CatalogPage;
use AA\Import\Checkable;
use AA\Import\CheckResult;
use AA\Import\Context;
use AA\Import\Probe;
use AA\Import\Probeable;
use AA\Import\Stream;

require_once __DIR__ . '/ScriptedSource.php';

/** A ScriptedSource that also checks, probes and lists its catalog as the test sets it, and records the calls */
class CatalogSource extends ScriptedSource implements Checkable, Probeable, Cataloged
{
    public CheckResult $check;

    /** @var ?\Closure(Context): void runs first in check() */
    public ?\Closure $onCheck = null;

    /** @var array<string, Probe|\Closure(): Probe> stream key => answer, or what gives a new one at each call;
     *  a stream not listed is changed at v-<key> */
    public array $probes = [];

    /** @var array<string, \Exception> stream key => what probe() throws */
    public array $failProbe = [];

    /** @var array<string, CatalogPage|\Exception|\Closure(): CatalogPage> cursor => the page, what catalog() throws, or
     *  what makes the page there (a page the source would refuse to make) */
    public array $pages = [];

    /** what each probe() and catalog() call spends of the step */
    public float $callSeconds = 0.0;

    /** what check() spends of the step */
    public float $checkSeconds = 0.0;

    /** @var list<string> check, probe:<stream>, catalog:<cursor> in order */
    public array $calls = [];

    /** @var array<string, ?string> stream key => the version probe() was told last */
    public array $known = [];

    public function __construct()
    {
        $this->check = new CheckResult(true, '', '');
    }

    public function check(Context $ctx): CheckResult
    {
        $this->calls[]     = 'check';
        if ($this->onCheck) {
            ($this->onCheck)($ctx);
        }
        TestRun::$microNow = (TestRun::$microNow ?? 0.0) + $this->checkSeconds;
        return $this->check;
    }

    public function probe(Stream $s, Context $ctx, ?string $known): Probe
    {
        $this->calls[]       = 'probe:' . $s->key;
        $this->known[$s->key] = $known;
        TestRun::$microNow = (TestRun::$microNow ?? 0.0) + $this->callSeconds;
        if (isset($this->failProbe[$s->key])) {
            throw $this->failProbe[$s->key];
        }
        $probe = $this->probes[$s->key] ?? new Probe(Probe::CHANGED, 'v-' . $s->key);
        return ($probe instanceof \Closure) ? $probe() : $probe;
    }

    public function catalog(Context $ctx, string $cursor): CatalogPage
    {
        $this->calls[]     = 'catalog:' . $cursor;
        TestRun::$microNow = (TestRun::$microNow ?? 0.0) + $this->callSeconds;
        $page              = $this->pages[$cursor] ?? throw new \LogicException("No catalog page at '$cursor'");
        if ($page instanceof \Exception) {
            throw $page;
        }
        return ($page instanceof \Closure) ? $page() : $page;
    }
}
