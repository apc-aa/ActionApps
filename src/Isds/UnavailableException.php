<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * ISDS asks to come back later: HTTP 503 (a planned outage), 429 (too many requests), 408 (it gave
 * up waiting for the request), or status 3008/3009 (too many parallel or repeated calls,
 * WS_manipulace_s_datovymi_zpravami.pdf 1.9.1, 1.9.2). Pause. Always "may have been sent", as
 * 503: the HTTP statuses do not say whether the request got done, and ISDS documents 3008/3009
 * only for lists and downloads - on CreateMessage one would be an answer nobody described, so the
 * message is looked up, not sent again.
 */
final class UnavailableException extends IsdsException
{
    public function mayHaveBeenSent(): bool
    {
        return true;
    }
}
