<?php

/**
 * Runner endpoint for the post-response cron fallback.
 *
 * Called via fire-and-forget async HTTP from CronFallback::maybeTrigger() when
 * fastcgi_finish_request() is not available (mod_php, CGI, plain Apache PHP).
 * Runs the toexecute queue under flock, with a gate re-check, so concurrent or
 * anonymous calls all converge to one effective execution per
 * CRON_FALLBACK_INTERVAL.
 *
 * Safe to call directly from a browser - the flock + gate re-check inside
 * CronFallback::runDeferred() prevent runaway execution. No auth needed for
 * the same reason cron.php has none: the work is gated, not arbitrary.
 *
 * @package UserOutput
 * @author  Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
*/

// Don't let our own locsess.php trigger a nested fallback for this request.
define('AA_NO_CRON_FALLBACK_THIS_REQUEST', true);

require_once __DIR__ . "/../include/config.php";
require_once __DIR__ . "/../include/locsess.php";
require_once __DIR__ . "/../include/util.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/mail.php";

// Background runner: long tasks are fine, and a disconnected client must not kill us.
MoreTimeLimit(3600);
@ignore_user_abort(true);

\AA\Later\CronFallback::runDeferred();
