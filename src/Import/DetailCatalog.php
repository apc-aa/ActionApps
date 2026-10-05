<?php

declare(strict_types=1);

namespace AA\Import;

/**
 * The catalog of the source in the import detail: what the last check listed, what of it is new,
 * missing or renamed against the streams the source had then, page by page, and Add/Update of an
 * entry for a source that adopts entries. The page shows what the check stored and never asks the
 * source - only Add/Update runs its code. The section is not refreshed while a run goes on: the
 * catalog may be megabytes of JSON.
 */
final class DetailCatalog
{
    /** where the section starts, for a link back to it */
    public const ANCHOR = '#aa-import-catalog';

    public function __construct(private readonly Definition $def, private readonly string $uid) {}

    /** @param mixed $page the page of the listing (?catalog_page=), from the request */
    public function html(mixed $page = null): string
    {
        $class   = Registry::sources()[$this->def->sourceType()] ?? null;
        $catalog = Catalog::forDefinition($this->id());
        if ($catalog === null) {
            // a source gone may have left its catalog; one without a catalog has nothing to show
            return (($class !== null) && is_subclass_of($class, Cataloged::class))
                ? self::section('<p class="aa-empty-note">' . _m('No catalog yet. Check source lists what the source offers.') . '</p>')
                : '';
        }
        $count   = $catalog->count();
        $streams = $catalog->streams();
        if ($catalog->damaged()) {
            return self::section('<div class="aa-err">' . _m('The stored catalog cannot be read. The next check of the source lists it again.') . '</div>');
        }
        $filled = $catalog->filledAt();
        $html   = '<p>' . (($filled === null)
            ? _m('The catalog is incomplete: the last check did not list all of it (%1 entries so far). Nothing is reported missing at the source until a check lists it whole.', [$count])
            : _m('Listed by the check of %1: %2 entries.', [date('Y-m-d H:i', $filled), $count])) . '</p>';
        $html   .= $this->diffHtml($catalog, $streams);
        $pages   = DetailPage::pagesOf($count);
        $page    = DetailPage::pageOf($page, $pages);
        $html   .= $this->listingHtml($catalog->entriesAt(($page - 1) * DetailPage::PAGE_SIZE, DetailPage::PAGE_SIZE), $streams, ($class !== null) && is_subclass_of($class, CatalogAdoptable::class), $page);
        $html   .= DetailPage::pagerHtml($this->def, 'catalog_page', self::ANCHOR, _m('Pages of the catalog'), $page, $pages);
        return self::section($html);
    }

    // ------------------------------------------------------------ actions

    /** @return array{string, string} an outcome of DetailPage::handle() */
    public function adopt(mixed $key): array
    {
        if (!is_string($key) || ($key === '')) {
            return ['no_entry', ''];
        }
        $r = Service::adopt($this->def, $key, $this->uid);
        return match ($r->code) {
            'CHANGED'  => ['adopted', ''],
            'NO_ENTRY' => ['no_entry', ''],
            'BUSY'     => ['adopt_busy', $r->detail],
            default    => ['adopt_refused', $r->code],
        };
    }

    /** what adopt() did, by its outcome */
    public static function outcomeHtml(string $code, string $reason): string
    {
        return match ($code) {
            'adopted'  => MsgOk(_m('The stream is added or updated from the catalog. The streams of the import show it after the next check of the source.')),
            'no_entry' => MsgErr(_m('The catalog has no such entry. Check the source to list its catalog again.')),
            'adopt_busy' => MsgErr(_m('Nothing was added from the catalog:') . ' ' . safe(DetailPage::busyText($reason))),
            default    => MsgErr(_m('Nothing was added from the catalog:') . ' ' . safe(self::reasonText($reason))),
        };
    }

    /** why adopt() was refused, by its code - plain text */
    public static function reasonText(string $code): string
    {
        return match ($code) {
            'KEYS'          => _m('This import uses API keys, so only a superadmin may add streams from its catalog.'),
            'ADOPT_SLICE'   => _m('Adding from the catalog needs the permission to edit all items of the slice the source adds its streams to.'),
            'NOT_ADOPTABLE' => _m('The import source cannot add streams from its catalog.'),
            'TRANSPORT'     => _m('The import source could not be reached or did not answer. Try again later.'),
            default         => DetailPage::reasonText($code),
        };
    }

