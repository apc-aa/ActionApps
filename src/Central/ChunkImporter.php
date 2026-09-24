<?php

declare(strict_types=1);

namespace AA\Central;

use AA;

/** Imports a chunk of a module definition (AA_Definition_Module_Chunk) into this AA - Central
 *  Copy Slice (responder Do_Import_Module_Chunk, or the local import). The chunk travels between
 *  AAs and keeps its name and properties; its methods delegate here. */
final class ChunkImporter
{
    /** @param array $chunk_data  table => rows
     *  @return list<string> report lines ('OK: ...', 'Err: ...') */
    public static function import($chunk_data): array
    {
        $metabase    = AA::Metabase();

        // a table gets into ok/err with its first row there - no "Err: 0 field ()" for a clean import
        $msgs = ['ok' => [], 'err' => []];

        foreach ($chunk_data as $table => $records) {
            if (empty($records)) {
                continue;
            }

            $keys = $metabase->getKeys($table);   // just for reporting
            // now we packing the data in the AA_Metabase::do*() functions
            // $metabase->packIds($table, $records);    // pack ids ...
            foreach ($records as $data) {
                $ident = [];
                foreach ($keys as $col) {
                    $ident[] = $data[$col];
                }

                if ($res = $metabase->doInsert($table, $data, 'nohalt')) {
                    $msgs['ok'][$table][] = $ident;
                } else {
                    $msgs['err'][$table][] = $ident;
                }

            }
        }
        $ret = [];
        if ($msg = self::formatReport($msgs['ok'])) {
            $ret[] = 'OK: ' . $msg;
        }
        if ($msg = self::formatReport($msgs['err'])) {
            $ret[] = 'Err: ' . $msg;
        }
        return $ret;
    }

    /** "2 field ([a][b]); " - rows per table by their keys */
    public static function formatReport($arr): string
    {
        $ret = '';
        foreach ($arr as $table => $idents) {
            $ret .= count($idents) . " $table (";
            foreach ($idents as $ident) {
                $ret .= '[' . implode(',', $ident) . ']';
            }
            $ret .= '); ';
        }
        return $ret;
    }
}
