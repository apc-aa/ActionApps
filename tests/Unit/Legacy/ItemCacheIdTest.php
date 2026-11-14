<?php

declare(strict_types=1);

/**
 * The template commands whose output depends on the item cache it per item (additionalCacheParam()). Two
 * objects of one item may carry other text aliases - a mailing expands the newsletter item once per reader,
 * with the reader's links - so the cache tells them apart by AA_Item::getCacheId(), not by the id alone.
 */

namespace Tests\Unit\Legacy;

use AA_Alias;
use AA_Aliases;
use AA_Item;
use AA_Stringexpand_Expand;
use AA_Stringexpand_Foreach;
use AA_Stringexpand_Validate;
use AA_Stringexpand__;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemCacheIdTest extends TestCase
{
    private const ITEM = 'e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7e7';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @param array<string, string> $texts alias => text */
    private static function item(array $texts = [], array $aliases = []): AA_Item
    {
        $als = new AA_Aliases();
        foreach ($aliases as $name => $field) {
            $als->addAlias(new AA_Alias($name, 'f_h', $field));
        }
        foreach ($texts as $name => $text) {
            $als->addTextAlias($name, $text);
        }
        return new AA_Item(['unpacked_id.....' => [['value' => self::ITEM]]], $als);
    }

    /** @return array<string, array{class-string}> */
    public static function itemSensitive(): array
    {
        return [
            'expand'   => [AA_Stringexpand_Expand::class],
            'foreach'  => [AA_Stringexpand_Foreach::class],
            'alias'    => [AA_Stringexpand__::class],
            'validate' => [AA_Stringexpand_Validate::class],
        ];
    }

    private static function key(string $class, ?AA_Item $item): string
    {
        return (new $class(['item' => $item]))->additionalCacheParam([]);
    }

    /** an item as the views have it keeps the key it always had */
    #[DataProvider('itemSensitive')]
    public function test_additionalCacheParam_itemWithoutTextAliases_theId(string $class): void
    {
        $key = self::key($class, self::item([], ['_#HEADLINE' => 'headline........']));

        self::assertStringContainsString(self::ITEM, $key);
        self::assertSame(self::key($class, self::item()), $key);
    }

    #[DataProvider('itemSensitive')]
    public function test_additionalCacheParam_noItem_noKey(string $class): void
    {
        self::assertStringNotContainsString(self::ITEM, self::key($class, null));
    }

    #[DataProvider('itemSensitive')]
    public function test_additionalCacheParam_otherTextAliases_otherKey(string $class): void
    {
        $anna = self::key($class, self::item(['_#MAILUNSB' => 'https://example.org/?au=anna']));

        self::assertNotSame($anna, self::key($class, self::item(['_#MAILUNSB' => 'https://example.org/?au=bob'])));
        self::assertSame($anna, self::key($class, self::item(['_#MAILUNSB' => 'https://example.org/?au=anna'])));
    }

    public function test_getCacheId(): void
    {
        self::assertSame(self::ITEM, self::item([], ['_#HEADLINE' => 'headline........'])->getCacheId());

        $item = self::item(['_#MAILPREF' => 'a']);
        $id   = $item->getCacheId();
        self::assertStringStartsWith(self::ITEM, $id);
        self::assertNotSame(self::ITEM, $id);

        $item->addAlias(new AA_Alias('_#MAILPREF', 'f_p:b', 'id..............'));
        self::assertNotSame($id, $item->getCacheId(), 'an alias added later counts');
    }
}