    // ------------------------------------------------------------ parts

    /** the streams whose entry is gone or renamed; how many entries are new
     *  @param list<Stream> $streams */
    private function diffHtml(Catalog $catalog, array $streams): string
    {
        $diff  = $catalog->diff($streams);
        $new   = count($diff['new']);
        $html  = match (true) {
            $new === 1 => '<p>' . _m('1 entry is new at the source - no stream comes from it.') . '</p>',
            $new > 1   => '<p>' . _m('%1 entries are new at the source - no stream comes from them.', [$new]) . '</p>',
            default    => '',
        };
        $byKey = [];
        foreach ($streams as $stream) {
            $byKey[$stream->key] = $stream;
        }
        $rows = '';
        foreach (['missing' => _m('Missing at the source'), 'renamed' => _m('Renamed at the source')] as $kind => $label) {
            foreach ($diff[$kind] as $key) {
                $stream = $byKey[$key] ?? null;
                if ($stream === null) {
                    continue;
                }
                $name  = ($kind === 'renamed') ? safe((string) $catalog->entry($stream->catalogKey)?->name) : '--';
                $rows .= '<tr><td>' . $label . '</td><td>' . safe($stream->key) . '</td><td>' . safe($stream->catalogKey) . '</td><td>' . safe($stream->label) . '</td><td>' . $name . '</td></tr>';
            }
        }
        if ($rows === '') {
            return $html;
        }
        $heads = [_m('Change'), _m('Stream'), _m('Catalog key'), _m('Name of the stream'), _m('Name in the catalog')];
        return $html . '<table class="aa-import-diff"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /** One page of the entries with the streams that come from each; Add or Update when the source
     *  adopts entries.
     *  @param list<CatalogEntry> $entries  @param list<Stream> $streams */
    private function listingHtml(array $entries, array $streams, bool $adopts, int $page): string
    {
        if (!$entries) {
            return '';
        }
        $from = [];
        foreach ($streams as $stream) {
            $from[$stream->catalogKey][] = $stream->key;
        }
        // a source with keys is the superadmin's, as is every change of its import; the right to the
        // slice the source adds to is checked on the click - the page would have to run the source to know it
        $why   = ($this->def->manageRefusal($this->uid) === null) ? '' : self::reasonText('KEYS');
        $heads = [_m('Key'), _m('Name'), _m('Updated'), _m('Details'), _m('Stream')];
        $html  = ($adopts && ($why !== '')) ? '<p class="aa-import-why">' . safe($why) . '</p>' : '';
        $html .= '<table class="aa-import-catalog"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th>' . ($adopts ? '<th scope="col">' . _m('Action') . '</th>' : '') . '</tr></thead><tbody>';
        foreach ($entries as $e) {
            $keys    = $from[$e->key] ?? [];
            $details = implode('; ', array_map(static fn(string $name, string $value): string => safe($name) . ': ' . safe($value), array_map('strval', array_keys($e->meta)), array_map('strval', $e->meta)));
            $html   .= '<tr><td>' . safe($e->key) . '</td><td>' . safe($e->name) . '</td><td>' . safe($e->updated) . '</td><td>' . $details . '</td>'
                . '<td>' . ($keys ? safe(implode(', ', $keys)) : _m('new')) . '</td>'
                . ($adopts ? '<td>' . $this->adoptFormHtml($e->key, $keys ? _m('Update') : _m('Add'), $why, $page) . '</td>' : '')
                . '</tr>';
        }
        return $html . '</tbody></table>';
    }

    private function adoptFormHtml(string $key, string $label, string $why, int $page): string
    {
        return '<form method="post" action="' . safe(DetailPage::urlOf($this->def)) . '" class="aa-import-adopt">'
            . DetailPage::tokenFields($this->id(), 'adopt')
            . DetailPage::hiddenInput('key', $key)
            . (($page > 1) ? DetailPage::hiddenInput('catalog_page', (string) $page) : '')
            . DetailPage::button($label, $why, [])
            . '</form>';
    }

    private static function section(string $body): string
    {
        return DetailPage::section('catalog', _m('Catalog of the source'), $body, false);
    }

    private function id(): string
    {
        return (string) $this->def->getId();
    }
}
