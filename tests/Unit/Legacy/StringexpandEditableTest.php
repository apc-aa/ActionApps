<?php

declare(strict_types=1);

// store_fixtures loads the real locsess.php, which needs the constants include/config.php normally provides.

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use AA;
    use AA_Alias;
    use AA_Aliases;
    use AA_Field;
    use AA_Item;
    use AA_Items;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;

    /**
     * {editable:<item>:<field>} puts the field into the page to be edited and saved back as it is
     * stored, so a command in the text must reach the browser as written - an _#ALIAS in it too,
     * though the page expands the editable as part of an alias (_#FULLEDIT = f_t:{editable:...}).
     * The same for the widget of {live}, {edit} and {editfull} in a row of a view.
     * Own process: the slice and the item stay in the module and item caches.
     */
    #[RunClassInSeparateProcess]
    final class StringexpandEditableTest extends TestCase
    {
        private const SLICE = '3dc6868dff6ab21b1028a19bd9afbd3f';
        private const ITEM  = 'a5e179dd7b4dbfa92cdd72356f0898f2';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            (new FakeSlice(self::SLICE))->setFields(new FakeFields(self::SLICE, [
                self::field('full_text.......', 'txt:16'),
                self::field('headline........', 'fld'),
            ]));
            AA::$langnum = [0];   // a page without translations
        }

        /**
         * &#35; is '#' to the browser, so the editor gets the stored text back
         * @return array<string, array{string, string, bool, string, string}> field, stored text, is HTML, element, its content
         */
        public static function sources(): array
        {
            return [
                'command with an alias' => ['full_text.......', '<p>{siteitem:technicka-specifikace:_#FULLTEXT}</p>', true, 'div', '<p>{siteitem:technicka-specifikace:_&#35;FULLTEXT}</p>'],
                'alias in an attribute' => ['full_text.......', '<p><a href="_#SEO_URL_">{headline........}</a></p>', true, 'div', '<p><a href="_&#35;SEO_URL_">{headline........}</a></p>'],
                'plain text'            => ['headline........', 'Price _#HEADLINE & {ids:x}', false, 'span', 'Price _&#35;HEADLINE &amp; {ids:x}'],
            ];
        }

        #[DataProvider('sources')]
        public function test_expand_inAliasOfThePage_browserGetsTheSource(string $field_id, string $source, bool $html, string $tag, string $content): void
        {
            $page = AA::Un('<main>_#FULLEDIT</main>', '', self::item($field_id, $source, $html));

            $field = preg_quote($field_id, '~');
            self::assertMatchesRegularExpression("~^<main><$tag contenteditable=true [^>]*data-aa-field=\"$field\"[^>]*>.*</$tag></main>$~s", $page);
            preg_match("~<$tag [^>]*>(.*)</$tag>~s", $page, $match);
            self::assertSame($content, $match[1]);
        }

        /**
         * the widget's control holds the stored text - the browser decodes the HTML to it
         * @return array<string, array{string, string, string}> command, field, stored text
         */
        public static function widgets(): array
        {
            $text  = "<p>{siteitem:technicka-specifikace:_#HEADLINE}</p>\n<p>_#SEO_URL_ & {ids:x}</p>";
            $short = 'Price _#HEADLINE & {ids:x} <b>"_#SEO_URL_"</b>';
            return [
                'live textarea'     => ['live', 'full_text.......', $text],
                'edit textarea'     => ['edit', 'full_text.......', $text],
                'editfull textarea' => ['editfull', 'full_text.......', $text],
                'live input'        => ['live', 'headline........', $short],
                'edit input'        => ['edit', 'headline........', $short],
                'editfull input'    => ['editfull', 'headline........', $short],
            ];
        }

        #[DataProvider('widgets')]
        public function test_expand_inRowOfAView_widgetHoldsTheSource(string $command, string $field_id, string $source): void
        {
            $page = AA::Un("<main>{{$command}::$field_id}</main>", '', self::item($field_id, $source, $field_id === 'full_text.......'));

            self::assertSame($source, self::control($page, $field_id));
        }

        /**
         * only the control holds the stored value - the callback, the label and the help around it are
         * the template's own text, so the page substitutes their _#ALIASES; an alias in braces is
         * expanded before the command, wherever it is
         * @return array<string, array{string, string}> command, what the page holds
         */
        public static function widgetParts(): array
        {
            return [
                'callback'               => ["{live::headline........::f('_#HEADLINE')}", "this, f('Price'))"],
                'callback in braces'     => ["{live::headline........::f('{_#HEADLINE}')}", "this, f('Price'))"],
                'label'                  => ['{editfull::headline........::::{"name":"Name _#HEADLINE","input_help":"Help _#HEADLINE"}}', '>Name Price</label>'],
                'help'                   => ['{editfull::headline........::::{"name":"Name _#HEADLINE","input_help":"Help _#HEADLINE"}}', '<small>Help Price</small>'],
                'placeholder in control' => ['{edit::headline........::::{"placeholder":"e.g. _#HEADLINE"}}', 'placeholder="e.g. _&#35;HEADLINE"'],
                'placeholder in braces'  => ['{edit::headline........::::{"placeholder":"e.g. {_#HEADLINE}"}}', 'placeholder="e.g. Price"'],
            ];
        }

        #[DataProvider('widgetParts')]
        public function test_expand_widgetParts_onlyTheControlKeepsAliases(string $command, string $expected): void
        {
            $page = AA::Un("<main>$command</main>", '', self::item('headline........', 'Price', false));

            self::assertStringContainsString($expected, $page);
        }

        /** the field keeps one widget for the whole page - other commands showing it must get its aliases as before */
        public function test_expand_sameFieldElsewhere_widgetUnchanged(): void
        {
            $item = self::item('headline........', 'Price _#HEADLINE', false);
            AA::Un('<main>{edit::headline........}</main>', '', $item);
            $field = \AA_Slice::getModule(self::SLICE)->getField('headline........');

            self::assertStringContainsString('value="Price _#HEADLINE"', $field->getWidget()->getOnlyHtml($field->getAaProperty(), $item->getItemContent()));
        }

        /** the value of the form control as the browser reads it */
        private static function control(string $page, string $field_id): string
        {
            $dom = new \DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8"?>' . $page, LIBXML_NOERROR | LIBXML_NOWARNING);
            $name = 'aa[u' . self::ITEM . '][' . str_replace('.', '_', $field_id) . '][0]';
            $el   = (new \DOMXPath($dom))->query("//textarea[@name='$name'] | //input[@name='$name']")->item(0);
            self::assertInstanceOf(\DOMElement::class, $el, "no control $name in: $page");
            return ($el->tagName === 'textarea') ? $el->textContent : $el->getAttribute('value');
        }

        private static function item(string $field_id, string $source, bool $html): AA_Item
        {
            $aliases = (new AA_Aliases())
                ->addAlias(new AA_Alias('_#FULLEDIT', "f_t:{editable:{id..............}:$field_id}", $field_id))
                ->addAlias(new AA_Alias('_#FULLTEXT', 'f_y:', 'full_text.......'))
                ->addAlias(new AA_Alias('_#HEADLINE', 'f_h:', 'headline........'))
                ->addAlias(new AA_Alias('_#SEO_URL_', 'f_t:https://example.org/', 'headline........'));
            $content = [
                'u_slice_id......' => [['value' => self::SLICE]],
                'unpacked_id.....' => [['value' => self::ITEM]],
                'short_id........' => [['value' => 7]],
                'headline........' => [['value' => 'The article', 'flag' => 0]],
                'full_text.......' => [['value' => 'The whole article', 'flag' => FLAG_HTML]],
            ];
            $content[$field_id] = [['value' => $source, 'flag' => $html ? FLAG_HTML : 0]];
            // {editable:<item id>:...} takes the item from the cache, as a page that shows it does
            return AA_Items::singleton()->_store(new AA_Item($content, $aliases));
        }

        private static function field(string $id, string $widget): AA_Field
        {
            return new AA_Field(['id' => $id, 'slice_id' => pack_id(self::SLICE), 'name' => $id, 'input_show_func' => $widget, 'input_insert_func' => 'qte', 'input_validate' => 'text', 'text_stored' => 1] + array_fill_keys(['content_edit', 'multiple', 'required', 'html_default', 'html_show', 'input_pri', 'search_pri', 'feed'], 0) + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }
    }
}
