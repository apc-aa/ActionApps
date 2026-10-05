<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Import\Credential\Store;
use AA\IO\SourceRefused;
use AA\Later\BatchFailed;
use AA\Widget\Widget;
use Throwable;

/**
 * The part of the import detail that changes what runs are made of: the settings of the source and
 * the source itself, the keys the source needs, and the mapping - read only, with its reset from the
 * source. No run changes these sections, so the script of the page leaves them alone and a half-filled
 * form stays. Every change goes through Service and ends the open run when it changes anything.
 */
final class DetailConfig
{
    public function __construct(private readonly Definition $def, private readonly string $uid) {}

    /** @param mixed $chosen the source the user picked to switch to (?source=), from the request */
    public function html(mixed $chosen = null): string
    {
        return $this->settingsHtml($chosen) . $this->mappingHtml();
    }

    // ------------------------------------------------------------ actions

    /** @return array{string, string} an outcome of DetailPage::handle() */
    public function resetMapping(): array
    {
        $r = Service::resetMapping($this->def, $this->uid);
        return $r->done() ? ['mapping_reset', ''] : ['mapping_refused', self::reason($r)];
    }

    /**
     * The settings form: the source - another one only when the user chose it - with the settings
     * the form posts that this source declares. The form carries Service::settingsHash() of what it
     * was made from, so it cannot undo a change saved meanwhile.
     * @param array<mixed> $post
     * @return array{string, string} an outcome of DetailPage::handle()
     */
    public function changeSettings(array $post): array
    {
        $source = $post['source'] ?? null;
        if (!is_string($source) || ($source === '')) {
            return ['no_source', ''];
        }
        $stored = $post['stored'] ?? null;
        if (!is_string($stored)) {
            return ['settings_stale', ''];
        }
        try {
            $r = Service::changeSettings($this->def, $source, $this->postedSettings($source, $post), $this->uid, $stored);
        } catch (BatchFailed $e) {
            // the open run cannot be ended by this user
            $r = new ChangeResult($e->reason);
        }
        return match ($r->code) {
            'CHANGED' => ['settings_saved', ''],
            'SAME'    => ['settings_same', ''],
            'STALE'   => ['settings_stale', ''],
            default   => ['settings_refused', self::reason($r)],
        };
    }

    /**
     * What the widgets of the form posted (ObjectForm's format) for each setting source $source
     * declares and the page lets edit. A setting the page only shows keeps its stored value; one
     * posted with more values is passed on as a list, which Service refuses - nothing is cut off.
     * Service validates the rest.
     * @param array<mixed> $post
     * @return array<string, mixed>
     */
    private function postedSettings(string $source, array $post): array
    {
        $class = Registry::sources()[$source] ?? null;
        if ($class === null) {
            return [];
        }
        $fields = $post['aa']['u' . $this->id()] ?? null;
        $fields = is_array($fields) ? $fields : [];
        try {
            $declared = $class::getClassProperties();
        } catch (Throwable) {
            // Service asks again, logs it and says why
            return [];
        }
        $stored   = ($source === $this->def->sourceType()) ? $this->storedSettings() : [];
        $settings = [];
        foreach ($declared as $name => $property) {
            if (!self::isEditable($property)) {
                if (isset($stored[$name])) {
                    $settings[(string) $name] = $stored[$name];
                }
                continue;
            }
            $key = rawurlencode(\AA_Fields::getVarFromFieldId((string) $property->getId()));
            if (isset($fields[$key])) {
                $values = Widget::getValue((array) $fields[$key])->getValues();
                $settings[(string) $name] = (count($values) > 1) ? array_values($values) : ($values ? reset($values) : '');
            }
        }
        return $settings;
    }

    /** Text, int, bool and float settings are edited in the form; read-only, hidden and object ones
     *  are shown as text - their widgets would print the value unescaped or post it back as typed. */
    private static function isEditable(\AA_Property $property): bool
    {
        return !$property->isObject() && !in_array($property->getType(), ['readonly', 'hidden'], true);
    }

    /** a refusal for the session: its code and detail */
    private static function reason(ChangeResult $r): string
    {
        return $r->code . ':' . $r->detail;
    }

