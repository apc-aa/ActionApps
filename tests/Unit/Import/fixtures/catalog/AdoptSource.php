<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\CatalogAdoptable;
use AA\Import\CatalogEntry;
use AA\Import\Cataloged;
use AA\Import\CatalogPage;
use AA\Import\Context;
use AA\Import\SliceIdSetting;
use AA\Import\Stream;

/** A source of an installation that adopts catalog entries into the slice the test sets, and records every call of its code */
final class AdoptSource extends AbstractSource implements Cataloged, CatalogAdoptable
{
    /** @var list<string> fromSettings, streams, jobs, catalog, adoptSlice, adopt in order */
    public static array $calls = [];
    /** @var list<CatalogEntry> what adopt() got */
    public static array $adopted = [];
    public static string $adoptSlice = '';
    /** thrown by adopt() */
    public static ?\Throwable $failAdopt = null;
    /** @var list<array{vendor: string, context: string}> */
    public static array $credentials = [];
    /** the vendor of a key adopt() asks Context for; null = none */
    public static ?string $askKey = null;
    /** whether adopt() got the key it asked for */
    public static bool $keySeen = false;

    public static function name(): string
    {
        return 'Adopting';
    }

    public static function description(): string
    {
        return 'Adds streams from its catalog';
    }

    public static function getClassProperties(): array
    {
        return ['defs' => new AA_Property('defs', 'Slice of definitions', 'string', false, true, new SliceIdSetting())];
    }

    public static function fromSettings(array $settings): static
    {
        self::$calls[] = 'fromSettings';
        return new static();
    }

    public function streams(): iterable
    {
        self::$calls[] = 'streams';
        return [];
    }

    public function jobs(Stream $stream, Context $ctx, ?string $known): iterable
    {
        self::$calls[] = 'jobs';
        return [];
    }

    public static function credentials(): array
    {
        return self::$credentials;
    }

    public function catalog(Context $ctx, string $cursor): CatalogPage
    {
        self::$calls[] = 'catalog';
        return new CatalogPage([]);
    }

    public function adoptSlice(): string
    {
        self::$calls[] = 'adoptSlice';
        return self::$adoptSlice;
    }

    public function adopt(CatalogEntry $e, Context $ctx): string
    {
        self::$calls[]   = 'adopt';
        self::$adopted[] = $e;
        if (self::$askKey !== null) {
            self::$keySeen = ($ctx->secret(self::$askKey)?->isEmpty() === false);
        }
        if (self::$failAdopt) {
            throw self::$failAdopt;
        }
        return md5($e->key);
    }
}
