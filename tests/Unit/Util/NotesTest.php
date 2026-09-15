<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Notes;
use PHPUnit\Framework\TestCase;

/** the DB-free part of the renderer: the one-row strip preview (no util shims needed, preview() calls no globals) */
final class NotesTest extends TestCase
{
    public function test_preview_is_plain_text_on_one_line(): void
    {
        $this->assertSame('Cron is dead & the queue waits', Notes::preview("<p>Cron is <b>dead</b> &amp;\n the   queue</p><ul><li>waits</li></ul>"));
        $this->assertSame('', Notes::preview('<br>'), 'markup alone leaves nothing to preview');
    }

    public function test_preview_is_cut_by_display_width(): void
    {
        $this->assertSame('Příliš žluťoučký kůň…', Notes::preview('Příliš žluťoučký kůň úpěl ďábelské ódy', 21));
        $this->assertSame('short', Notes::preview('short', 22), 'a text that fits gets no ellipsis');
    }
}
