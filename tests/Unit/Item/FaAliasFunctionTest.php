<?php

declare(strict_types=1);

// AA_Item lives in include/item.php whose top-level require chain (stringexpand →
// perm_core → util → locsess) demands a handful of constants normally provided by
// include/config.php. Define safe test values before composer autoload resolves
// AA_Item, so the chain loads without a real config/DB.

namespace {
    foreach ([
        'AA_INSTAL_URL'     => 'http://localhost/aa/',
        'AA_INSTAL_PATH'    => '/aa/',
        'IMG_UPLOAD_PATH'   => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'      => dirname(__DIR__, 3) . '/',
        'PERM_LIB'          => 'sql',
        'DB_HOST'           => 'localhost',
        'DB_NAME'           => 'aa_test',
        'DB_USER'           => 'aa',
        'DB_PASSWORD'       => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Item {

    use AA_Item;
    use PHPUnit\Framework\TestCase;

    /**
     * AA_Item stub that bypasses content4id / DB / alias resolution so f_a can be
     * exercised in isolation. Each test seeds the field-id → value map directly.
     */
    final class FaTestItem extends AA_Item
    {
        /** @param array<string,string> $values */
        public function __construct(private array $values)
        {
            // intentionally skip parent::__construct — no content4id, aliases, etc. needed
        }

        public function getval($field_id, $index = 0)
        {
            return $this->values[$field_id] ?? '';
        }

        public function getFlag($field_id): int
        {
            return 0;   // plain text — text2Html will apply nl2br/safe()
        }

        /** Resolve a token to a field value if it's a known id; otherwise return as-is. */
        public function subst_alias($text): string
        {
            return array_key_exists((string) $text, $this->values) ? $this->values[$text] : (string) $text;
        }

        public function subst_aliases($var): array|string
        {
            if (!is_array($var)) {
                return $this->subst_alias($var);
            }
            return array_map(fn($v) => $this->subst_alias($v), $var);
        }
    }

    final class FaAliasFunctionTest extends TestCase
    {
        private const COL          = 'abstract........';
        private const FULLTEXT     = 'full_text.......';
        private const ABSTRACT_TXT = 'Abstract content text long enough for testing purposes blah blah blah blah blah blah blah';   // > 80 chars
        private const FULLTEXT_TXT = 'Full text content here, much longer text for the body of the article with more text';          // > 80 chars

        private function item(string $abstract = '', string $fulltext = ''): FaTestItem
        {
            return new FaTestItem([
                self::COL      => $abstract,
                self::FULLTEXT => $fulltext,
            ]);
        }

        // ----- legacy `f_a:` (no params) — show whole abstract or empty -----

        public function test_legacy_no_params_with_abstract_returns_whole_value(): void
        {
            $out = $this->item(self::ABSTRACT_TXT)->f_a(self::COL, '');
            // text2Html wraps with nl2br + safe() but the substring should appear verbatim (no HTML in source)
            $this->assertStringContainsString(self::ABSTRACT_TXT, $out);
        }

        public function test_legacy_no_params_with_empty_abstract_returns_empty_without_typeerror(): void
        {
            // Bug 2 regression: pre-fix this triggered PHP 8 TypeError from substr("", 0, "")
            $this->assertSame('', $this->item('')->f_a(self::COL, ''));
        }

        // ----- bug 1: f_a:80::1 must actually shorten -----

        public function test_length_only_paragraph_mode_shortens(): void
        {
            // Bug 1 regression: pre-fix the length was ignored and the whole value was returned
            $out = $this->item(self::ABSTRACT_TXT)->f_a(self::COL, '80::1');
            $this->assertLessThanOrEqual(80, strlen($out));
            $this->assertNotSame(self::ABSTRACT_TXT, $out);
        }

        public function test_length_only_no_paragraph_mode_substrs(): void
        {
            // Bug 1 variant: f_a:80::0 also previously returned full text
            $out = $this->item(self::ABSTRACT_TXT)->f_a(self::COL, '80::0');
            $this->assertSame(80, strlen($out));
            $this->assertSame(substr(self::ABSTRACT_TXT, 0, 80), $out);
        }

        public function test_length_only_missing_trailing_params_does_not_warn(): void
        {
            // Bug 2 variant: f_a:80 explodes to 1 element → 2 undefined-key warnings without array_pad.
            // Promote PHP warnings to exceptions so the test fails if they fire.
            set_error_handler(static function (int $errno, string $errstr): bool {
                throw new \ErrorException($errstr, 0, $errno);
            });
            try {
                $out = $this->item(self::ABSTRACT_TXT)->f_a(self::COL, '80');
                $this->assertSame(80, strlen($out));
            } finally {
                restore_error_handler();
            }
        }

        // ----- f_a:80:full_text:1 — show whole abstract if present, else shortened fulltext -----

        public function test_separate_fulltext_with_abstract_returns_whole_abstract(): void
        {
            $out = $this->item(self::ABSTRACT_TXT, self::FULLTEXT_TXT)
                ->f_a(self::COL, '80:' . self::FULLTEXT . ':1');
            $this->assertStringContainsString(self::ABSTRACT_TXT, $out);
            $this->assertStringNotContainsString(self::FULLTEXT_TXT, $out);
        }

        public function test_separate_fulltext_with_empty_abstract_shortens_fulltext(): void
        {
            $out = $this->item('', self::FULLTEXT_TXT)
                ->f_a(self::COL, '80:' . self::FULLTEXT . ':1');
            $this->assertLessThanOrEqual(80, strlen($out));
            $this->assertNotSame('', $out);
        }

        public function test_separate_fulltext_paragraph_off_with_empty_abstract_substrs_fulltext(): void
        {
            // Bug 3 (dormant): substr branch previously had no fallback to $pfield,
            // so this returned '' instead of substr'd fulltext.
            $out = $this->item('', self::FULLTEXT_TXT)
                ->f_a(self::COL, '80:' . self::FULLTEXT . ':0');
            $this->assertSame(substr(self::FULLTEXT_TXT, 0, 80), $out);
        }

        // ----- f_a:80:abstract........:1 — explicit same field, must shorten -----

        public function test_explicit_same_field_shortens(): void
        {
            $out = $this->item(self::ABSTRACT_TXT)
                ->f_a(self::COL, '80:' . self::COL . ':1');
            $this->assertLessThanOrEqual(80, strlen($out));
            $this->assertNotSame(self::ABSTRACT_TXT, $out);
        }

        // ----- safety: $plength resolving to non-numeric after alias resolution -----

        public function test_non_numeric_length_after_alias_resolution_does_not_fatal(): void
        {
            $item = new FaTestItem([self::COL => self::ABSTRACT_TXT, 'len_alias' => 'abc']);
            $out  = $item->f_a(self::COL, 'len_alias::0');
            $this->assertSame('', $out);   // (int)'abc' = 0, substr returns ''
        }
    }
}
