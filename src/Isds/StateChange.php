<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * One state change of a sent message (GetMessageStateChanges). A message changes more than once,
 * e.g. 4 delivered to the box, then 6 accepted by a login.
 */
final readonly class StateChange
{
    /**
     * @param int $status dmMessageStatus: 4 delivered to the box, 5 accepted after 10 days, 6 accepted
     *                    by a login, 8 undeliverable (WS_manipulace_s_datovymi_zpravami.pdf 1.5)
     * @param int $time   unix seconds of the change; an acceptance after 10 days is dated 23:59:59.999
     *                    of its day
     */
    public function __construct(public string $dmId, public int $status, public int $time) {}
}
