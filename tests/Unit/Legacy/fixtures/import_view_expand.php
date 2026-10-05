<?php

declare(strict_types=1);

// loaded by StringexpandImportTest once include/stringexpand.php is in - the class extends one of it

/** {testimportview} - a view a planned task shows, whose text another user wrote */
class AA_Stringexpand_Testimportview extends AA_Stringexpand_Nevercache
{
    public static string $text = '';

    public function expand(): string
    {
        return AA::Un(self::$text);
    }
}
