<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Note;
use PHPUnit\Framework\TestCase;

/** the DB-free parts: the HTML trust boundary, the resolve choices and the hiding rule */
final class NoteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // safe(), _m()
    }

    public function test_html_keeps_formatting_and_safe_links_only(): void
    {
        $this->assertSame('<b>bold</b> and <a href="https://x.org/a?b=1&amp;c" rel="noopener">link</a>', Note::sanitizeHtml('<b class="x">bold</b> and <a href="https://x.org/a?b=1&c" target="_blank" onclick="evil()">link</a>'));
        $this->assertSame('alert(1) <i>i</i>', Note::sanitizeHtml('<script>alert(1)</script> <i onmouseover="x">i</i>'));
        $this->assertSame('<a>js</a> <a href="/aa/admin/index.php" rel="noopener">rel</a> <a href="mailto:a@b.c" rel="noopener">m</a>', Note::sanitizeHtml('<a href="javascript:alert(1)">js</a> <a href=/aa/admin/index.php>rel</a> <a href=\'mailto:a@b.c\'>m</a>'));
        $this->assertSame('<a>x</a>', Note::sanitizeHtml('<a href="//evil.org/x">x</a>'), 'protocol-relative is off-site');
        $this->assertSame('x', Note::sanitizeHtml('<img src="x" onerror="evil()">x'), 'img is not allowed');
        $this->assertSame('<a>e</a> <a>t</a>', Note::sanitizeHtml('<a href="&#106;avascript:alert(1)">e</a> <a href="java' . "\t" . 'script:x">t</a>'), 'entity-obscured and split schemes');
        $this->assertSame('<b>up</b>', Note::sanitizeHtml('<B ONCLICK="x">up</B>'), 'uppercase tags lose attributes too');
    }

    public function test_until_choices(): void
    {
        $now = (int) strtotime('2026-09-11 12:00:00');
        $this->assertSame((int) strtotime('2026-09-12 00:00:00'), Note::untilFromChoice('today', '', $now));
        $this->assertSame((int) strtotime('2026-09-12 00:00:00'), Note::untilFromChoice('', '', $now), 'today is the default');
        $this->assertSame((int) strtotime('2026-09-19 00:00:00'), Note::untilFromChoice('week', '', $now));
        $this->assertSame((int) strtotime('2026-10-13 00:00:00'), Note::untilFromChoice('month', '', $now));
        $this->assertSame(0, Note::untilFromChoice('forever', '', $now));
        $this->assertSame((int) strtotime('2026-12-25 00:00:00'), Note::untilFromChoice('date', '2026-12-24', $now), 'the whole picked day');
        $this->assertSame((int) strtotime('2026-09-12 00:00:00'), Note::untilFromChoice('date', 'nonsense', $now));
    }

    public function test_hiding_rule(): void
    {
        $created = 1000;
        $this->assertTrue(Note::hidesRule($created, 0, 0, 99999), 'forever hides a state finding');
        $this->assertTrue(Note::hidesRule($created, 2000, 0, 1500), 'within the period');
        $this->assertFalse(Note::hidesRule($created, 2000, 0, 2000), 'a state finding comes back when the period ends');
        $this->assertTrue(Note::hidesRule($created, 2000, 900, 5000), 'an event finding with no occurrence since stays hidden');
        $this->assertFalse(Note::hidesRule($created, 2000, 3000, 5000), 'a new occurrence after the resolution shows it again');
        $this->assertTrue(Note::hidesRule($created, 2000, 3000, 1500), 'but not before the period ended');
    }
}
