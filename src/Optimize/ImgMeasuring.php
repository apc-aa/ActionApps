<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\Adminlink;
use AA\Util\PhpThumbParams;

/** {img} prints the size of the resized image for most of its <info> values ('img', 'imgb',
 *  'picture', 'width', 'height', 'hw', 'html', 'imgtype', 'mime'). Unless the phpThumb
 *  parameters alone tell that size (PhpThumbParams::knownSize()), it measures the image on
 *  every expansion: a request from the server to itself, which also renders the thumbnail -
 *  70-110 ms per image, seconds for a listing. This lists the {img} calls in the templates
 *  which do that. The standard use - the url with a size following from the parameters -
 *  never reads the image at all.
 */
class ImgMeasuring extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('{img} which measures the image on every page view');
    }

    public static function description(): string
    {
        return _m('Lists the {img} expressions in views, fields, site spots and other code which print the image size, but whose parameters do not tell it - so the resized image is loaded over HTTP on every expansion.');
    }

    /** a LIKE scan over every code column (CodePoints) - not index-only, so not a basic check */
    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'cache';
    }

    /** rewriting a template is its author's call - no repair */
    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $evidence = [];
        foreach (CodePoints::containing('{img:') as $row) {
            foreach (self::measuringCalls($row['txt']) as [$expression, $reason]) {
                $evidence[] = [
                    _m('Where')      => Adminlink::linkByTable($row['table'], $row['column'], $row) ?: safe("$row[table].$row[column] $row[iid]"),
                    _m('Expression') => '<code>' . safe($expression) . '</code>',
                    _m('Why')        => safe($reason),
                ];
            }
        }
        if (!$evidence) {
            return [new Finding(Finding::OK, _m('No {img} in the templates measures its image'))];
        }
        return [new Finding(
            Finding::INFO,
            _m('%1 {img} expressions measure the image on every page view', [count($evidence)]),
            count: count($evidence),
            evidence: $evidence,
            advice: _m('Give both w and h together with zc, iar or far, and leave out parameters which make the size depend on the photo (ar, ra, wp, hp, wl, hl, ws, hs, sx, sy, sw, sh, fltr) - then the size follows from the parameters. Or print just the url (info "url" or "im") and set the size in the HTML or CSS.'),
        )];
    }

    /** The {img} calls in $code which measure the image, with the reason.
     *  @return list<array{0: string, 1: string}> [expression, reason] */
    public static function measuringCalls(string $code): array
    {
        $found = [];
        foreach (self::calls($code) as [$expression, $args]) {
            $params = PhpThumbParams::clean(trim($args[1] ?? ''));   // what {img} reads: trimmed, line breaks inside dropped
            $info   = trim($args[2] ?? '');
            if (isset(PhpThumbParams::URL_INFOS[$info]) || self::isComputed($info)) {
                continue;   // prints the url only - or cannot be told, so better not guessed
            }
            $reason = match (true) {
                ($info === 'imgtype') || ($info === 'mime')      => _m('"%1" needs the output format, which only the image tells', [$info]),
                self::isComputed($params)                         => _m('the parameters are computed - check that every variant tells the size'),
                PhpThumbParams::knownSize($info, $params) === null => _m('the parameters do not tell the size'),
                default                                           => '',
            };
            if ($reason !== '') {
                $found[] = [$expression, $reason];
            }
        }
        return $found;
    }

    /** Every {img:...} in $code with its arguments, split the way the expansion splits them:
     *  on ":" outside nested {...}, except "#:" and the colon of "http://" / "https://". The
     *  command name in any case, {# comments} dropped - the expansion does both. A nested
     *  {img:...} inside the arguments is listed on its own, too.
     *  @return list<array{0: string, 1: list<string>}> [expression, arguments] */
    public static function calls(string $code): array
    {
        $calls  = [];
        $code   = (string) preg_replace('~\{#[^{}]*\}~', '', $code);
        $length = strlen($code);
        $offset = 0;
        while (($start = stripos($code, '{img:', $offset)) !== false) {
            $offset = $start + 5;
            $args   = [];
            $arg    = '';
            $depth  = 0;
            for ($i = $offset; $i < $length; $i++) {
                $char = $code[$i];
                if ($char === '}' && $depth === 0) {
                    $args[]  = $arg;
                    $calls[] = [substr($code, $start, $i - $start + 1), $args];
                    continue 2;
                }
                if ($char === ':' && $depth === 0 && !self::isLiteralColon($code, $i)) {
                    $args[] = $arg;
                    $arg    = '';
                    continue;
                }
                $depth += ($char === '{') ? 1 : (($char === '}') ? -1 : 0);
                $arg   .= $char;
            }
            // unclosed: the expansion leaves this one as text, but still expands every later one
        }
        return $calls;
    }

    /** "#:" is an escaped colon; "http://" and "https://" keep theirs (ParamExplode()) */
    private static function isLiteralColon(string $code, int $i): bool
    {
        return (($i > 0) && ($code[$i - 1] === '#'))
            || ((substr($code, $i + 1, 2) === '//') && (substr($code, $i - 2, 2) === 'tp' || substr($code, $i - 3, 3) === 'tps'));
    }

    /** an argument the expansion fills in only at render time */
    private static function isComputed(string $arg): bool
    {
        return str_contains($arg, '{') || str_contains($arg, '_#');
    }
}
