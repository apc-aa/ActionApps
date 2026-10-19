<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * The id of a slice or a site as AA stores it: 32 hexadecimal digits in small letters.
 */
final class ModuleId
{
    /** null when $id is not one; is_long_id() takes capitals too */
    public static function normalize(string $id): ?string
    {
        return is_long_id($id) ? strtolower($id) : null;
    }
}
