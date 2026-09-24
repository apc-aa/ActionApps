<?php

declare(strict_types=1);

namespace AA\Central;

/** Turns an uploaded CSV of AA installs into central_conf rows (central/import_csv.php). No DB. */
final class CsvImport
{
    /** Columns never taken from the file: the auto-increment id belongs to the DB,
     *  and every imported install starts active. */
    public const NOT_IMPORTED = ['id', 'status_code'];

    /** parse() error codes - the page turns them into messages */
    public const ERR_BLANK_HEADER = 'blank_header';   // the first line is empty
    public const ERR_SEMICOLON    = 'semicolon';      // the header is one cell with ';' - wrong separator
    public const ERR_NO_HEADER    = 'no_header';      // no name matched - the first line is data, not a header
    public const ERR_DUPLICATE    = 'duplicate';      // the same column twice ('error_arg' = its name)

    /** All CSV records of the stream. A leading UTF-8 BOM (Excel) is skipped, so it cannot
     *  hide the quotes of a quoted first header name.
     *  @param resource $handle
     *  @return list<list<?string>> as fgetcsv() returns them - a blank line is [null]
     */
    public static function readRecords($handle): array
    {
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $records = [];
        // escape '' = RFC 4180: quotes are doubled, a backslash is an ordinary character (passwords may hold one)
        while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $records[] = $record;
        }
        return $records;
    }

    /** @param list<?string> $header     first CSV record - central_conf column names, any order and case
     *  @param list<list<?string>> $rows the following records
     *  @param string[] $columns         central_conf columns, AA::Metabase()->getColumnNames('central_conf')
     *  @return array{
     *      rows: array<int, array<string, string|int>>,
     *      rejected: list<array{row: int, fields: int}>,
     *      ignored: list<string>,
     *      error: string|null,
     *      error_arg: string
     *  } rows to insert (status_code = 1), keyed by file row (row 1 = header); records whose width differs from the header;
     *    header names not imported; on error (self::ERR_*) nothing is imported and no cell is returned
     *    except a duplicate header name
     */
    public static function parse(array $header, array $rows, array $columns): array
    {
        $ret = ['rows' => [], 'rejected' => [], 'ignored' => [], 'error' => null, 'error_arg' => ''];

        $canonical = [];                                       // lowercase name => column name
        foreach (array_diff($columns, self::NOT_IMPORTED) as $column) {
            $canonical[strtolower($column)] = $column;
        }
        $header = array_map(static fn(?string $name): string => trim((string) $name), $header);
        $known  = [];                                          // position => column name
        foreach ($header as $pos => $name) {
            if (isset($canonical[strtolower($name)])) {
                $known[$pos] = $canonical[strtolower($name)];
            } else {
                $ret['ignored'][] = $name;
            }
        }

        if (!$known) {
            $ret['ignored'] = [];                              // it is data, possibly a password
            $ret['error']   = match (true) {
                implode('', $header) === ''                   => self::ERR_BLANK_HEADER,
                count($header) === 1 && str_contains($header[0], ';') => self::ERR_SEMICOLON,
                default                                       => self::ERR_NO_HEADER,
            };
            return $ret;
        }
        $lower = array_filter(array_map('strtolower', $header), static fn(string $name): bool => $name !== '');   // empty names (trailing commas) may repeat
        if ($dups = array_diff_assoc($lower, array_unique($lower))) {
            $ret['ignored']   = [];
            $ret['error']     = self::ERR_DUPLICATE;
            $ret['error_arg'] = $header[array_key_first($dups)];
            return $ret;
        }

        foreach ($rows as $i => $row) {
            $data = [];
            foreach ($known as $pos => $name) {
                $data[$name] = (string) ($row[$pos] ?? '');
            }
            if (trim(implode('', $data)) === '') {
                continue;                                      // nothing to import - blank line, or only unknown columns filled
            }
            if (count($row) !== count($header)) {              // a column shifted - the values would land in wrong columns
                $ret['rejected'][] = ['row' => $i + 2, 'fields' => count($row)];
                continue;
            }
            $data['status_code'] = 1;
            $ret['rows'][$i + 2] = $data;
        }
        return $ret;
    }
}
