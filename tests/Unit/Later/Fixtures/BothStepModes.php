<?php

declare(strict_types=1);

namespace Tests\Unit\Later\Fixtures;

use AA;
use Tests\Unit\Central\Package\Fixtures\TableDb;

/** One test body for both step modes of ScriptedBatchRun: work() outside any transaction (import)
 *  and work() in the transaction that ends the step (Central). A row of the step flow must hold in both. */
trait BothStepModes
{
    /** @return array<string, array{bool}> */
    public static function bothModes(): array
    {
        return ['work outside a transaction' => [false], 'work in the step\'s transaction' => [true]];
    }

    /** $cases of a data provider, each in both modes - the mode comes first
     *  @param array<string, list<mixed>> $cases
     *  @return array<string, list<mixed>> */
    private static function inBothModes(array $cases): array
    {
        $all = [];
        foreach (self::bothModes() as $mode => [$inTransaction]) {
            foreach ($cases as $name => $args) {
                $all["$name, $mode"] = [$inTransaction, ...$args];
            }
        }
        return $all;
    }

    private static function mode(bool $inTransaction): void
    {
        ScriptedBatchRun::$inTransaction = $inTransaction;
    }

    /** what work() writes besides the run - the batch of a Central step */
    private static function writeBatch(string $what): void
    {
        AA::Metabase()->doInsert('log', ['time' => 1, 'user' => '', 'type' => 'BATCH', 'selector' => 'batch', 'params' => $what]);
    }

    /** @return list<string> the batches stored */
    private static function batches(): array
    {
        return array_values(array_map(static fn(array $r): string => (string) $r['params'], array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === 'BATCH')));
    }
}
