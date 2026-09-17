<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A "Filename template" of a file field (AA_Inserter_Fil) puts the uploaded file's name into
 * an AA expression before AA::Un() expands it. The name is whatever the uploader sent -
 * filler.php takes anonymous uploads - so it must reach the template escaped, or a name like
 * "{sql:...}.jpg" runs as an expression.
 */
final class FilesNameTemplateAliasesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\defined('DEFAULT_LANG_INCLUDE')) {
            \define('DEFAULT_LANG_INCLUDE', 'en_utf8.php3');
        }
        require_once __DIR__ . '/../../../include/files.class.php';
    }

    public function test_plain_name_gives_the_aliases_the_upload_ends_up_with(): void
    {
        $this->assertSame(['_#name' => 'my-photo', '_#ext' => 'jpg', '_#full' => 'my-photo.jpg'], \Files::nameTemplateAliases('My Photo.JPG'));
    }

    /** @return array<string, array{0: string}> */
    public static function hostileNames(): array
    {
        return [
            'expression'         => ['{sql:select 1}.jpg'],
            'expression in ext'  => ['photo.{include:x}'],
            'alias'              => ['_#HEADLINE.jpg'],
            'nested'             => ['a{b:{c}}.jpg'],
            'path'               => ['../../{x}/photo.jpg'],
        ];
    }

    #[DataProvider('hostileNames')]
    public function test_no_template_syntax_survives(string $name): void
    {
        foreach (\Files::nameTemplateAliases($name) as $alias => $value) {
            $this->assertDoesNotMatchRegularExpression('~[{}:#/]~', $value, "$alias of $name");
        }
    }
}
