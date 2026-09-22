<?php

declare(strict_types=1);

namespace AA\Later;

use AA\IO\DB\DB_AA;
use AA_Log;
use AA_Logentry_Alerts;

/** One alerts sending sweep for one frequency - what the cron table row
 *  `modules/alerts/alerts.php?howoften=...` did until 2026-09. Readers get
 *  the items new since their collection's last sending; the instant sweep
 *  runs every few minutes, the digests once per period.
 */
class AlertsSweep implements LaterInterface
{
    /** frequency => when ScheduleAaTasks plans the next sweep (strtotime() relative to now).
     *  Digests at night, so the readers find them in the morning; the exact
     *  minutes only avoid the other nightly tasks. */
    public const SCHEDULE = [
        'instant'  => '+5 minutes',
        'daily'    => 'tomorrow 01:00',
        'weekly'   => 'next monday 01:30',
        'twoweeks' => '+14 days 01:45',
        'monthly'  => 'first day of next month 02:00',
    ];

    /** seconds between two sendings of a collection with this frequency (the `fix` mode) */
    public const FREQUENCY = ['daily' => 86400, 'weekly' => 7 * 86400, 'twoweeks' => 14 * 86400, 'monthly' => 31 * 86400];

    /** The earliest moment a sweep of this frequency may reach back to. A sweep that stopped -
     *  a missing cron row, a moved install - must not mail years of backlog: on toolkit the
     *  daily sweep resumed on 2026-09-22 after 13 years and sent 52 readers items from 2014.
     *  Two periods, so one skipped run still catches up.
     *
     *  A frequency without a period of its own (instant, irregular) gets the tightest cap,
     *  never none: the instant sweep reads `last` exactly like a digest does - toexecutelater()
     *  calls send_emails() with an empty $item_id - and toolkit has instant rows sitting on
     *  2012 and 2016. An unknown name is capped for the same reason.
     */
    public static function earliestBacklog(string $howoften, int $now): int
    {
        return $now - (2 * (self::FREQUENCY[$howoften] ?? min(self::FREQUENCY)));
    }

    /** the legacy alerts module - functions in files, not autoloaded classes */
    private const REQUIRED_FILES = [
        'include/mgettext.php',
        'modules/alerts/util.php',
        'modules/alerts/lang.php',
        'include/item.php',
        'include/view.php',
        'include/searchlib.php',
        'modules/alerts/alerts_sending.php',
    ];

    /** @param bool $fix  send only to the collections not processed within their frequency (a repair run) */
    public function __construct(private string $howoften, private bool $fix = false) {}

    public function toexecutelater($params = []): string
    {
        self::requireAlerts();
        if (!isset(get_howoften_options()[$this->howoften])) {
            return "unknown frequency '$this->howoften'";
        }
        initialize_last();

        $collections = $this->fix ? $this->overdueCollections() : 'all';
        $detail      = is_array($collections) ? 'fix ' . count($collections) : 'all';   // the id list overflows log.params(128)
        AA_Log::write('ALERTS', $this->howoften, new AA_Logentry_Alerts(['phase' => 'start', 'detail' => $detail]));

        $count = send_emails($this->howoften, $collections, 'all', true, '');
        AA_Log::write('ALERTS', $this->howoften, new AA_Logentry_Alerts(['phase' => 'sent', 'count' => $count]));
        return "$this->howoften: $count mails";
    }

    /** @return array<int,string> collections whose last sending is older than their frequency */
    private function overdueCollections(): array
    {
        $sent_before = time() - (self::FREQUENCY[$this->howoften] ?? 0);
        return DB_AA::select('collectionid', 'SELECT collectionid FROM alerts_collection_howoften', [['howoften', $this->howoften], ['last', $sent_before, '<']]);
    }

    private static function requireAlerts(): void
    {
        foreach (self::REQUIRED_FILES as $file) {
            require_once constant('AA_BASE_PATH') . $file;
        }
        mgettext_bind('en', 'alerts');   // texts of the alert mails; the templates carry their own language
    }
}
