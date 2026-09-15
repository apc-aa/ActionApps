<?php

declare(strict_types=1);

namespace AA\Later;

use AA\Util\Linkcheck;

/** Hourly validation of the links module URLs - the cron table row
 *  `modules/links/linkcheck.php` until 2026-09 (which had been failing on a
 *  missing include since Linkcheck moved to AA\Util).
 */
class LinkcheckRun implements LaterInterface
{
    public function toexecutelater($params = []): string
    {
        return 'checked ' . (new Linkcheck())->checking() . ' links';
    }
}
