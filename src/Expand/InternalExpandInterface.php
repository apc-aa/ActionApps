<?php

namespace AA\Expand;

use AA_Content;

/** The possibility, how to implement internal aliases for Admin interface
 *   Such aliases then could be called as {internal:AA_Plannedtask:lastrun:...},
 *   where AA_Plannedtask is AA class, which has internal_expand() method.
 *   Such function should never leak potentially sensitive data, since
 *   anyone could call it (although we will try to do it callable just form
 *   internal AA admin interface). It should not be called outside and
 *   results are not guaranteed.
 * /
 */
interface InternalExpandInterface {
    static function internal_expand(?AA_Content $content, ...$params): string;
}