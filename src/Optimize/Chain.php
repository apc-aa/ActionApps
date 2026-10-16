<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
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
     *  on the same DB server run their own. The lock connection is never persistent, so a die()
     *  or a fatal error that skips the release frees the lock at the end of the script.
     *  @param ?int $holder  set to the MySQL connection id holding the lock when it is held
     *  @return \Closure(): void|null  releases the lock (once); null when another run holds it
     */
    public static function tryLock(string $type, ?int &$holder = null): ?\Closure
    {
        $name = 'aa_optimize_' . $type;
        if (!DB_AA::lock($name, 0, $holder)) {
            return null;
        }
        $held = true;
        return static function () use (&$held, $name): void {
            if ($held) {
                $held = false;
                DB_AA::unlock($name);
            }
        };
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
