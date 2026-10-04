<?php

declare(strict_types=1);

namespace Tests\Unit\IO\Fixtures;

use AA\IO\Grabber\AbstractGrabber;
use AA_Content;

/** A grabber over a fixed list of items - what a Saver test feeds in instead of a remote source.
 *  Counts what the Saver asked of it, so a test can tell how far a run got and that it was closed. */
class ArrayGrabber extends AbstractGrabber
{
    /** prepare() calls */
    public int $prepared = 0;
    /** items handed out by getItem() */
    public int $taken = 0;
    /** finish() calls */
    public int $finished = 0;

    private int $pos = 0;

    /**
     * @param list<AA_Content>          $items         handed out in order, then null
     * @param ?\Exception               $prepare_error thrown from prepare(), as an unreachable source would
     * @param ?\Closure(int): void      $on_get        called with the 1-based number of each item handed out
     */
    public function __construct(
        private array $items,
        private ?\Exception $prepare_error = null,
        private ?\Closure $on_get = null,
    ) {}

    public function prepare(): void
    {
        $this->prepared++;
        $this->pos = 0;
        if ($this->prepare_error) {
            throw $this->prepare_error;
        }
    }

    public function getItem(): ?AA_Content
    {
        if (!isset($this->items[$this->pos])) {
            return null;
        }
        $item = $this->items[$this->pos++];
        $this->taken++;
        if ($this->on_get) {
            ($this->on_get)($this->taken);
        }
        return $item;
    }

    public function finish(): void
    {
        $this->finished++;
    }
}