    /** What reset_mapping and settings did, by DetailPage::handle()'s outcome; '' for any other outcome.
     *  A refusal comes as "CODE:detail" (reason()). */
    public static function outcomeHtml(string $code, string $reason): string
    {
        [$refusal, $detail] = explode(':', $reason, 2) + ['', ''];
        return match ($code) {
            'mapping_reset'    => MsgOk(_m('The mapping is set again from the source. An open run goes on with the mapping it started with.')),
            'mapping_refused'  => MsgErr(_m('The mapping was not reset:') . ' ' . safe(Definition::refusal($refusal, $detail)) . self::settingsPointer($refusal, false, $detail)),
            'settings_saved'   => MsgOk(_m('The settings are saved and the mapping is set again from the source. If a run was open, it is ended, and what the import knew of the streams is dropped.')),
            'settings_same'    => MsgOk(_m('The settings are the same as stored - nothing changed.')),
            'settings_refused' => MsgErr(_m('The settings were not saved:') . ' ' . safe(Definition::refusal($refusal, $detail))),
            'settings_stale'   => MsgErr(safe(Definition::refusal('STALE'))),
            'no_source'        => MsgErr(_m('Choose a source.')),
            default            => '',
        };
    }

    /** Where to fix what refused an action, for the refusals the settings or the source cause; ''
     *  for others. $start: a start or Continue by hand - a planned start does not check the settings
     *  for its user, so it goes on.
     *  @param string $detail of the refusal - a mapping without a key gets it from the settings */
    public static function settingsPointer(string $code, bool $start, string $detail = ''): string
    {
        $link   = '<a href="#aa-import-settings">' . _m('Settings of the source') . '</a>';
        $no_key = ($code === 'MAPPING') && in_array('NO_KEY', Mapping::splitProblems($detail), true);
        return match (true) {
            $start && in_array($code, ['SETTINGS', 'SLICE'], true)
                => ' ' . _m('Planned starts still run with the stored settings; to run the import by hand, change them in %1 below.', [$link]),
            !$start && ($no_key || in_array($code, ['SETTINGS', 'SLICE', 'NO_MAPPING', 'SOURCE'], true))
                => ' ' . _m('Change the settings or the source in %1 below.', [$link]),
            default => '',
        };
    }

    // ------------------------------------------------------------ settings

    /**
     * The source and its settings - the one place where they change - and the keys it needs. Picking
     * another source only shows its settings (?source=); one save then changes both, so the state is
     * dropped once and no definition is left with a source but without its settings.
     * @param mixed $chosen the source picked, from the request; anything else = the current one
     */
    public function settingsHtml(mixed $chosen = null): string
    {
        $current = $this->def->sourceType();
        $type    = (is_string($chosen) && isset(Registry::sources()[$chosen])) ? $chosen : $current;
        $html    = $this->sourceChoiceHtml($type) . $this->settingsFormHtml($type) . $this->keysHtml($type);
        return DetailPage::section('settings', _m('Settings of the source'), $html, false);
    }

    /** a GET form: picking a source only shows its settings */
    private function sourceChoiceHtml(string $type): string
    {
        $current = $this->def->sourceType();
        $sources = array_keys(Registry::sources());
        if (array_diff($sources, [$current]) === []) {
            return '<p>' . _m('Source') . ': ' . safe(Definition::sourceName($current)) . '. ' . _m('No other source is available.') . '</p>';
        }
        $options = '';
        foreach ($sources as $t) {
            $options .= '<option value="' . safe($t) . '"' . (($t === $type) ? ' selected' : '') . '>' . safe(Definition::sourceName($t)) . (($t === $current) ? ' ' . _m('(current)') : '') . '</option>';
        }
        // a GET form drops the query of its action: the parameters of the page go as fields
        [$path, $query] = explode('?', DetailPage::urlOf($this->def), 2) + ['', ''];
        parse_str($query, $params);
        $hidden = '';
        foreach ($params as $name => $value) {
            $hidden .= is_string($value) ? DetailPage::hiddenInput((string) $name, $value) : '';
        }
        return '<form method="get" action="' . safe($path) . '" class="aa-import-source">' . $hidden
            . '<label>' . _m('Source') . ' <select name="source">' . $options . '</select></label> '
            . '<button type="submit">' . _m('Show its settings') . '</button>'
            . (($type === $current) ? '' : ' <a href="' . safe(DetailPage::urlOf($this->def)) . '">' . _m('Keep the current source') . '</a>')
            . '</form>';
    }

