<?php

declare(strict_types=1);

namespace AA\Central;

/** Property values of serialized objects by their bare names - for __unserialize() of classes whose
 *  older rows were written under another class name or visibility. */
final class SerializedProps
{
    /** The names carry the visibility: "\0*\0name" (protected), "\0Class\0name" (private - the
     *  class the row was written with, maybe the old one), "name" (public).
     *  @return array<string, mixed> */
    public static function bare(array $data): array
    {
        $ret = [];
        foreach ($data as $key => $value) {
            $pos = strrpos((string) $key, "\0");
            $ret[($pos === false) ? (string) $key : substr((string) $key, $pos + 1)] = $value;
        }
        return $ret;
    }
}
