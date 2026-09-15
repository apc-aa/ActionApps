<?php

declare(strict_types=1);

namespace AA\Later;

use AA\IO\DB\DB_AA;
use AA\IO\Feed;

/** Queues the import of every feed (RSS and APC-AA) - the cron table row
 *  `admin/xmlclient.php` until 2026-09. Each feed becomes its own
 *  AA\IO\Importer task, so this run is quick whatever the feeds do.
 *  (xmlclient.php also looked for stored "general" AA\IO\Feed objects;
 *  Feed is not an AA_Object, none can exist.)
 */
class FeedsRun implements LaterInterface
{
    public function toexecutelater($params = []): string
    {
        return $this->run('write');
    }

    /** @param string $fire  Feed::feed() mode: write (queue the imports), test (dry run), display (show the sources)
     *  @return string one line per feed */
    public function run(string $fire): string
    {
        $todo = [];
        foreach (DB_AA::select('feed_id', 'SELECT feed_id FROM rssfeeds') as $id) {
            $todo[] = ['rss', (int) $id];
        }
        foreach (DB_AA::select('feed_id', 'SELECT feed_id FROM external_feeds') as $id) {
            $todo[] = ['aarss', (int) $id];
        }
        // random order: a feed that hangs then does not stall the same followers every run
        shuffle($todo);

        $lines = [];
        foreach ($todo as [$type, $id]) {
            $lines[] = "$type $id: " . self::load($type, $id)->feed($fire);
        }
        return implode("\n", $lines);
    }

    private static function load(string $type, int $id): Feed
    {
        $feed = new Feed();
        if ($type === 'rss') {
            $feed->loadRSSFeed($id);
        } else {
            $feed->loadAAFeed($id);
        }
        return $feed;
    }
}
