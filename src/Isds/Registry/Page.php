<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use AA\Central\ResponderPolicy;
use AA\IO\DB\DB_AA;
use AA\Util\FormToken;
use AA\Util\LogOnce;
use AA\Widget\Widget;
use AA_Content;
use AA_Fields;
use AA_Form;
use AA_Slice;
use AA_Value;

/**
 * The page of a slice that pairs its items with the register of data boxes
 * (admin/se_isds_registry.php): the downloaded register, the report of Sync with a checkbox for each
 * row a person may write, and the settings of the pairing. Every action is a POST with the token of
 * the slice's page, answered by a redirect to the GET page, which shows its outcome once; a GET only
 * reads. Values of the items, of the register and of the settings go out through safe().
 *
 * The report and the write need PS_EDIT_ALL_ITEMS on the slice - Sync reads the items past the slice's
 * reading password and writes for whoever runs it; the settings need PS_FIELDS. The caller lets in
 * users with either.
 */
class Page
{
    /** boxes ticked in advance: unchecked boxes are not posted, so max_input_vars (1000 by default -
     *  PHP drops the inputs over it without a word) limits the ticked ones; one write takes no more */
    public const MAX_TICKS = Sync::MAX_WRITE;

    public const CSS = '
        .aa-isds-intro { max-width: 56rem; }
        .aa-isds-filter { display: flex; flex-wrap: wrap; gap: .3em 1.4em; margin: .8em 0; padding: 0; list-style: none; }
        .aa-isds-filter a[aria-current] { font-weight: 600; color: inherit; }
        .aa-isds-n { font-variant-numeric: tabular-nums; }
        .aa-isds-scroll { overflow-x: auto; }
        .aa-isds-report ul { margin: 0; padding-left: 1.1em; }
        .aa-isds-note { font-size: var(--sz-s); }
        .aa-isds-shared { font-weight: 600; }
        .aa-isds-none { color: var(--clr-text-accent); }
    ';

    /** $_SESSION key of the outcome of the last action, per slice, until its page shows it */
    private const FLASH = 'aa_isds_registry_outcome';

    private const ACTIONS = ['settings', 'download', 'write'];

    public function __construct(
        private readonly string $sliceId,
        private readonly ?Config $config,
        private readonly bool $mayWrite,
        private readonly bool $mayConfigure,
    ) {}

    /** the page of slice $sliceId for the logged-in user */
    public static function forRequest(string $sliceId): self
    {
        $sliceId = strtolower($sliceId);
        return new self($sliceId, Config::forSlice($sliceId), IfSlPerm(PS_EDIT_ALL_ITEMS, $sliceId), IfSlPerm(PS_FIELDS, $sliceId));
    }

    /**
     * The status filter of a GET: '' = all rows.
     * @throws \InvalidArgumentException another value - a link of this page never has one
     */
    public static function statusOf(string $status): string
    {
        if (($status !== '') && !in_array($status, MatchRow::STATUSES, true)) {
            throw new \InvalidArgumentException('status: not a status of the report');
        }
        return $status;
    }

    /** @param array<string, string> $more further parameters of the page */
    public static function urlOf(string $sliceId, array $more = []): string
    {
        return get_admin_url('se_isds_registry.php', ['change_id' => $sliceId] + $more, false);
    }

    /**
     * Does the action a POST asks for; the caller then redirects (afterPost()) before the page reads
     * anything. A GET, or a POST without a known action, does nothing.
     * @param array<array-key, mixed> $post   action, token, status (the filter to return to), and per action:
     *                                        aa (the settings form), stamp and tick (the write form)
     * @param array<array-key, mixed> $server $_SERVER
     * @return ?array<string, mixed> the outcome: its 'code' and what outcomeHtml() shows with it
     */
    public function handle(array $post, array $server): ?array
    {
        $action = $post['action'] ?? null;
        if ((($server['REQUEST_METHOD'] ?? '') !== 'POST') || !in_array($action, self::ACTIONS, true)) {
            return null;
        }
        $token = $post['token'] ?? '';
        if (ResponderPolicy::isForeignBrowserRequest($server) || !FormToken::checkPost($this->tokenPurpose(), is_string($token) ? $token : '', $server)) {
            return ['code' => 'expired'];
        }
        return match ($action) {
            'settings' => $this->saveSettings($post['aa'] ?? []),
            'download' => $this->downloadRegister(),
            default    => $this->writeTicked($post['stamp'] ?? '', $post['tick'] ?? []),
        };
    }

