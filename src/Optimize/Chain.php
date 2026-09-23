<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_PDO;
use AA\Util\Txt;
use AA_Serializable;
use Throwable;

/** Runs all Optimize classes of one type (like 'sql_update') in priority order, as
 *  service/sql_update.php does - Central's Do_Optimize_Type responder runs the same chain,
 *  so a remote AA picks the classes of its own version.
 *  No static name(): the Optimize manager lists only the classes that have one.
 */
final class Chain
{
    /** @return array<string, AbstractOptimize> lowest priority() first, then by class name */
    public static function optimizers(string $type): array
    {
        $optimizers = [];
        foreach (CheckRunner::classes([$type]) as $optimize_class) {
            $optimizers[sprintf("%05s", $optimize_class::priority()) . $optimize_class] = AA_Serializable::factory($optimize_class);
        }
        ksort($optimizers);
        return $optimizers;
    }

    /** test() or repair() as one answer: whatever the optimizers print (DB_PDO::haltmsg() under
     *  set_halt('report')) and a thrown error go into the report, not around the caller's response.
     *  @param 'test'|'repair'     $method
     *  @param AbstractOptimize[]  $optimizers
     *  @return array{0: bool, 1: string}  all passed, 'OK '|'Err ' + printed output + HTML report
     */
    public static function run(string $method, array $optimizers): array
    {
        $chain = match ($method) {
            'test'   => self::test(...),
            'repair' => self::repair(...),
        };
        ob_start();
        try {
            [$ok, $report] = $chain($optimizers);
        } catch (Throwable $e) {
            // stops the rest of the chain, like the throw would; the table so far is lost
            [$ok, $report] = [false, '<p>' . Txt::safe(get_class($e) . ': ' . $e->getMessage()) . '</p>'];
        } finally {
            $printed = (string) ob_get_clean();
        }
        return [$ok, ($ok ? 'OK ' : 'Err ') . $printed . $report];
    }

    /** Named DB lock for running the chain of the type - two runs at once would rebuild the same
     *  tables; Do_Optimize repair and sql_update.php Restore take it too. Per database: other AAs
     *  on the same DB server run their own.
     *  A named lock lives as long as its connection, so it is taken on a connection of its own
     *  which is never persistent and never pooled: when a die() or a fatal error skips the release,
     *  the end of the script still closes the connection and frees the lock.
     *  @param ?int         $holder  set to the MySQL connection id holding the lock when it is held
     *  @param ?DB_PDO      $db      the connection to lock on - for tests
     *  @return \Closure(): void|null  releases the lock (once - it is also the shutdown function);
     *                                 null when another run holds it
     */
    public static function tryLock(string $type, ?int &$holder = null, ?DB_PDO $db = null): ?\Closure
    {
        $db   ??= new DB_PDO(self::lockConnectionParams());
        $name   = qquote(substr('aa_optimize_' . $type . '_' . constant('DB_NAME'), 0, 64));  // MySQL limit
        $db->query("SELECT GET_LOCK($name, 0) AS got, IS_USED_LOCK($name) AS holder");
        $got    = $db->next_record();
        $holder = $got ? (int) $db->f('holder') : null;
        if (!$got || (string) $db->f('got') !== '1') {   // 0 held, NULL error
            return null;
        }
        $release = static function () use (&$db, $name): void {
            if ($db) {
                $db->query("SELECT RELEASE_LOCK($name)");
                $db = null;   // the last reference - closes the connection
            }
        };
        register_shutdown_function($release);
        return $release;
    }

    /** The lock connection: config.php settings, but never persistent (see tryLock()) */
    public static function lockConnectionParams(): array
    {
        return ['persistent' => false] + dbConnectionParams();
    }

    /** "already running" - with the connection id an admin can KILL when the run is gone */
    public static function runningMessage(string $type, ?int $holder): string
    {
        return "$type is already running on this AA" . ($holder ? " (MySQL connection $holder holds the lock)" : '');
    }

    /** test() on each
     * @param AbstractOptimize[] $optimizers
     * @return array{0: bool, 1: string}  all passed, HTML table: name | OK/Problem | report
     */
    public static function test(array $optimizers): array
    {
        $msg    = '<table>';
        $result = true;
        foreach ($optimizers as $optimizer) {
            $msg .= '<tr><td>' . $optimizer->name() . '</td>';
            $res  = $optimizer->test();
            if (!$res) {
                $result = false;
            }
            $msg .= '<td>' . ($res ? 'OK' : 'Problem') . '</td>';
            $msg .= '<td>' . $optimizer->report() . '</td></tr>';
        }
        $msg .= "</table>";
        return [$result, $msg];
    }

    /** repair() on each whose test() fails (sql_update.php "Install / Update")
     * @param AbstractOptimize[] $optimizers
     * @return array{0: bool, 1: string}  all repairs succeeded, HTML table: name | repair report
     */
    public static function repair(array $optimizers): array
    {
        $msg    = '<table>';
        $result = true;
        foreach ($optimizers as $optimizer) {
            $msg .= '<tr><td>' . $optimizer->name() . '</td>';

            if ($optimizer->test()) {
                $msg .= '<td>test passed - skipping</td></tr>';
            } else {
                $optimizer->clear_report();
                $res  = $optimizer->repair();
                if (!$res) {
                    $result = false;
                }
                $msg .= '<td>' . $optimizer->report() . '</td></tr>';
            }
        }
        $msg .= "</table>";
        return [$result, $msg];
    }
}
