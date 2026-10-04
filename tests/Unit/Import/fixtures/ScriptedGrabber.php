<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA\Http\ClientInterface;
use AA_Content;
use Tests\Unit\IO\Fixtures\ArrayGrabber;

require_once __DIR__ . '/../../IO/fixtures/ArrayGrabber.php';

/** The grabber of a ScriptedSource job: fixed items, built into new items like CSV or XML (not
 *  direct fill) and fetching the way the import allows - unless the test says otherwise. */
final class ScriptedGrabber extends ArrayGrabber
{
    /** the client the run handed over */
    public ?ClientInterface $client = null;

    /**
     * @param ?\Closure(): void $onPrepare runs first in prepare() - a test spends the step's time there
     * @param int $failAt the item (1-based) getItem() fails at with $failure instead, 0 = none
     */
    public function __construct(
        array $items,
        ?\Exception $prepare_error = null,
        private readonly bool $viaLoadData = true,
        private readonly bool $directFill = false,
        private readonly ?\Closure $onPrepare = null,
        private readonly int $failAt = 0,
        private readonly ?\Exception $failure = null,
    ) {
        parent::__construct($items, $prepare_error);
    }

    public function prepare(): void
    {
        if ($this->onPrepare) {
            ($this->onPrepare)();
        }
        parent::prepare();
    }

    public function getItem(): ?AA_Content
    {
        if (($this->failAt > 0) && ($this->taken + 1 === $this->failAt) && $this->failure) {
            throw $this->failure;
        }
        return parent::getItem();
    }

    public function isDirectfill(): bool
    {
        return $this->directFill;
    }

    public function fetchesViaLoadData(): bool
    {
        return $this->viaLoadData;
    }

    public function setHttp(?ClientInterface $http): static
    {
        $this->client = $http;
        return parent::setHttp($http);
    }
}
