<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Transformation;
use PHPUnit\Framework\TestCase;

final class TransformationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $post;

    /** @var array<string, mixed> */
    private array $request;

    protected function setUp(): void
    {
        $this->post    = $_POST;
        $this->request = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_POST    = $this->post;
        $_REQUEST = $this->request;
    }

    public function test_getRequestVariables_reads_only_the_posted_form(): void
    {
        $_POST    = ['transformValuesearchpattern' => 'a', 'transformValuereplacestring' => 'b', 'other' => 'x'];
        $_REQUEST = ['transformValuesearchpattern' => 'from-cookie', 'transformValueunalias' => '1'] + $_POST;

        $this->assertSame(
            ['searchpattern' => 'a', 'replacestring' => 'b'],
            AA_Transformation::getRequestVariables('transform', 'AA_Transformation_Value'),
        );
    }

    public function test_getRequestVariables_ignores_a_foreign_class(): void
    {
        $_POST = ['transformValuesearchpattern' => 'a'];
        $this->assertSame([], AA_Transformation::getRequestVariables('transform', 'Value'));
    }
}
