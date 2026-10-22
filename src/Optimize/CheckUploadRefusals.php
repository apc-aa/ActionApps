<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Api\Controller\FilesController;
use AA\IO\DB\DB_AA;
use AA\Upload\UploadPolicy;
use AA\Util\LogOnce;
use AA_Log;
use AA_Logentry;
use AA_Logentry_Unv;

/** Uploads from the rich-text editor that AA refused in the last 30 days, per slice and reason
 *  (logged once a day by FilesController): an expired login or a probe, a file type, or an
 *  upload directory PHP cannot write. Index-only. */
class CheckUploadRefusals extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Refused uploads');
    }

    public static function description(): string
    {
        return _m('Lists the uploads from the rich-text editor that AA refused in the last 30 days, per slice and reason, and the slices whose upload directory is not writable.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'security';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $rows   = LogOnce::recent(FilesController::EVENT_REFUSED, now() - self::WINDOW_SECONDS, self::GROUPS);
        $slices = [];
        foreach ($rows as $row) {
            $slice = (string) ParamExplode((string) $row['selector'])[0];
            if (is_long_id($slice)) {
                $slices[$slice] = $slice;
            }
        }
        $slice_names = $slices ? DB_AA::select(['unpackid' => 'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `module`', [['id', array_values($slices), 'l']]) : [];
        return self::findingsFor($rows, $slice_names);
    }

    /**
     * @param list<array<string, ?string>> $rows         LogOnce::recent() rows
     * @param array<string, string>        $slice_names  slice id => name
     * @return Finding[]
     */
    public static function findingsFor(array $rows, array $slice_names): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$slice_id, $reason] = array_pad(ParamExplode($selector), 2, '');
            $slice_name = (string) ($slice_names[$slice_id] ?? '') ?: $slice_id;
            $params     = AA_Logentry::factoryByString((string) $row['params']);
            [$address, $detail] = ($params instanceof AA_Logentry_Unv) ? [$params->ip, $params->detail] : ['', ''];
            $kind       = in_array($reason, ['dir', 'store'], true) ? 'dir' : $reason;
            $findings[] = new Finding(
                ($kind === 'dir') ? Finding::ERROR : Finding::WARNING,
                match ($reason) {
                    'dir'   => _m('Slice %1 cannot store uploads - its directory is not writable', [$slice_name]),
                    'store' => _m('Slice %1 could not store an upload', [$slice_name]),
                    default => _m('Uploads to slice %1 refused: %2', [$slice_name, $reason]),
                },
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Slice')     => safe($slice_name),
                    _m('Reason')    => safe($reason),
                    _m('Address')   => safe($address),
                    _m('Detail')    => safe($detail),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: match ($kind) {
                    'dir'    => _m('PHP cannot write the upload directory of the slice (IMG_UPLOAD_PATH/<slice id>, or its file manager directory). Fix the owner or the permissions on the server.'),
                    'rate'   => _m('An address uploaded more anonymous images in an hour than AA allows (%1 an address, %2 a slice). A robot, most likely - the address is in the log.', [FilesController::ANON_PER_IP_HOUR, FilesController::ANON_PER_SLICE_HOUR]),
                    'rights' => _m('Somebody tried to upload without the right to: an editor whose login expired (reloading the page helps), or a probe - many days from one address.'),
                    'size'   => _m('Anonymous visitors may upload images up to %1 MB; editors are limited by upload_max_filesize and post_max_size of PHP.', [UploadPolicy::ANON_MAX_BYTES >> 20]),
                    default  => _m('The editor refused a file type, or a file whose content did not match its extension. The allowed types are fixed in AA\Upload\UploadPolicy; nothing a browser or PHP would run ever passes.'),
                },
                links: [_m('Log rows') => AA_Log::getAdminUrl(FilesController::EVENT_REFUSED, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No refused uploads in the last 30 days'))];
    }
}
