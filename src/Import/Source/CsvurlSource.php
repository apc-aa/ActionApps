<?php

declare(strict_types=1);

namespace AA\Import\Source;

use AA\Import\AbstractSource;
use AA\Import\Checkable;
use AA\Import\CheckResult;
use AA\Import\Context;
use AA\Import\Job;
use AA\Import\Mapping;
use AA\Import\Probe;
use AA\Import\Probeable;
use AA\Import\Stream;
use AA\IO\Grabber\CSV;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA_Property;

/**
 * A CSV file at a public http(s) address, its first row naming the columns. It has no mapping editor
 * behind it, so the settings carry the mapping: which column fills which field, and which one is the key.
 */
final class CsvurlSource extends AbstractSource implements Checkable, Probeable
{
    /** the grabber parses the whole file into memory, several times its size */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Host without user:password@ - a key belongs in credentials(), not in a setting anyone managing the
     * import reads. It ends by (?![\s\S]), not $~D: $ lets a trailing \n pass, and the form hands the
     * pattern to the browser without its delimiters, where a modifier would break it.
     */
    private const URL = '~^https?://[^\/?#@\s]+([\/?#]\S*)?(?![\s\S])~';

    /** @param array<string, string> $columns column => field id */
    private function __construct(private readonly string $url, private readonly string $delimiter, private readonly array $columns, private readonly string $keyColumn) {}

    public static function name(): string
    {
        return _m('CSV file at a URL');
    }

    public static function description(): string
    {
        return _m('Imports the rows of a CSV file at a public http(s) address; the settings say which column fills which field.');
    }

    public static function getClassProperties(): array
    {
        return [
            'url'        => new AA_Property('url', _m('URL of the file'), 'string', false, true, ['regexp', ['pattern' => self::URL]], true, _m('http or https only, on a public address, without a user name and password'), '', 'https://example.org/data.csv'),
            'delimiter'  => self::delimiterProperty(),
            'columns'    => new AA_Property('columns', _m('Columns'), 'text', false, true, new CsvColumnsSetting(), true, _m('JSON object: the column name in the first row => the field it fills'), '', '{"Name": "headline........", "Code": "text...........1"}'),
            'key_column' => new AA_Property('key_column', _m('Key column'), 'string', false, true, 'text', true, _m('The column that identifies the item; it must be one of the columns'), '', 'Code'),
        ];
    }

    /** The delimiters of the CSV grabber, the comma shown for a blank one - as it is read - and without
     *  "other": the form has no field to type another delimiter into. */
    private static function delimiterProperty(): AA_Property
    {
        $property = CSV::getClassProperties()['delimiter'];
        $property->setConstants(array_diff_key((array) $property->getConstants(), ['' => true]));
        return $property->setDefault(',');
    }

    /** Validated here again - stored settings reach a run without the form */
    public static function fromSettings(array $settings): static
    {
        foreach (self::getClassProperties() as $name => $property) {
            $value     = $settings[$name] ?? '';
            $validator = $property->getValidator();
            if (!$validator->varempty($value) && !$validator->validate($value)) {
                throw new SourceRefused('SETTINGS', "The setting $name is not valid");
            }
        }
        $columns = CsvColumnsSetting::parse($settings['columns'] ?? '') ?? [];
        $key     = $settings['key_column'] ?? '';
        if (($key !== '') && !isset($columns[$key])) {
            throw new SourceRefused('SETTINGS', 'The key column must be one of the columns');
        }
        // the grabber reads '' as a comma and \t as a tab
        return new self($settings['url'] ?? '', $settings['delimiter'] ?? '', $columns, $key);
    }

    public function streams(): iterable
    {
        return [new Stream('csv', 'CSV', [])];
    }

    public function jobs(Stream $stream, Context $ctx, ?string $known): iterable
    {
        yield new Job('csv', (new CSV($this->url(), '', $this->delimiter))->setHttp($ctx->http()), [], $this->url);
    }

    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /** copyfield expands nothing, and the value keeps the plain-text flag the grabber gives it */
    public function defaultMapping(): Mapping
    {
        $rows = [];
        foreach ($this->columns as $column => $field_id) {
            $rows[] = ['to' => $field_id, 't' => 'copyfield', 'p' => ['field2copy' => (string) $column]];
        }
        return new Mapping($rows, ($this->keyColumn === '') ? [] : [$this->columns[$this->keyColumn]]);
    }

    /**
     * Reachable, and its first row names every mapped column: a missing one would store '' into its
     * field in every item, and a missing key column would make all rows one item.
     */
    public function check(Context $ctx): CheckResult
    {
        $body    = $this->download($ctx);
        $header  = ($body === '') ? [] : (new CSV('', $body, $this->delimiter))->firstRow();
        $missing = array_diff(array_keys($this->columns), $header);

        return $missing ? new CheckResult(false, 'COLUMNS', 'Columns missing in the first row: ' . implode(', ', $missing)) : new CheckResult(true, '', '');
    }

    /** The version is a hash of the file: exact whatever the server says of it (ETag, Last-Modified), and the size is capped */
    public function probe(Stream $s, Context $ctx, ?string $known): Probe
    {
        $version = sha1($this->download($ctx));
        return new Probe(($version === $known) ? Probe::UNCHANGED : Probe::CHANGED, $version);
    }

    /** @throws SourceFailure by the status, as a grabber would */
    private function download(Context $ctx): string
    {
        $answer  = $ctx->http()->request('GET', $this->url());
        $failure = SourceFailure::forStatus($answer->status, 'HTTP ' . $answer->status . ' from the CSV file');
        return is_null($failure) ? $answer->body : throw $failure;
    }

    private function url(): string
    {
        return ($this->url !== '') ? $this->url : throw new SourceRefused('SETTINGS', 'No URL of the CSV file');
    }
}