    /** the settings of source $type, filled with the stored ones when it is the current source */
    private function settingsFormHtml(string $type): string
    {
        $current = $this->def->sourceType();
        $class   = Registry::sources()[$type] ?? null;
        if ($class === null) {
            return '<p class="aa-import-why">' . _m('This source is not available, so its settings cannot be shown. Choose another source above.') . '</p>';
        }
        $change = ($type !== $current);
        $note   = '';
        try {
            $stored = $change ? [] : Definition::settingsOf($this->def->snapshot());
        } catch (SourceRefused) {
            $stored = [];
            $note   = '<div class="aa-err">' . _m('The stored settings cannot be read; saving the form stores them again.') . '</div>';
        }
        try {
            $fields = $this->fieldsHtml($class::getClassProperties(), $stored);
        } catch (Throwable) {
            // a page that only reads logs nothing - a save asks the source again and logs it
            return '<div class="aa-err">' . _m('The source did not describe its settings, so they cannot be changed now.') . '</div>';
        }
        $why = $this->manageWhy($change ? $type : null);
        if ($change) {
            $what    = _m('You are changing the source from %1 to %2. Saving ends the open run, drops what the import knows of the streams and the catalog, and takes the mapping from the new source.', [safe(Definition::sourceName($current)), safe(Definition::sourceName($type))]);
            $confirm = _m('Change the source? The open run ends, what the import knows of the streams is dropped and the mapping comes from the new source.');
            $button  = _m('Change the source and save');
        } else {
            $what    = _m('Saving changed settings ends the open run, drops what the import knows of the streams and the catalog, and sets the mapping again from the source. Unchanged settings change nothing.');
            $confirm = _m('Save the settings? If they changed, the open run ends, what the import knows of the streams is dropped and the mapping is set again from the source.');
            $button  = _m('Save the settings');
        }
        return '<form method="post" action="' . safe(DetailPage::urlOf($this->def)) . '" class="aa-import-settings" data-aa-confirm="' . safe($confirm) . '">'
            . DetailPage::tokenFields($this->id(), 'settings') . DetailPage::hiddenInput('source', $type) . DetailPage::hiddenInput('stored', Service::settingsHash($this->def))
            . $note . $fields
            . '<p class="aa-import-why">' . $what . '</p>'
            . DetailPage::button($button, $why, ['class' => 'aa-button-run']) . DetailPage::whyHtml($why)
            . '</form>';
    }

    /** @param array<mixed> $properties of the source  @param array<string, string> $stored */
    private function fieldsHtml(array $properties, array $stored): string
    {
        $content = (new \AA_Content())->setId($this->id());
        $html    = '';
        foreach ($properties as $name => $property) {
            if (!($property instanceof \AA_Property)) {
                continue;
            }
            if (!self::isEditable($property)) {
                $html .= '<p class="aa-import-fixed">' . safe((string) $property->getName()) . ': ' . (isset($stored[$name]) ? '<code>' . safe($stored[$name]) . '</code>' : '--') . '</p>';
                continue;
            }
            if (isset($stored[$name])) {
                $content->setAaValue($property->getId(), new \AA_Value($stored[$name]));
            }
            $form = new \AA_Form();
            $property->addPropertyFormrows($form);
            $html .= $form->getRowsHtml($content);
        }
        return ($html === '') ? '<p class="aa-empty-note">' . _m('The source has no settings.') . '</p>' : $html;
    }

    /** The keys source $type needs: set, missing or damaged, to all who see the page. The end of a set
     *  key - still a part of it - and the constants to define only to a superadmin; a key never. */
    private function keysHtml(string $type): string
    {
        if (!isset(Registry::sources()[$type])) {
            return '';
        }
        $keys = Definition::sourceKeys($type);
        $html = '<h3>' . _m('Keys') . '</h3>';
        if ($keys === null) {
            return $html . '<p>' . _m('The source did not say which keys it needs.') . '</p>';
        }
        if (!$keys) {
            return $html . '<p class="aa-empty-note">' . _m('The source needs no key.') . '</p>';
        }
        $super = IsSuperadmin();
        $heads = $super ? [_m('Key'), _m('State'), _m('Hint')] : [_m('Key'), _m('State')];
        $rows  = '';
        foreach ($keys as [$vendor, $context]) {
            $state = self::keyState($vendor, $context);
            $rows .= '<tr><td><code>' . safe($vendor) . '</code>' . (($context === '') ? '' : ' <code>' . safe($context) . '</code>') . '</td>'
                . '<td>' . match ($state) {
                    'set'     => _m('Key is set'),
                    'damaged' => _m('Key is damaged'),
                    default   => _m('Key is missing'),
                } . '</td>'
                . ($super ? '<td>' . safe(self::keyHint($vendor, $context, $state)) . '</td>' : '')
                . '</tr>';
        }
        return $html . '<table class="aa-import-keys"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<p><small>' . _m('The administrator of the server sets the keys in config.php, never here. Without its key the source fails when it needs it; a damaged key holds a line break and is never sent.') . '</small></p>';
    }

