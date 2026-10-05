<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Mapping;
use AA\Import\SliceIdSetting;
use AA\Import\Stream;

/** A source of an installation whose streams and failures the test sets - static, as the Registry creates it */
final class ServiceSource extends AbstractSource
{
    /** @var list<string> keys of the streams, in order */
    public static array $streamKeys = ['s1', 's2', 's3'];
    /** thrown by fromSettings() */
    public static ?\Throwable $refuse = null;
    /** thrown by streams() */
    public static ?\Throwable $failStreams = null;
    /** called by streams(): what other processes do meanwhile */
    public static ?\Closure $onStreams = null;
    /** null = the mapping below */
    public static ?Mapping $mapping = null;
    /** @var list<array<string, string>> the settings of each fromSettings() */
    public static array $created = [];
    /** thrown by getClassProperties() */
    public static ?\Throwable $failProperties = null;
    /** thrown by defaultMapping() */
    public static ?\Throwable $failMapping = null;
    /** @var list<array{vendor: string, context: string}> what credentials() gives */
    public static array $credentials = [];
    public static ?\Throwable $failKeys = null;

    /** @param array<string, string> $settings */
    public function __construct(private readonly array $settings = []) {}

    public static function name(): string
    {
        return 'Service';
    }

    public static function description(): string
    {
        return 'Streams the test sets';
    }

    public static function getClassProperties(): array
    {
        if (self::$failProperties) {
            throw self::$failProperties;
        }
        return [
            'territory' => new AA_Property('territory', 'Territory', 'string', false, true),
            'from_year' => new AA_Property('from_year', 'From year', 'string', false, true),
            'slice'     => new AA_Property('slice', 'Slice of definitions', 'string', false, true, new SliceIdSetting()),
            // a slice id declared the loose way
            'other'     => new AA_Property('other', 'Another slice', 'string', false, true, 'id'),
            'code'      => new AA_Property('code', 'Code', 'string', false, true, ['text', ['maxlength' => 2000]]),
            // validators that hand back an int
            'year'      => new AA_Property('year', 'From year', 'int', false, true, 'int'),
            'active'    => new AA_Property('active', 'Active only', 'bool', false, true, 'bool'),
            // shown, never edited in the import detail
            'fixed'     => new AA_Property('fixed', 'Fixed', 'readonly', false, true, 'text'),
            'object'    => new AA_Property('object', 'Object', 'AA_Validate_Text', false, true, 'text'),
            'tags'      => new AA_Property('tags', 'Tags', 'string', true, true, 'text'),
        ];
    }

    public static function fromSettings(array $settings): static
    {
        self::$created[] = $settings;
        if (self::$refuse) {
            throw self::$refuse;
        }
        return new static($settings);
    }

    public function streams(): iterable
    {
        if (self::$onStreams) {
            (self::$onStreams)();
        }
        if (self::$failStreams) {
            throw self::$failStreams;
        }
        return array_map(static fn(string $key): Stream => new Stream($key, "Stream $key", []), self::$streamKeys);
    }

    public function jobs(Stream $stream, Context $ctx, ?string $known): iterable
    {
        return [];
    }

    public static function credentials(): array
    {
        if (self::$failKeys) {
            throw self::$failKeys;
        }
        return self::$credentials;
    }

    public function defaultMapping(): Mapping
    {
        if (self::$failMapping) {
            throw self::$failMapping;
        }
        // the slice setting goes into an expression, as a source of definitions does
        $validate = isset($this->settings['slice']) && ($this->settings['slice'] !== '') ? ['{ids:' . $this->settings['slice'] . '}'] : ['1'];
        return self::$mapping ?? new Mapping([['to' => 'headline........', 't' => 'copyfield', 'p' => ['field2copy' => 'key']]], ['headline........'], 'update', $validate, '{recompute:x}');
    }
}
