<?php

declare(strict_types=1);

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
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /** The rule list of the profile page (PrintRule() in include/profile.php) shows stored values as text */
    final class ProfileRuleListTest extends TestCase
    {
        private const BAD = '<b>x</b>';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/profile.php';
        }

        protected function setUp(): void
        {
            $GLOBALS['PROPERTY_TYPES']  = array_fill_keys(['input_view', 'listlen', 'admin_perm', 'admin_order', 'admin_search', 'hide', 'show', 'fill', 'hide&fill', 'predefine', 'ui_manager', 'ui_inputform'], 'Label');
            $GLOBALS['SORTORDER_TYPES'] = ['+' => 'Ascending', '-' => 'Descending'];
            $GLOBALS['uid']             = '1';
        }

        private static function row(array $rule, array $fields = []): string
        {
            ob_start();
            PrintRule($rule + ['id' => 7, 'selector' => ''], $fields);
            return (string) ob_get_clean();
        }

        /** @return array<string, array{array<string, string>, array<string, string>}> */
        public static function rules(): array
        {
            $field = ['headline........' => self::BAD];
            return [
                'value'           => [['property' => 'listlen', 'value' => self::BAD], []],
                'order field'     => [['property' => 'admin_order', 'value' => 'headline........+'], $field],
                'search value'    => [['property' => 'admin_search', 'value' => 'headline........:' . self::BAD], ['headline........' => 'Headline']],
                'search field'    => [['property' => 'admin_search', 'value' => 'headline........:x'], $field],
                'hidden field'    => [['property' => 'hide', 'value' => '1', 'selector' => 'headline........'], $field],
                'hidden selector' => [['property' => 'hide', 'value' => '1', 'selector' => self::BAD], [self::BAD => 'Headline']],
                'fill parameter'  => [['property' => 'fill', 'value' => '0:txt:' . self::BAD, 'selector' => 'headline........'], ['headline........' => 'Headline']],
                'fill field'      => [['property' => 'fill', 'value' => '0:txt:x', 'selector' => 'headline........'], $field],
                'ui value'        => [['property' => 'ui_inputform', 'value' => self::BAD, 'selector' => 'add_title'], []],
            ];
        }

        #[DataProvider('rules')]
        public function test_stored_values_are_printed_as_text(array $rule, array $fields): void
        {
            $html = self::row($rule, $fields);
            $this->assertStringNotContainsString(self::BAD, $html);
            $this->assertStringContainsString(safe(self::BAD), $html);
        }

        public function test_search_rule_names_its_field(): void
        {
            $this->assertStringContainsString('<td>headline........<small> (Headline)</small>&nbsp;</td>', self::row(['property' => 'admin_search', 'value' => 'headline........:x'], ['headline........' => 'Headline']));
        }
    }
}
