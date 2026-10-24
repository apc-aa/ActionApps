<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Isds\Accounts;
use AA\Isds\Binding;
use AA\Isds\Body\HtmlBody;
use AA\Isds\Body\PdfBody;
use AA\Isds\DatovkaConf;
use AA\Isds\File;
use Dompdf\Dompdf;

/**
 * Makes a short fixed letter into a PDF the way a mass sending to data boxes does (HtmlBody, PdfBody) - only
 * where an ISDS account sends for a site of this database, or one of no site read for a slice of it, as
 * CheckIsdsAccounts deals with them. One real letter tells more than a list of its conditions: dompdf and its
 * fonts left in the tree, GD for the images, a temporary directory PHP may write to. A failure names none of
 * them - what dompdf says may name paths of the server. It runs within the memory_limit of the process, which a
 * sending raises for its letter instead, and needs some 20 MB of it free.
 */
class CheckIsdsPdf extends AbstractOptimize
{
    /**
     * Czech letters, a table, <pre> in the other font and an image: what each sending letter may hold; in the frame
     * of an e-mail wider than the page, which the PDF makes blocks of and narrower
     */
    private const LETTERHEAD = "Obec Zkušební\nNáměstí 1, 123 45 Zkušebná";
    private const BODY = '<table width="700"><tr><td><p>Příliš žluťoučký kůň úpěl ďábelské ódy.</p>'
        . '<table border="1"><tr><td width="200">Pondělí</td><td>9:00</td></tr></table>'
        . '<pre>Čj. 42/2026</pre><p><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFUlEQVQI12NkiFrAgA0wMeAAg1MCAMfEAQqHEKHXAAAAAElFTkSuQmCC" alt=""></p>'
        . '</td></tr></table>';

    public static function name(): string
    {
        return _m('Letters to data boxes as a PDF');
    }

    public static function description(): string
    {
        return _m('Makes a short letter into a PDF, as a mass sending to data boxes does with the letter of each campaign - only when an ISDS account of misc/datovka/datovka_conf.php sends for a site of this database.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'mail';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $bindings = Accounts::bindings($this->conf());
        $here     = $this->modulesHere(Accounts::moduleIds($bindings));
        // one of no site read is in every database: only the one of its slices tries it
        $sending = array_filter(Accounts::here($bindings, $here), static fn(Binding $b): bool => ($b->sites !== []) || (array_intersect(array_filter([$b->campaignSlice, $b->messageSlice]), $here) !== []));
        if (!$sending) {
            return [];
        }
        try {
            $html = new HtmlBody();
            $pdf  = $this->render($html->document('Zkouška', $this->body(), true, self::LETTERHEAD, PdfBody::CSS));
            // without its fonts dompdf falls back to Helvetica, which has no Czech; without GD the image is left out
            preg_match_all('~/BaseFont /(?:[A-Z]{6}\+)?(DejaVuSans(?:Mono)?)\b~', $pdf->content, $fonts);
            $made = !$html->omitted() && str_starts_with($pdf->content, '%PDF-') && (count(array_unique($fonts[1])) === 2)
                && str_contains($pdf->content, '/Subtype /Image');
        } catch (\Throwable) {
            $made = false;
        }
        if ($made) {
            return [new Finding(Finding::OK, _m('AA makes letters to data boxes into a PDF'))];
        }

        return [new Finding(
            Finding::ERROR,
            _m('A letter to a data box cannot be made into a PDF'),
            advice: _m('Until then no campaign can be sent to data boxes.') . ' ' . match ($this->missing()) {
                'dompdf' => _m('dompdf, which makes the PDF, is missing: run composer install in the directory of AA.'),
                'gd'     => _m('PHP lacks the GD extension, which draws the images of a letter: install it and restart PHP.'),
                default  => _m('Run composer install in the directory of AA - it brings dompdf and its fonts - and check that PHP may write to its temporary directory (sys_temp_dir).'),
            },
        )];
    }

    // ------------------------------------------------------------ what tests replace

    protected function conf(): DatovkaConf
    {
        return DatovkaConf::load();
    }

    /**
     * @param list<string> $ids of sites or slices
     * @return list<string> those that are modules of this database
     */
    protected function modulesHere(array $ids): array
    {
        return Accounts::modulesHere($ids);
    }

    protected function body(): string
    {
        return self::BODY;
    }

    protected function render(string $document): File
    {
        return (new PdfBody())->render($document);
    }

    /** @return string what the server lacks for certain: 'dompdf', 'gd', '' = neither */
    protected function missing(): string
    {
        return match (true) {
            !class_exists(Dompdf::class) => 'dompdf',
            !extension_loaded('gd')      => 'gd',
            default                      => '',
        };
    }
}
