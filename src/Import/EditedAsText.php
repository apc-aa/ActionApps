<?php

declare(strict_types=1);

namespace AA\Import;

/** A validator of a setting stored in another form than the user types it: its validate() turns the
 *  typed text into the stored form, or refuses it saying where and what is wrong (lastErrMsg(), in
 *  words of the page); formText() turns the stored form back for the settings form. */
interface EditedAsText
{
    public function formText(string $stored): string;
}
