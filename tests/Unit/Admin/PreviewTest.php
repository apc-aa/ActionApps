<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** admin/preview.php frames the item and the navigation bar with the OK button to return_url.
 *  The page needs a session, so the tests read its source. */
final class PreviewTest extends TestCase
{
    private static function source(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . "/$file");
    }

    public function test_frames_get_the_checked_parameters_encoded_and_escaped(): void
    {
        $src = self::source('admin/preview.php');
        $this->assertStringNotContainsString('$_GET', $src, 'the parameters come only through InputVars');
        $this->assertStringContainsString('src="<?php echo con_url(StateUrl("prev_navigation.php"), [\'sh_itm\' => $sh_itm, \'return_url\' => $return_url]); ?>"', $src);
        $this->assertStringContainsString('src="<?php echo safe($preview_url); ?>"', $src);
    }

    /** the page escapes the preview URL itself, so the fallback must not come escaped already */
    public function test_preview_url_fallback_is_not_html_escaped(): void
    {
        $src = self::source('include/slice.class.php');
        $this->assertStringContainsString('$url = get_url($this->getProperty(\'slice_url\'), [\'sh_itm\' => $item_id]);', $src);
        $this->assertStringNotContainsString('con_url($this->getProperty(\'slice_url\')', $src);
    }
}
