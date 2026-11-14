<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\Mailbulk\Links;
use PHPUnit\Framework\TestCase;

/** Only the web links of <a> change - everything else of the mail stays as the template wrote it. */
final class LinksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // safe()
    }

    private static function track(string $html, array $skip = []): string
    {
        return Links::track($html, static fn(string $url): string => 'https://t.example.org/hit?u=' . rawurlencode($url) . '&k=1', $skip);
    }

    private static function tracked(string $url): string
    {
        return 'https://t.example.org/hit?u=' . rawurlencode($url) . '&amp;k=1';
    }

    public function test_track_rewritesHttpLinksOnly(): void
    {
        $html = '<a href="https://a.org/x">a</a> <A HREF=\'http://b.org/\'>b</A> <a class="c" href=https://c.org/u>c</a>'
            . ' <a href="mailto:x@a.org">m</a> <a href="#top">t</a> <a href="/rel">r</a> <a href="ftp://f.org/">f</a> <a name="n">n</a>';

        self::assertSame(
            '<a href="' . self::tracked('https://a.org/x') . '">a</a> <A HREF=\'' . self::tracked('http://b.org/') . '\'>b</A> <a class="c" href="' . self::tracked('https://c.org/u') . '">c</a>'
            . ' <a href="mailto:x@a.org">m</a> <a href="#top">t</a> <a href="/rel">r</a> <a href="ftp://f.org/">f</a> <a name="n">n</a>',
            self::track($html),
        );
    }

    public function test_track_skipsGivenUrls(): void
    {
        $html = '<a href="https://a.org/out?au=x&c=abcde">out</a> <a href="https://a.org/out?au=x&amp;c=abcde">out</a> <a href="https://a.org/form">form</a> <a href="https://a.org/news">news</a>';

        self::assertSame(
            '<a href="https://a.org/out?au=x&c=abcde">out</a> <a href="https://a.org/out?au=x&amp;c=abcde">out</a> <a href="https://a.org/form">form</a> <a href="' . self::tracked('https://a.org/news') . '">news</a>',
            self::track($html, ['https://a.org/out?au=x&c=abcde', 'https://a.org/form']),
        );
    }

    public function test_track_ampEntity(): void
    {
        $seen = [];
        $html = Links::track('<a href="https://a.org/?a=1&amp;b=2">x</a>', static function (string $url) use (&$seen): string {
            $seen[] = $url;
            return 'https://t.example.org/hit?_path=v1&t=' . strlen($url);
        }, []);

        self::assertSame(['https://a.org/?a=1&b=2'], $seen);
        self::assertSame('<a href="https://t.example.org/hit?_path=v1&amp;t=22">x</a>', $html);
    }

    public function test_track_leavesImgAndOtherAttributes(): void
    {
        $html = '<img src="https://a.org/i.png" alt="a > b"><link href="https://a.org/s.css"><abbr href="https://a.org/">x</abbr>'
            . '<a data-href="https://a.org/d" title="see https://a.org/t" href="https://a.org/h" target="_blank">h</a>';

        self::assertSame(
            '<img src="https://a.org/i.png" alt="a > b"><link href="https://a.org/s.css"><abbr href="https://a.org/">x</abbr>'
            . '<a data-href="https://a.org/d" title="see https://a.org/t" href="' . self::tracked('https://a.org/h') . '" target="_blank">h</a>',
            self::track($html),
        );
    }

    public function test_track_urlTheCallbackKeeps_isUntouched(): void
    {
        $html = '<a href="https://a.org/?a=1&amp;b=2">x</a>';

        self::assertSame($html, Links::track($html, static fn(string $url): string => $url, []));
    }
}
