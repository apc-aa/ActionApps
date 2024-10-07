<?php

namespace AA\Util;

class Txt {
    public static function decoratedName(string $name, bool $deleted = false): string {
        return $deleted ? (Langs::isUTF() ? "××× $name ×××" : "xxx $name xxx")  : $name;
    }
}