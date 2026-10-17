<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Body;

use AA\Isds\Body\HtmlBody;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * AA switches PCRE JIT off. Without it, over 5 MB, a pattern searching the rest of the letter from
 * every "</body" takes minutes. A process of its own: going over the time limit is a fatal error.
 */
final class HtmlBodyJitTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_document_endTagsWithoutJit(): void
    {
        $jit = (string) ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        set_time_limit(20);
        try {
            $document = (new HtmlBody())->document('Pozvánka', '<p>a</p>' . str_repeat('</body', 900_000));
        } finally {
            set_time_limit(0);
            ini_set('pcre.jit', $jit);
        }

        self::assertStringEndsWith("<body>\n<p>a</p>\n</body>\n</html>\n", $document);
    }
}
