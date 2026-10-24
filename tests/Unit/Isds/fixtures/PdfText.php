<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

/**
 * What a reader sees of a PDF of dompdf, without pdftotext: its text and where its images lie. Text is
 * the literal strings of TJ, UTF-16BE (Identity-H, a CID is the code point); what dompdf drew off the
 * A4 page - below its bottom edge, past its right one - is left out, as no reader shows it.
 */
final class PdfText
{
    private const WIDTH  = 595.28;
    private const HEIGHT = 841.89;

    /** one line of text each TJ */
    public static function of(string $pdf): string
    {
        return implode('', array_map(static fn(array $shown): string => $shown[0] . "\n", self::shown($pdf)));
    }

    /** @return list<array{string, float, float}> the text of each TJ on the page, x and y (from the bottom) of its baseline */
    public static function shown(string $pdf): array
    {
        $text = [];
        foreach (self::streams($pdf) as $stream) {
            preg_match_all('~BT ([0-9.-]+) ([0-9.-]+) Td [^\[]*\[\((.*?)(?<!\\\\)\)\] TJ~s', $stream, $shown, PREG_SET_ORDER);
            foreach ($shown as [, $x, $y, $literal]) {
                if (((float) $x >= 0) && ((float) $x <= self::WIDTH) && ((float) $y >= 0) && ((float) $y <= self::HEIGHT)) {
                    $text[] = [mb_convert_encoding(self::unescape($literal), 'UTF-8', 'UTF-16BE'), (float) $x, (float) $y];
                }
            }
        }
        return $text;
    }

    /** @return list<array{float, float, float, float}> width, height, x and y (from the bottom) of each image drawn */
    public static function images(string $pdf): array
    {
        $images = [];
        foreach (self::streams($pdf) as $stream) {
            preg_match_all('~([0-9.-]+) 0 0 ([0-9.-]+) ([0-9.-]+) ([0-9.-]+) cm /I\w+ Do~', $stream, $drawn, PREG_SET_ORDER);
            foreach ($drawn as [, $w, $h, $x, $y]) {
                $images[] = [(float) $w, (float) $h, (float) $x, (float) $y];
            }
        }
        return $images;
    }

    /** @return list<string> the content streams, inflated */
    private static function streams(string $pdf): array
    {
        preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $pdf, $m);
        $streams = [];
        foreach ($m[1] as $stream) {
            $inflated = @gzuncompress($stream);
            if (is_string($inflated)) {
                $streams[] = $inflated;
            }
        }
        return $streams;
    }

    private static function unescape(string $literal): string
    {
        return (string) preg_replace_callback('~\\\\([nrtbf()\\\\]|[0-7]{1,3})~', static fn(array $e): string => match ($e[1]) {
            'n'     => "\n",
            'r'     => "\r",
            't'     => "\t",
            'b'     => "\x08",
            'f'     => "\f",
            '(', ')', '\\' => $e[1],
            default => chr((int) octdec($e[1])),
        }, $literal);
    }
}
