<?php

declare(strict_types=1);

namespace AA\Tests\Unit\Util;

use AA\Util\Adminlink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminlinkTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function headlines(): array
    {
        return [
            'plain text'                   => ['Spring fair', 'Spring fair'],
            'plain field escaped by f_h'   => ['Tom &amp; Jerry', 'Tom &amp; Jerry'],
            'html field keeps only text'   => ['<b>Bold</b> news', 'Bold news'],
            'script cannot run'            => ['<script>alert(1)</script>Hi', 'alert(1)Hi'],
            'attribute handler is dropped' => ['<img src=x onerror=alert(1)>Photo', 'Photo'],
            'encoded tag stays text'       => ['&lt;script&gt;', '&lt;script&gt;'],
            'quotes are escaped'           => ['"Quote" \'single\'', '&quot;Quote&quot; &apos;single&apos;'],
        ];
    }

    #[DataProvider('headlines')]
    public function test_headlineText_returnsEscapedPlainText(string $formatted, string $expected): void
    {
        $this->assertSame($expected, Adminlink::headlineText($formatted));
    }
}
