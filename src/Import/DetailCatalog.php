<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\SourceFailure;

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

    /** @param \Closure(): ?Catalog $catalog the catalog record of the page, read once for all its parts */
    public function __construct(private readonly Definition $def, private readonly string $uid, private readonly \Closure $catalog) {}

    /** @param mixed $page the page of the listing (?catalog_page=), from the request; a link to a page
     *                     opens the section, which is closed otherwise - the catalog may be long */
    public function html(mixed $page = null): string
    {
        $class   = Registry::sources()[$this->def->sourceType()] ?? null;
        $catalog = ($this->catalog)();
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
        $adopts = ($class !== null) && is_subclass_of($class, CatalogAdoptable::class);
        // a source with keys is the superadmin's, as is every change of its import; the right to the
        // slice the source adds to is checked on the click - the page would have to run the source to know it
        $why    = ($this->def->manageRefusal($this->uid) === null) ? '' : self::reasonText('KEYS');
        $diff   = $catalog->diff($streams);
        $filled = $catalog->filledAt();
        $html   = '<p>' . (($filled === null)
            ? _m('The catalog is incomplete: the last check did not list all of it (%1 entries so far). Nothing is reported missing at the source until a check lists it whole.', [$count])
            : _m('Listed by the check of %1: %2 entries.', [date('Y-m-d H:i', $filled), $count])) . '</p>';
        $html  .= $this->diffHtml($catalog, $diff, $streams);
        $pages  = DetailPage::pagesOf($count);
        $shown  = DetailPage::pageOf($page, $pages);
        $html  .= $this->listingHtml($catalog->entriesAt(($shown - 1) * DetailPage::PAGE_SIZE, DetailPage::PAGE_SIZE), $streams, $adopts, $why, $shown);
        $html  .= DetailPage::pagerHtml($this->def, 'catalog_page', self::ANCHOR, _m('Pages of the catalog'), $shown, $pages);
        $counts = [$adopts ? _m('%1 entries, %2 can be added', [$count, ($why === '') ? $catalog->addable($diff['new']) : 0]) : _m('%1 entries', [$count])];
        // what changed at the source is worth seeing without opening the section
        if ($diff['missing']) {
            $counts[] = _m('%1 missing at the source', [count($diff['missing'])]);
        }
        if ($diff['renamed']) {
            $counts[] = _m('%1 renamed at the source', [count($diff['renamed'])]);
        }
        $open = ($page !== null) || $diff['missing'] || $diff['renamed'];
        return self::section($html, ' <span class="aa-import-count">' . implode(', ', $counts) . '</span>', $open);
    }

    // ------------------------------------------------------------ actions

    /** @return array{0: string, 1: string, 2?: string} an outcome of DetailPage::handle() */
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
            // the third element only for a failure of the source - a refusal of the settings has no detail here
            default    => ['adopt_refused', $r->code, ...(SourceFailure::isDetail($r->detail) && ($r->detail !== '') ? [$r->detail] : [])],
        };
    }

    /** what adopt() did, by its outcome
     *  @param string $detail of a failure of the source, as SourceFailure::$detail */
    public static function outcomeHtml(string $code, string $reason, string $detail = ''): string
    {
        return match ($code) {
            'adopted'  => MsgOk(_m('The stream is added or updated from the catalog. The streams of the import show it after the next check of the source.')),
            'no_entry' => MsgErr(_m('The catalog has no such entry. Check the source to list its catalog again.')),
            'adopt_busy' => MsgErr(_m('Nothing was added from the catalog:') . ' ' . safe(DetailPage::busyText($reason))),
            default    => MsgErr(_m('Nothing was added from the catalog:') . ' ' . safe((($detail !== '') && in_array($reason, ['SOURCE', 'TRANSPORT'], true)) ? DetailPage::failureText($reason, $detail) : trim(self::reasonText($reason) . ' ' . RunView::detailText($detail)))),
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
     *  @param array{new: list<string>, missing: list<string>, renamed: list<string>} $diff of Catalog::diff($streams)
     *  @param list<Stream> $streams */
    private function diffHtml(Catalog $catalog, array $diff, array $streams): string
    {
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

    /** One page of the entries with the streams that come from each; when the source adopts entries,
     *  one form with an Add or Update button per entry - off for an entry the source said it would
     *  refuse, with the reason in its title. A reason all the disabled entries share is said once above
     *  the table, a different one beside its entry.
     *  @param list<CatalogEntry> $entries  @param list<Stream> $streams  @param string $why off for all entries */
    private function listingHtml(array $entries, array $streams, bool $adopts, string $why, int $page): string
    {
        if (!$entries) {
            return '';
        }
        $from = [];
        foreach ($streams as $stream) {
            $from[$stream->catalogKey][] = $stream->key;
        }
        // why the button of each entry is off; '' = it is on
        $offWhy   = array_map(static fn(CatalogEntry $e): string => ($why !== '') ? $why : $e->refusal, $entries);
        $off      = array_filter($offWhy, static fn(string $reason): bool => $reason !== '');
        $reasons  = array_unique(array_map(static fn(CatalogEntry $e): string => $e->refusal, array_intersect_key($entries, $off)));
        $common   = ((count($off) > 1) && (count($reasons) === 1)) ? (string) reset($reasons) : '';
        $html     = '';
        if ($adopts) {
            $html .= ($why === '') ? '' : '<p class="aa-import-why">' . safe($why) . '</p>';
            $html .= ($common === '') ? '' : '<p class="aa-import-why">' . _m('%1 entries on this page cannot be added or updated: %2', [count($off), safe($common)]) . '</p>';
        }
        $html    .= self::filterHtml($adopts && ($why === ''));
        $heads    = [_m('Key'), _m('Name'), _m('Updated'), _m('Details'), _m('Stream')] + ($adopts ? [5 => _m('Action')] : []);
        $labels   = array_map(static fn(string $head): string => ' data-label="' . safe($head) . '"', $heads);
        $table    = '<table id="aa-import-catalog-list" class="aa-import-catalog"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>';
        foreach ($entries as $i => $e) {
            $keys     = $from[$e->key] ?? [];
            $disabled = isset($off[$i]);
            $details  = implode('; ', array_map(static fn(string $name, string $value): string => safe($name) . ': ' . safe($value), array_map('strval', array_keys($e->meta)), array_map('strval', $e->meta)));
            $table   .= '<tr' . ($adopts ? ' data-addable="' . ((!$disabled && !$keys) ? 'yes' : 'no') . '"' : '') . '>'
                . '<td' . $labels[0] . '>' . safe($e->key) . '</td><td' . $labels[1] . '>' . safe($e->name) . '</td><td' . $labels[2] . '>' . safe($e->updated) . '</td><td' . $labels[3] . '>' . $details . '</td>'
                . '<td' . $labels[4] . '>' . ($keys ? safe(implode(', ', $keys)) : _m('new')) . '</td>'
                . ($adopts ? '<td' . $labels[5] . '>' . DetailPage::button($keys ? _m('Update') : _m('Add'), $offWhy[$i], ['name' => 'key', 'value' => $e->key])
                    . (($e->refusal === $common) ? '' : DetailPage::whyHtml($e->refusal)) . '</td>' : '')
                . '</tr>';
        }
        $table .= '</tbody></table>';
        if (!$adopts) {
            return $html . $table;
        }
        return $html . '<form method="post" action="' . safe(DetailPage::urlOf($this->def)) . '" class="aa-import-adopt">'
            . DetailPage::tokenFields($this->id(), 'adopt')
            . (($page > 1) ? DetailPage::hiddenInput('catalog_page', (string) $page) : '')
            . $table . '</form>';
    }

    /** The filter of the listed page, for javascript/dataimport.js: hidden until the script shows it, and
     *  outside the form - Enter in it would send the form with its first button.
     *  @param bool $addable whether the rows say which of them can be added */
    private static function filterHtml(bool $addable): string
    {
        return '<div class="aa-import-filter" data-aa-filter="aa-import-catalog-list" data-aa-shown="' . safe(_m('%1 of %2 entries on this page')) . '" hidden>'
            . '<label>' . _m('Filter this page') . ' <input' . htmlattr(['type' => 'search', 'autocomplete' => 'off']) . '></label>'
            . ($addable ? '<label><input type="checkbox"> ' . _m('Only entries that can be added') . '</label>' : '')
            . '<output aria-live="polite"></output></div>';
    }

    /** @param string $counts HTML after the title */
    private static function section(string $body, string $counts = '', bool $open = true): string
    {
        return DetailPage::section('catalog', _m('Catalog of the source') . $counts, $body, false, $open);
    }

    private function id(): string
    {
        return (string) $this->def->getId();
    }
}
