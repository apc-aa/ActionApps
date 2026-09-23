<?php

declare(strict_types=1);

namespace Tests\Unit\Central;

use AA\Central\CsvImport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvImportTest extends TestCase
{
    /** A subset of the central_conf schema is enough - the page passes the real list. */
    private const COLUMNS = ['id', 'db_pwd', 'AA_HTTP_DOMAIN', 'ORG_NAME', 'AA_ADMIN_PWD', 'status_code'];

    /** [csv file content, expected records]
     *  @return array<string, array{string, list<list<?string>>}> */
    public static function files(): array
    {
        return [
            'plain' => [
                "ORG_NAME,db_pwd\nEcn,pw\n",
                [['ORG_NAME', 'db_pwd'], ['Ecn', 'pw']],
            ],
            'bom before a quoted header' => [
                "\xEF\xBB\xBF\"ORG_NAME\",\"db_pwd\"\r\nEcn,pw\r\n",
                [['ORG_NAME', 'db_pwd'], ['Ecn', 'pw']],
            ],
            'bom before a plain header' => [
                "\xEF\xBB\xBFORG_NAME\nEcn\n",
                [['ORG_NAME'], ['Ecn']],
            ],
            'blank line is [null]' => [
                "ORG_NAME\n\nEcn\n",
                [['ORG_NAME'], [null], ['Ecn']],
            ],
            'rfc 4180 - backslash is not an escape' => [
                "db_pwd\n\"p\\\"\"x,y\"\n",
                [['db_pwd'], ['p\\"x,y']],
            ],
            'line longer than the old 10000 limit' => [
                'db_pwd' . "\n" . str_repeat('x', 20000) . "\n",
                [['db_pwd'], [str_repeat('x', 20000)]],
            ],
            'empty file' => [
                '',
                [],
            ],
        ];
    }

    /** @param list<list<?string>> $expected */
    #[DataProvider('files')]
    public function test_readRecords_reads_the_stream(string $content, array $expected): void
    {
        $handle = fopen('php://memory', 'r+b');
        fwrite($handle, $content);
        rewind($handle);

        $this->assertSame($expected, CsvImport::readRecords($handle));
        fclose($handle);
    }

    /** [header, rows (as fgetcsv returns them), expected rows]
     *  @return array<string, array{list<?string>, list<list<?string>>, list<array<string, string|int>>}> */
    public static function imports(): array
    {
        return [
            'header + 2 rows' => [
                ['ORG_NAME', 'AA_HTTP_DOMAIN'],
                [['Ecn', 'ecn.cz'], ['Apc', 'apc.org']],
                [
                    ['ORG_NAME' => 'Ecn', 'AA_HTTP_DOMAIN' => 'ecn.cz', 'status_code' => 1],
                    ['ORG_NAME' => 'Apc', 'AA_HTTP_DOMAIN' => 'apc.org', 'status_code' => 1],
                ],
            ],
            'columns in any order' => [
                ['AA_HTTP_DOMAIN', 'ORG_NAME'],
                [['ecn.cz', 'Ecn']],
                [['AA_HTTP_DOMAIN' => 'ecn.cz', 'ORG_NAME' => 'Ecn', 'status_code' => 1]],
            ],
            'id column never sets the id' => [
                ['id', 'ORG_NAME'],
                [['42', 'Ecn']],
                [['ORG_NAME' => 'Ecn', 'status_code' => 1]],
            ],
            'status_code from csv overridden' => [
                ['ORG_NAME', 'status_code'],
                [['Ecn', '3']],
                [['ORG_NAME' => 'Ecn', 'status_code' => 1]],
            ],
            'header names case-insensitive, canonical keys' => [
                ['org_name', 'Aa_Http_Domain', 'ID'],
                [['Ecn', 'ecn.cz', '42']],
                [['ORG_NAME' => 'Ecn', 'AA_HTTP_DOMAIN' => 'ecn.cz', 'status_code' => 1]],
            ],
            'spaces around header names' => [
                [' ORG_NAME ', "AA_HTTP_DOMAIN\t"],
                [['Ecn', 'ecn.cz']],
                [['ORG_NAME' => 'Ecn', 'AA_HTTP_DOMAIN' => 'ecn.cz', 'status_code' => 1]],
            ],
            'empty lines skipped' => [
                ['ORG_NAME', 'AA_HTTP_DOMAIN'],
                [[null], ['Ecn', 'ecn.cz'], [''], ['', ' '], ['Apc', 'apc.org'], [null]],
                [
                    ['ORG_NAME' => 'Ecn', 'AA_HTTP_DOMAIN' => 'ecn.cz', 'status_code' => 1],
                    ['ORG_NAME' => 'Apc', 'AA_HTTP_DOMAIN' => 'apc.org', 'status_code' => 1],
                ],
            ],
            'row with only unknown columns filled skipped' => [
                ['ORG_NAME', 'AA_HTTP_DOMAIN', 'note'],
                [['', '', 'called them'], ['Ecn', 'ecn.cz', '']],
                [['ORG_NAME' => 'Ecn', 'AA_HTTP_DOMAIN' => 'ecn.cz', 'status_code' => 1]],
            ],
            'values are kept verbatim' => [
                ['ORG_NAME', 'db_pwd'],
                [[' Ecn ', 'p"a,s\\s']],
                [['ORG_NAME' => ' Ecn ', 'db_pwd' => 'p"a,s\\s', 'status_code' => 1]],
            ],
            'trailing empty header names are no duplicates' => [
                ['ORG_NAME', '', ''],
                [['Ecn', '', '']],
                [['ORG_NAME' => 'Ecn', 'status_code' => 1]],
            ],
            'no rows' => [
                ['ORG_NAME'],
                [],
                [],
            ],
        ];
    }

    /** @param list<?string> $header @param list<list<?string>> $rows @param list<array<string, string|int>> $expected */
    #[DataProvider('imports')]
    public function test_parse_returns_rows_to_insert(array $header, array $rows, array $expected): void
    {
        $result = CsvImport::parse($header, $rows, self::COLUMNS);

        $this->assertNull($result['error']);
        $this->assertSame($expected, array_values($result['rows']));
    }

    public function test_parse_keys_rows_by_file_row(): void
    {
        $result = CsvImport::parse(['ORG_NAME'], [['Ecn'], [null], ['Apc']], self::COLUMNS);

        // header = row 1, the blank line is row 3
        $this->assertSame([2, 4], array_keys($result['rows']));
    }

    /** [row, expected field count] - rows whose width differs from the header's 3 columns
     *  @return array<string, array{list<?string>, int}> */
    public static function badRows(): array
    {
        return [
            'short row' => [['Ecn', 'ecn.cz'], 2],
            'long row'  => [['Ecn', 'ecn.cz', 'x', 'extra'], 4],
        ];
    }

    /** @param list<?string> $bad */
    #[DataProvider('badRows')]
    public function test_parse_rejects_row_of_other_width_instead_of_throwing(array $bad, int $fields): void
    {
        $header = ['ORG_NAME', 'AA_HTTP_DOMAIN', 'db_pwd'];
        $rows   = [['Ecn', 'ecn.cz', 's3cret'], $bad, ['Apc', 'apc.org', 'pw']];

        $result = CsvImport::parse($header, $rows, self::COLUMNS);

        $this->assertSame(['Ecn', 'Apc'], array_column($result['rows'], 'ORG_NAME'));
        // header is row 1, so the second data row is row 3
        $this->assertSame([['row' => 3, 'fields' => $fields]], $result['rejected']);
    }

    public function test_parse_reports_ignored_header_columns(): void
    {
        $result = CsvImport::parse(['id', 'ORG_NAME', 'foo', 'status_code'], [['1', 'Ecn', 'bar', '3']], self::COLUMNS);

        $this->assertSame(['id', 'foo', 'status_code'], $result['ignored']);
        $this->assertSame([2 => ['ORG_NAME' => 'Ecn', 'status_code' => 1]], $result['rows']);
    }

    /** [header, error code, error_arg] - the file is refused, nothing imported
     *  @return array<string, array{list<?string>, string, string}> */
    public static function badHeaders(): array
    {
        return [
            'no header row'         => [['ecn.cz', 's3cret'], CsvImport::ERR_NO_HEADER, ''],
            'blank first line'      => [[null], CsvImport::ERR_BLANK_HEADER, ''],
            'semicolon separated'   => [['ORG_NAME;AA_HTTP_DOMAIN'], CsvImport::ERR_SEMICOLON, ''],
            'duplicate column'      => [['ORG_NAME', 'db_pwd', 'ORG_NAME'], CsvImport::ERR_DUPLICATE, 'ORG_NAME'],
            'duplicate in any case' => [['ORG_NAME', 'org_name '], CsvImport::ERR_DUPLICATE, 'org_name'],
            'duplicate unknown'     => [['ORG_NAME', 'note', 'Note'], CsvImport::ERR_DUPLICATE, 'Note'],
        ];
    }

    /** @param list<?string> $header */
    #[DataProvider('badHeaders')]
    public function test_parse_refuses_the_file_on_a_bad_header(array $header, string $error, string $arg): void
    {
        $result = CsvImport::parse($header, [['apc.org', 'pw', 'x']], self::COLUMNS);

        $this->assertSame($error, $result['error']);
        $this->assertSame($arg, $result['error_arg']);
        $this->assertSame([], $result['rows']);
        $this->assertSame([], $result['rejected']);
        // no cell comes back: without a header the first line is data and may hold a password
        $this->assertSame([], $result['ignored']);
    }
}