    /**
     * Keeps the outcome of handle() for the GET page, which shows it once.
     * @param array<string, mixed> $outcome
     * @param string               $status  the posted status filter to return to; anything else shows all rows
     * @return string the GET page to redirect to (303)
     */
    public function afterPost(array $outcome, string $status = ''): string
    {
        $_SESSION[self::FLASH][$this->sliceId] = $outcome;
        return self::urlOf($this->sliceId, in_array($status, MatchRow::STATUSES, true) ? ['status' => $status] : []);
    }

    /**
     * The page below its title. Builds the settings form, whose widgets ask for their scripts - call it
     * before the head is printed.
     * @param string $status statusOf() of the request
     */
    public function html(string $status = ''): string
    {
        $outcome = $_SESSION[self::FLASH][$this->sliceId] ?? [];
        unset($_SESSION[self::FLASH][$this->sliceId]);
        try {
            $info       = $this->registerInfo();
            $unreadable = false;
        } catch (\RuntimeException $e) {
            $this->logFailure('look', $e);
            $info       = null;
            $unreadable = true;
        }
        $refused = (($outcome['code'] ?? '') === 'settings_refused');

        return '<p class="aa-isds-intro">' . _m('This page finds the data box of each item in the public register of data boxes and writes it into the item. Only the rows you tick are written, and only the data box field changes. A box found by IČO is ticked in advance; a box found by name is a suggestion only, so compare the names and tick it yourself.') . '</p>'
            . self::outcomeHtml($outcome, $this->now())
            . $this->registerHtml($info, $unreadable)
            . $this->reportHtml($status, $info)
            // only a refused save keeps its values
            . $this->settingsHtml($refused ? ($outcome['values'] ?? null) : null, $refused);
    }

    // ------------------------------------------------------------ actions

    /** @return array<string, mixed> */
    private function saveSettings(mixed $aa): array
    {
        if (!$this->mayConfigure) {
            return ['code' => 'refused'];
        }
        $values = $this->postedValues($aa);
        if ($values === null) {
            return ['code' => 'settings_refused', 'errors' => [_m('The form sent values this page does not send. Reload the page and try again.')]];
        }
        $errors = Config::errors($values, $this->sliceFields());
        if ($errors) {
            return ['code' => 'settings_refused', 'errors' => $errors, 'values' => $values];
        }
        $this->saveConfig(Config::fromValues($this->sliceId, $values));
        return ['code' => 'settings_saved'];
    }

    /**
     * The settings the form posts: aa[u<Config::idFor()>][<property>][...], as AA_Form names them.
     * @return ?array<string, string|list<string>> property => its text, or the texts of a list; null for
     *                                             what the form does not post
     */
    private function postedValues(mixed $aa): ?array
    {
        $posted = is_array($aa) ? ($aa['u' . Config::idFor($this->sliceId)] ?? null) : null;
        $values = [];
        foreach (Config::getClassProperties() as $id => $property) {
            $data  = is_array($posted) ? ($posted[$id] ?? []) : [];
            $texts = self::postedTexts($data);
            if ($texts === null) {
                return null;
            }
            $values[$id] = $property->isMulti() ? $texts : ($texts[0] ?? '');
        }
        return $values;
    }

