<?php

declare(strict_types=1);

namespace Tests\Unit\Central;

use AA\Central\RemoteHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemoteHtmlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // the real _m() for the "(no output)" note
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_sandboxed_escapes_html_into_srcdoc(): void
    {
        $out = RemoteHtml::sandboxed('Remote', '<script>alert("x")</script><b title=\'q\'>A &amp; B</b>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<b ', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&lt;b title=&apos;q&apos;&gt;A &amp;amp; B&lt;/b&gt;"', $out);
    }

    public function test_sandboxed_grants_no_script_or_origin(): void
    {
        $out = RemoteHtml::sandboxed('Remote', '<p>report</p>');

        $this->assertMatchesRegularExpression('/<iframe sandbox srcdoc="/', $out);
        $this->assertStringNotContainsString('allow-', $out);
    }

    public function test_sandboxed_report_carries_blocking_csp_first(): void
    {
        $out = RemoteHtml::sandboxed('Remote', '<img src="https://tracker.example/x.png">');

        $this->assertStringContainsString('srcdoc="&lt;meta http-equiv=&quot;Content-Security-Policy&quot; content=&quot;default-src &apos;none&apos;; style-src &apos;unsafe-inline&apos;; img-src data:&quot;&gt;&lt;img', $out);
    }

    public function test_sandboxed_escapes_title_in_heading_and_attribute(): void
    {
        $out = RemoteHtml::sandboxed('Org "<i>" (https://x/)', '<p>r</p>');

        $this->assertStringNotContainsString('<i>', $out);
        $this->assertStringContainsString('title="Org &quot;&lt;i&gt;&quot; (https://x/)"', $out);
        $this->assertStringContainsString('<h3>Org &quot;&lt;i&gt;&quot; (https://x/)</h3>', $out);
    }

    /** @return array<string, array{string}> */
    public static function emptyOutputs(): array
    {
        return ['empty' => [''], 'whitespace' => [" \n\t "]];
    }

    #[DataProvider('emptyOutputs')]
    public function test_sandboxed_empty_output_renders_no_iframe(string $html): void
    {
        $out = RemoteHtml::sandboxed('<Remote>', $html);

        $this->assertStringNotContainsString('<iframe', $out);
        $this->assertSame('<h3>&lt;Remote&gt;</h3><p>(no output)</p>', $out);
    }

    /** @return array<string, array{string}> */
    public static function hostileBytes(): array
    {
        return [
            'quote'           => ['"'],
            'nul'             => ["\0\""],
            'overlong quote'  => ["\xC0\x22"],
            'invalid byte'    => ["\xFF\""],
            'iso-2022-jp esc' => ["\x1b\$B\""],
            'close iframe'    => ['</iframe><script>alert(1)</script>'],
        ];
    }

    /** No byte sequence may close the srcdoc attribute early: the raw `"` count is the benign one. */
    #[DataProvider('hostileBytes')]
    public function test_sandboxed_attribute_cannot_be_closed_early(string $html): void
    {
        $benign = RemoteHtml::sandboxed('Remote', 'x');
        $out    = RemoteHtml::sandboxed('Remote', $html);

        $this->assertSame(substr_count($benign, '"'), substr_count($out, '"'));
        $this->assertSame(1, substr_count($out, '<iframe'));
        $this->assertSame(1, substr_count($out, '</iframe>'));
        $this->assertStringNotContainsString('<script', $out);
    }
}
