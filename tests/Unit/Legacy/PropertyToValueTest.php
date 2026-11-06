<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Property;
use AA_Value;
use PHPUnit\Framework\TestCase;

/**
 * AA_Property::toValue() (include/widget.class.php) turns a posted form value into the object's
 * value. The validators take a string, and a crafted POST can send an array instead.
 */
final class PropertyToValueTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_toValue_singleStringPassesThrough(): void
    {
        $property = new AA_Property('title', 'Title', 'text');
        $this->assertSame('Hello', $property->toValue(new AA_Value('Hello')));
    }

    public function test_toValue_multiStringsPassThrough(): void
    {
        $property = new AA_Property('tags', 'Tags', 'text', true);
        $this->assertSame(['a', 'b'], $property->toValue(new AA_Value(['a', 'b'])));
    }

    public function test_toValue_singleArrayBecomesEmpty(): void
    {
        $property = new AA_Property('title', 'Title', 'text');
        $value    = new AA_Value('');
        $value->setValues([['nested']]);
        $this->assertSame('', $property->toValue($value));
    }

    public function test_toValue_multiArrayItemBecomesEmpty(): void
    {
        $property = new AA_Property('tags', 'Tags', 'text', true);
        $value    = new AA_Value('');
        $value->setValues(['a', ['nested']]);
        $this->assertSame(['a', ''], $property->toValue($value));
    }
}
