<?php

declare(strict_types=1);

namespace AA\Later;

/** Names of a batch run's states. The core knows four roles - active (queued or working),
 *  error, done, ended - each user names them its own way (import: running), and may add
 *  own states with where each may go (Central: preview → active). */
final class BatchStates
{
    /**
     * @param array<string, list<string>> $extra own state => states it may go to; ended is
     *                                           always allowed, so any run can be ended
     */
    public function __construct(
        public readonly string $active,
        public readonly string $error,
        public readonly string $done,
        public readonly string $ended,
        public readonly array $extra = [],
    ) {
        $all = $this->all();
        if (in_array('', $all, true) || (count(array_unique($all)) !== count($all))) {
            throw new \InvalidArgumentException('Batch states must be distinct and named');
        }
        foreach ($extra as $from => $targets) {
            if (array_diff($targets, $all) || in_array((string) $from, $targets, true)) {
                throw new \InvalidArgumentException('Batch state may go only to another known state');
            }
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        $own = array_map('strval', array_keys($this->extra));
        return array_merge([$this->active, $this->error, $this->done, $this->ended], $own);
    }

    /** Resuming an active run (try now) is no transition: the lease and next_at decide it. */
    public function mayGo(string $from, string $to): bool
    {
        $targets = match ($from) {
            $this->active => [$this->error, $this->done, $this->ended],
            $this->error  => [$this->active, $this->ended],         // resume, end
            $this->done, $this->ended => [],
            default       => isset($this->extra[$from]) ? [...$this->extra[$from], $this->ended] : [],
        };
        return in_array($to, $targets, true);
    }
}