    /**
     * The texts of one setting as the select (aa[..][prop][]) or the list widget (aa[..][prop][mfl][n]
     * and [flag]) posts them; null for anything else - the widgets' code expects their own shape and
     * throws a TypeError on an array in place of a text.
     * @return ?list<string>
     */
    private static function postedTexts(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }
        // the HTML flag means nothing for a field id or a type, and a text there breaks Widget::getValue()
        unset($data['flag']);
        foreach ($data as $key => $value) {
            $texts = (($key === 'mfl') && is_array($value)) ? $value : [$key => $value];
            foreach ($texts as $n => $text) {
                if (!ctype_digit((string) $n) || !is_string($text)) {
                    return null;
                }
            }
        }
        return array_values(array_filter(Widget::getValue($data)->getValues(), 'is_string'));
    }

    /** @return array<string, mixed> */
    private function downloadRegister(): array
    {
        if (!$this->mayWrite) {
            return ['code' => 'refused'];
        }
        if ($this->config === null) {
            return ['code' => 'no_settings'];
        }
        try {
            $done = $this->download();
        } catch (\RuntimeException $e) {
            $this->logFailure('download', $e);
            return ['code' => 'download_failed'];
        }
        return match ($done['status']) {
            Sync::RUNNING => ['code' => 'download_running'],
            Sync::RECENT  => ['code' => 'download_recent', 'time' => $done['last']],
            default       => ['code' => 'downloaded'],
        };
    }

    /**
     * Writes the ticked rows that still offer the box the person saw, from this request's report.
     * @return array<string, mixed>
     */
    private function writeTicked(mixed $stamp, mixed $ticked): array
    {
        if (!$this->mayWrite) {
            return ['code' => 'refused'];
        }
        if ($this->config === null) {
            return ['code' => 'no_settings'];
        }
        try {
            $info = $this->registerInfo();
        } catch (\RuntimeException $e) {
            $this->logFailure('look', $e);
            return ['code' => 'register_unreadable'];
        }
        // the rows were matched against the register and the settings the person saw
        if (($info === null) || !is_string($stamp) || !hash_equals(Sync::registerStamp($info, $this->config), $stamp)) {
            return ['code' => 'view_changed'];
        }
        try {
            $boxes = $this->registerBoxes();
        } catch (\RuntimeException $e) {
            $this->logFailure('read', $e);
            return ['code' => 'register_unreadable'];
        }
        $sync = $this->newSync($this->config);
        try {
            $report = $sync->report($boxes);
        } catch (\RuntimeException $e) {
            return ['code' => 'misfit', 'message' => $e->getMessage()];
        }
        ['rows' => $rows, 'stale' => $stale, 'left' => $left] = Sync::toWrite($report, is_array($ticked) ? $ticked : []);
        try {
            $done = $sync->write($rows);
        } catch (\Exception $e) {
            // storeItem() threw: the rows before it are written, their count is lost; an Error is a
            // bug and goes to the log of fatals
            $this->logFailure('write', $e);
            return ['code' => 'write_stopped'];
        }
        $failed = [];
        foreach ($rows as $row) {
            $message = $done['failed'][(string) $row->itemId] ?? null;
            if ($message !== null) {
                $failed[] = $row->name . ': ' . $message;
            }
        }
        return ['code' => 'written', 'written' => $done['written'], 'stale' => count($stale), 'left' => $left, 'failed' => $failed];
    }

    /**
     * What an action did, for the page after the redirect; '' = nothing to say. What failed reading,
     * downloading or writing is in the AA log only, which only an administrator reads: it may name a
     * server path or quote the answer of the operator's server.
     * @param array<array-key, mixed> $outcome handle()'s, as the session kept it
     * @param ?int                    $now     the time of the page, time() when null
     */
    public static function outcomeHtml(array $outcome, ?int $now = null): string
    {
        $err  = static fn(string $html): string => '<div class="aa-err" role="alert">' . $html . '</div>';
        $info = static fn(string $html): string => '<div class="aa-info" role="status">' . $html . '</div>';
        $log  = [Sync::LOG_EVENT];
        return match ($outcome['code'] ?? '') {
            'expired'             => $err(_m('The form has expired; reload the page and try again.')),
            'refused'             => $err(_m('You do not have permissions to do this in this slice.')),
            'settings_saved'      => '<div class="aa-ok" role="status">' . _m('The settings are saved.') . '</div>',
            'settings_refused'    => $err(_m('The settings were not saved:') . self::listHtml($outcome['errors'])),
            'downloaded'          => '<div class="aa-ok" role="status">' . _m('The register is downloaded.') . '</div>',
            'download_recent'     => $info(self::recentHtml($outcome['time'], $now ?? time())),
            'download_running'    => $info(_m('The register is being downloaded right now. Reload the page in a minute.')),
            'download_failed'     => $err(_m('The register did not download; the previous one stays in use. Try again later. An administrator finds the details in the AA log (type %1).', $log)),
            'view_changed'        => $err(_m('The register or the settings of the pairing have changed since you looked at the rows, so nothing was written. Check the rows below again and write them.')),
            'register_unreadable' => $err(_m('The copy of the register cannot be read, so nothing was written. An administrator finds the details in the AA log (type %1).', $log)),
            'no_settings'         => $err(_m('Choose the fields of the pairing first.')),
            'misfit'              => $err(_m('The settings of the pairing no longer fit the fields of the slice, so nothing was written: %1', [safe($outcome['message'])])),
            'write_stopped'       => $err(_m('Writing stopped with an error. The rows written before it stay written; the report below shows the items as they are now. An administrator finds the details in the AA log (type %1).', $log)),
            'written'             => self::writtenHtml($outcome),
            default               => '',
        };
    }

    /** the next possible download with its date when it is not today */
    private static function recentHtml(int $last, int $now): string
    {
        $next = $last + Sync::MIN_DOWNLOAD_AGE;
        return _m('The last download attempt was at %1. Another one is possible from %2.', [date('Y-m-d H:i', $last), date((date('Y-m-d', $next) === date('Y-m-d', $now)) ? 'H:i' : 'Y-m-d H:i', $next)]);
    }

    /** @param array<array-key, mixed> $outcome */
    private static function writtenHtml(array $outcome): string
    {
        ['written' => $written, 'stale' => $stale, 'left' => $left, 'failed' => $failed] = $outcome;
        $html = ($written > 0)
            ? '<div class="aa-ok" role="status">' . _m('Data boxes written: %1.', [$written]) . '</div>'
            : (($stale || $left || $failed) ? '' : '<div class="aa-info" role="status">' . _m('Nothing was written: no row was ticked, or the ticked ones have their box already.') . '</div>');
        if ($stale > 0) {
            $html .= '<div class="aa-info" role="status">' . _m('Ticked rows that changed meanwhile and were not written: %1. Check them again below.', [$stale]) . '</div>';
        }
        if ($left > 0) {
            $html .= '<div class="aa-info" role="status">' . _m('Ticked rows left for the next write: %1. One write takes at most %2 rows.', [$left, Sync::MAX_WRITE]) . '</div>';
        }
        if ($failed) {
            $html .= '<div class="aa-err" role="alert">' . _m('These items were not written:') . self::listHtml($failed) . '</div>';
        }
        return $html;
    }

    /** @param list<string> $texts */
    private static function listHtml(array $texts): string
    {
        $html = '';
        foreach ($texts as $text) {
            $html .= '<li>' . safe($text) . '</li>';
        }
        return '<ul>' . $html . '</ul>';
    }

    // ------------------------------------------------------------ register

    /** @param ?array{mtime: int, size: int} $info registerInfo() */
    private function registerHtml(?array $info, bool $unreadable): string
    {
        $downloads = $this->mayWrite && ($this->config !== null);
        if ($unreadable) {
            $html = '<div class="aa-err">' . _m('The copy of the register cannot be read. An administrator finds the details in the AA log (type %1).', [Sync::LOG_EVENT]) . '</div>';
        } elseif ($info === null) {
            $html = '<p>' . ($downloads ? _m('The register has not been downloaded yet. Download it to see the report.') : _m('The register has not been downloaded yet.')) . '</p>';
        } else {
            $html = '<p>' . $this->downloadedText($info['mtime']) . ' ' . _m('Size: %1 MB.', [number_format($info['size'] / 1048576, 1)]) . '</p>';
            if (Sync::isOld($info, $this->now())) {
                $html .= '<p class="aa-info">' . _m('The register is older than %1 days. Download it again before writing: authorities get new boxes and merge.', [Sync::OLD_DAYS]) . '</p>';
            }
        }
        if ($downloads) {
            $html .= $this->form('download', '<button type="submit">' . _m('Download the register now') . '</button> '
                . '<span class="aa-isds-note">' . _m('The public register of the authorities\' data boxes, about 17 MB; the download takes up to a minute. All slices of this AA use the same copy.') . '</span>');
        }
        return self::section('register', _m('Register of data boxes'), $html);
    }

    /** today and yesterday by the calendar of the server, not by 24 hours */
    private function downloadedText(int $mtime): string
    {
        $now  = $this->now();
        $when = date('Y-m-d H:i', $mtime);
        return match (date('Y-m-d', $mtime)) {
            date('Y-m-d', $now)                                => _m('Downloaded: %1 (today).', [$when]),
            date('Y-m-d', strtotime('yesterday', $now) ?: 0)   => _m('Downloaded: %1 (yesterday).', [$when]),
            default                                            => _m('Downloaded: %1.', [$when]),
        };
    }

    // ------------------------------------------------------------ report

    /** @param ?array{mtime: int, size: int} $info registerInfo() */
    private function reportHtml(string $status, ?array $info): string
    {
        $body = $this->reportBodyHtml($status, $info);
        return ($body === '') ? '' : self::section('report', _m('Items and their data boxes'), $body);
    }

    /**
     * '' = no report: no settings, no right to write, or no register
     * @param ?array{mtime: int, size: int} $info registerInfo()
     */
    private function reportBodyHtml(string $status, ?array $info): string
    {
        $config = $this->config;
        if (($config === null) || !$this->mayWrite || ($info === null)) {
            return '';
        }
        try {
            $boxes = $this->registerBoxes();
        } catch (\RuntimeException $e) {
            $this->logFailure('read', $e);
            return '<div class="aa-err">' . _m('The copy of the register cannot be read. An administrator finds the details in the AA log (type %1).', [Sync::LOG_EVENT]) . '</div>';
        }
        try {
            $report = $this->newSync($config)->report($boxes);
        } catch (\RuntimeException $e) {
            return '<div class="aa-err">' . _m('The settings of the pairing no longer fit the fields of the slice: %1. Choose the fields again in the settings below.', [safe($e->getMessage())]) . '</div>';
        }
        if (!$report->rows) {
            return '<p class="aa-empty-note">' . _m('The slice has no items to pair.') . '</p>';
        }
        $rows = ($status === '') ? $report->rows : array_values(array_filter($report->rows, static fn(MatchRow $row): bool => $row->status === $status));
        $html = $this->filterHtml($report, $status);
        if (!$rows) {
            return $html . '<p class="aa-empty-note">' . _m('No row has this status.') . '</p>';
        }

        $default  = array_fill_keys(Sync::defaultTicked($report), true);
        // the items of the shared boxes: sharedBoxes() alone decides which box a row holds
        $shared   = array_fill_keys(array_merge(...array_values($report->sharedBoxes())), true);
        $types    = ($config->typeField() !== '');
        $ticked   = 0;
        $writable = 0;
        $body     = '';
        foreach ($rows as $row) {
            $tick = null;
            if ($row->writable()) {
                ++$writable;
                $tick    = isset($default[(string) $row->itemId]) && ($ticked < self::MAX_TICKS);
                $ticked += $tick ? 1 : 0;
            }
            $body .= $this->rowHtml($row, $tick, isset($shared[$row->itemId]), $types);
        }

        $heads = [_m('Write'), _m('Item')];
        if ($types) {
            $heads[] = _m('Type');
        }
        array_push($heads, _m('Status'), _m('Stored data box'), _m('Data box in the register'), _m('Found'));
        $table = '<div class="aa-isds-scroll"><table class="aa-table aa-table-simple aa-isds-report"><thead><tr><th scope="col">' . implode('</th><th scope="col">', $heads) . '</th></tr></thead><tbody>' . $body . '</tbody></table></div>';
        if ($writable === 0) {
            return $html . $table;
        }
        if ($writable > self::MAX_TICKS) {
            $html .= '<p class="aa-info">' . _m('One write takes at most %1 rows, and at most as many are ticked in advance. Write the rest afterwards.', [self::MAX_TICKS]) . '</p>';
        }
        return $html . $this->form('write', $table
            . '<p><button type="submit" class="btn-primary">' . _m('Write the ticked data boxes') . '</button> '
            . '<span class="aa-isds-note">' . _m('Writes only the data box field of the ticked items. Their history records the change.') . '</span></p>', ['stamp' => Sync::registerStamp($info, $config), 'status' => $status]);
    }

    /** links that show the rows of one status, with their counts */
    private function filterHtml(MatchReport $report, string $status): string
    {
        $html = '';
        foreach (['' => count($report->rows)] + $report->statusCounts() as $one => $count) {
            $label   = ($one === '') ? _m('All') : MatchRow::statusLabel($one);
            $current = ($one === $status) ? ' aria-current="page"' : '';
            $html   .= '<li><a href="' . safe(self::urlOf($this->sliceId, ($one === '') ? [] : ['status' => $one])) . '"' . $current . '>' . $label . ' <span class="aa-isds-n">' . $count . '</span></a></li>';
        }
        return '<ul class="aa-isds-filter" aria-label="' . safe(_m('Show the rows of')) . '">' . $html . '</ul>';
    }

    /** @param ?bool $tick null = no checkbox, the row cannot be written */
    private function rowHtml(MatchRow $row, ?bool $tick, bool $shared, bool $types): string
    {
        $name  = ($row->name === '') ? '<span class="aa-isds-none">' . _m('(no name)') . '</span>' : safe($row->name);
        $check = ($tick === null) ? '' : '<input type="checkbox"' . htmlattr([
            'name'       => 'tick[' . $row->itemId . ']',
            'value'      => Sync::tickValue($row),
            'aria-label' => _m('Write the data box %1 into %2', [$row->newDs, $row->name]),
            'checked'    => $tick ? 'checked' : '',
        ]) . '>';
        $html = '<tr><td>' . $check . '</td><th scope="row">' . $name . '</th>';
        if ($types) {
            $html .= '<td>' . safe($row->type) . '</td>';
        }
        $stored = trim($row->oldDs);
        $html  .= '<td>' . MatchRow::statusLabel($row->status) . '</td>'
            . '<td>' . (($stored === '') ? '<span class="aa-isds-none">' . _m('none') . '</span>' : '<code>' . safe($stored) . '</code>') . '</td>'
            . '<td>' . self::registerCellHtml($row) . '</td>'
            . '<td>' . self::foundHtml($row, $shared) . '</td></tr>';
        return $html;
    }

    private static function registerCellHtml(MatchRow $row): string
    {
        if ($row->box !== null) {
            return '<code>' . safe($row->newDs) . '</code> ' . safe($row->boxName);
        }
        if (!$row->candidates) {
            return '<span class="aa-isds-none">' . _m('Not found') . '</span>';
        }
        $html = '';
        foreach ($row->candidates as $box) {
            $about = array_filter([$box->name, $box->city, ($box->ico === '') ? '' : _m('IČO %1', [$box->ico])], static fn(string $s): bool => $s !== '');
            $html .= '<li><code>' . safe($box->id) . '</code> ' . safe(implode(', ', $about)) . '</li>';
        }
        return '<ul>' . $html . '</ul>';
    }

    private static function foundHtml(MatchRow $row, bool $shared): string
    {
        $html = match ($row->method) {
            MatchRow::BY_ICO  => _m('by IČO'),
            MatchRow::BY_NAME => _m('by name, check it'),
            default           => '',
        };
        if ($row->icoMismatch) {
            $html .= '<br><span class="aa-isds-note">' . _m('IČO of the item %1, of the box %2', [safe($row->ico), safe($row->box->ico ?? '')]) . '</span>';
        }
        if ($shared) {
            $html .= '<br><span class="aa-isds-note aa-isds-shared">' . _m('Another item has or would get this data box too. Check that it is not one place twice.') . '</span>';
        }
        return $html;
    }

    // ------------------------------------------------------------ settings

    /** @param ?array<array-key, mixed> $refused the values of a refused save, shown again */
    private function settingsHtml(?array $refused, bool $open): string
    {
        $html = $this->mayConfigure ? $this->settingsFormHtml($refused ?? $this->config?->values() ?? []) : $this->settingsTextHtml();
        return self::section('settings', _m('Settings of the pairing'), $html, $open || ($this->config === null));
    }

    /** @param array<array-key, mixed> $values */
    private function settingsFormHtml(array $values): string
    {
        $fields  = $this->sliceFields();
        $content = (new AA_Content())->setId(Config::idFor($this->sliceId))->setOwnerId($this->sliceId);
        $form    = new AA_Form();
        foreach (Config::getClassProperties() as $id => $property) {
            $content->setAaValue($id, new AA_Value($values[$id] ?? ($property->isMulti() ? [] : '')));
            if (!$property->isMulti()) {
                // a select of the slice's fields instead of a text for a field id
                $property->setConstants(self::fieldOptions($fields, $id, $property->isRequired()));
            }
            $property->addPropertyFormrows($form);
        }
        return '<p>' . _m('Saving changes only these settings, never the items.') . '</p>'
            . $this->form('settings', $form->getRowsHtml($content) . '<p><button type="submit">' . _m('Save the settings') . '</button></p>');
    }

    /**
     * The fields setting $property may name, as Config::errors() allows them
     * @return array<string, string> field id => "name (id)"
     */
    private static function fieldOptions(AA_Fields $fields, string $property, bool $required): array
    {
        $options = ['' => $required ? _m('Choose a field') : _m('None')];
        foreach ($fields as $id => $field) {
            if (Config::fieldRefusal($property, $field) === null) {
                $options[(string) $id] = (string) $field->getProperty('name') . ' (' . $id . ')';
            }
        }
        return $options;
    }

    /** the settings for a user who may not change them */
    private function settingsTextHtml(): string
    {
        if ($this->config === null) {
            return '<p class="aa-empty-note">' . _m('The pairing is not set up for this slice yet. Someone who may change the fields of the slice sets it up here.') . '</p>';
        }
        $fields = $this->sliceFields();
        $values = $this->config->values();
        $html   = '';
        foreach (Config::getClassProperties() as $id => $property) {
            $value = $values[$id] ?? '';
            if (is_array($value)) {
                $text = safe(implode(', ', $value));
            } else {
                // a field the slice no longer has shows its id alone
                $name = ($value === '') ? '' : (string) $fields->getField($value)?->getProperty('name');
                $text = ($name === '') ? safe($value) : safe($name) . ' <code>' . safe($value) . '</code>';
            }
            $html .= '<tr><th scope="row">' . safe((string) $property->getName()) . '</th><td>' . (($text === '') ? '<span class="aa-isds-none">' . _m('none') . '</span>' : $text) . '</td></tr>';
        }
        return '<table class="aa-table aa-table-simple aa-w-no-full"><tbody>' . $html . '</tbody></table>';
    }

    // ------------------------------------------------------------ parts

    /** a token of another slice's page does not work here */
    private function tokenPurpose(): string
    {
        return 'isds-registry:' . $this->sliceId;
    }

    /**
     * A form of one action, with the token of the slice's page
     * @param array<string, string> $hidden further hidden inputs
     */
    private function form(string $action, string $body, array $hidden = []): string
    {
        $inputs = FormToken::field($this->tokenPurpose());
        foreach (['action' => $action] + $hidden as $name => $value) {
            $inputs .= '<input type="hidden" name="' . safe($name) . '" value="' . safe($value) . '">';
        }
        return '<form method="post" action="' . safe(self::urlOf($this->sliceId)) . '">' . $inputs . $body . '</form>';
    }

    /** @throws \RuntimeException the slice is gone */
    private function sliceFields(): AA_Fields
    {
        $slice = AA_Slice::getModule($this->sliceId);
        if (!($slice instanceof AA_Slice)) {
            throw new \RuntimeException('The slice is gone');
        }
        return $slice->getFields();
    }

    private static function section(string $name, string $title, string $body, bool $open = true): string
    {
        return '<details id="aa-isds-' . $name . '" class="aa-page-section"' . ($open ? ' open' : '') . '><summary><h2 class="aa-tab-title">' . $title . '</h2></summary>' . $body . '</details>';
    }

    // ------------------------------------------------------------ what tests replace

    protected function newSync(Config $config): Sync
    {
        return new Sync($config);
    }

    /**
     * @return list<Box>
     * @throws \RuntimeException see Sync::registerBoxes()
     */
    protected function registerBoxes(): array
    {
        return Sync::registerBoxes();
    }

    /**
     * @return ?array{mtime: int, size: int}
     * @throws \RuntimeException see Sync::registerInfo()
     */
    protected function registerInfo(): ?array
    {
        return Sync::registerInfo();
    }

    /**
     * @return array{status: string, last: int} see Sync::download()
     * @throws \RuntimeException see Sync::download()
     */
    protected function download(): array
    {
        return Sync::download(null, $this->now());
    }

    /** the message for whoever reads the AA log - once a day, as a broken directory fails every view;
     *  it goes into the selector, which holds 255 bytes, params only 128. $what: look (at the file),
     *  read, download or write */
    protected function logFailure(string $what, \Throwable $e): void
    {
        LogOnce::daily(Sync::LOG_EVENT, $what . ': ' . $e->getMessage(), $this->sliceId);
    }

    /** the old values go only with the new ones */
    protected function saveConfig(Config $config): void
    {
        DB_AA::transaction(static fn(): string|false => $config->save());
    }

    protected function now(): int
    {
        return time();
    }
}
