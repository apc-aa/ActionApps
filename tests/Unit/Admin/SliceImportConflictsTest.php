<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use AA\Util\Txt;
use PHPUnit\Framework\TestCase;

/** The conflict step of the slice import lists names from the imported file in textareas, which the
 *  next post reads back. The page needs a session, so the tests read its source. */
final class SliceImportConflictsTest extends TestCase
{
    public function test_every_conflict_line_is_escaped(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/sliceimp.php');
        $this->assertSame(3, preg_match_all('~foreach \(\$(?:view_|data_)?conflicts_ID as \$c_id => \$name\) \{\s*echo safe\(~', $src));
        $this->assertSame(0, preg_match('~echo (?:substr\()?\$name~', $src));
    }

    /** the lists of added, overwritten and failed slices and items name them by the file too */
    public function test_result_lists_are_escaped(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/sliceimp.php');
        $this->assertSame(5, substr_count($src, 'echo safe($desc) . "<br>";'));
        $this->assertSame(0, preg_match('~echo \$desc~', $src));
    }

    /** what the browser posts back from the textarea is the line before escaping */
    public function test_escaped_line_comes_back_unchanged(): void
    {
        $line = "News </textarea><b>&amp; \"x\" 'y'\t1234 -> 1234";
        $this->assertStringNotContainsString('</textarea>', Txt::safe($line));
        $this->assertSame($line, html_entity_decode(Txt::safe($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
