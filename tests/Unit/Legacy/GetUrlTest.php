<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * get_url() puts new parameters right behind the "?", so a URL ending in an alias or a JS concatenation
     * (oedit.php?oid=_#AA_ID___) keeps its tail.
     */
    final class GetUrlTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../include/go_url.php';
        }

        #[DataProvider('urls')]
        public function test_get_url_adds_the_parameters(string $url, string $expected): void
        {
            self::assertSame($expected, get_url($url, ['x' => 1, 'y' => 'a b']));
        }

        /** @return array<string, array{string, string}> */
        public static function urls(): array
        {
            return [
                'plain'               => ['a.php', 'a.php?x=1&y=a%20b'],
                'with a query'        => ['a.php?b=2', 'a.php?x=1&y=a%20b&b=2'],
                'with a fragment'     => ['a.php#top', 'a.php?x=1&y=a%20b#top'],
                'query and fragment'  => ['a.php?b=2#top', 'a.php?x=1&y=a%20b&b=2#top'],
                'alias at the end'    => ['oedit.php?oid=_#AA_ID___', 'oedit.php?x=1&y=a%20b&oid=_#AA_ID___'],
                // an old link with a query pasted into the path - the parameters must not grow the path
                '"&" in the path'     => ['https://example.org/cz-35&wa=w/page', 'https://example.org/cz-35&wa=w/page?x=1&y=a%20b'],
                '"&" and a fragment'  => ['https://example.org/cz-35&wa=w/page#disc', 'https://example.org/cz-35&wa=w/page?x=1&y=a%20b#disc'],
            ];
        }

        public function test_get_url_without_parameters_keeps_the_url(): void
        {
            self::assertSame('a.php&b#c', get_url('a.php&b#c'));
        }
    }
}