    /** set, missing or damaged - a damaged key would put a line break into a request */
    private static function keyState(string $vendor, string $context): string
    {
        if (!Store::has($vendor, $context)) {
            return 'missing';
        }
        try {
            Store::resolve($vendor, $context);
        } catch (\UnexpectedValueException) {
            return 'damaged';
        }
        return 'set';
    }

    /** for a superadmin: the end of a set key or the constant of a damaged one; for a missing one,
     *  the constants that would hold it */
    private static function keyHint(string $vendor, string $context, string $state): string
    {
        return ($state === 'missing')
            ? _m('define one of %1 in config.php', [implode(', ', Store::names($vendor, $context))])
            : Store::describe($vendor, $context);
    }

    /** @return array<string, string> the stored settings; [] when they cannot be read */
    private function storedSettings(): array
    {
        try {
            return Definition::settingsOf($this->def->snapshot());
        } catch (SourceRefused) {
            return [];
        }
    }

    // ------------------------------------------------------------ mapping

    /** the mapping, read only - its expressions as text - and its reset from the source */
    public function mappingHtml(): string
    {
        try {
            $mapping = $this->def->mapping();
            $html    = $this->mappingRowsHtml($mapping) . self::mappingFactsHtml($mapping);
        } catch (SourceRefused) {
            $html = '<div class="aa-err">' . _m('The mapping of the import cannot be read. Reset it from the source.') . '</div>';
        }
        $why = isset(Registry::sources()[$this->def->sourceType()])
            ? $this->manageWhy(null)
            : DetailPage::reasonText('NO_SOURCE');
        $html .= '<form method="post" action="' . safe(DetailPage::urlOf($this->def)) . '" class="aa-import-actions">' . DetailPage::tokenFields($this->id(), 'reset_mapping')
            . DetailPage::button(_m('Reset mapping from the source'), $why, []) . DetailPage::whyHtml($why) . '</form>'
            . '<p><small>' . _m('Sets the mapping, the key fields, the store mode, the validation and the expression after a stream again as the source gives them for the stored settings. An open run goes on with the mapping it started with.') . '</small></p>';
        return DetailPage::section('mapping', _m('Mapping'), $html, false);
    }

    /** the rows of the mapping: target, transformation, parameters - all as text */
    private function mappingRowsHtml(Mapping $mapping): string
    {
        if (!$mapping->rows) {
            return '<p class="aa-empty-note">' . _m('The mapping has no rows.') . '</p>';
        }
        $slice = \AA_Slice::getModule(strtolower((string) $this->def->getOwnerId()));
        $heads = [_m('Target'), _m('Transformation'), _m('Parameters')];
        $html  = '<table class="aa-import-mapping"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>';
        foreach ($mapping->rows as $row) {
            $params = [];
            foreach ($row['p'] as $name => $value) {
                $params[] = '<code>' . safe((string) $name) . '</code> <code>' . safe($value) . '</code>';
            }
            $param = str_starts_with($row['to'], 'param:');
            $name  = (!$param && ($slice instanceof \AA_Slice)) ? (string) $slice->getField($row['to'])?->getName() : '';
            $html .= '<tr><td>' . (($name === '') ? '' : safe($name) . ' ') . '<code>' . safe($row['to']) . '</code>'
                . ($param ? ' <small>' . _m('the field the job parameter names') . '</small>' : '') . '</td>'
                . '<td><code>' . safe($row['t']) . '</code></td>'
                . '<td>' . implode('<br>', $params) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function mappingFactsHtml(Mapping $mapping): string
    {
        $codes = static fn(array $list, string $glue): string => $list ? implode($glue, array_map(static fn(string $x): string => '<code>' . safe($x) . '</code>', $list)) : '--';
        return DetailPage::factsHtml([
            _m('Key fields')     => $codes($mapping->keyFields, ', '),
            _m('Store mode')     => '<code>' . safe($mapping->storeMode) . '</code>',
            _m('Validation')     => $codes($mapping->validate, '<br>'),
            _m('After a stream') => $codes(($mapping->afterStream === '') ? [] : [$mapping->afterStream], ''),
        ]);
    }

    /** why the user may not change the definition (to source $source); '' = he may */
    private function manageWhy(?string $source): string
    {
        $code = $this->def->manageRefusal($this->uid, $source);
        return ($code === null) ? '' : Definition::refusal($code);
    }

    private function id(): string
    {
        return (string) $this->def->getId();
    }
}
