<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\SecurityLint;
use AA\Central\Package\ValueCodec;

/** Findings of an app check in words of the admin: what is wrong and what to do about it, and where it is
 *  - named by the site, slice, spot, view or field of the package instead of its ids. */
final class FindingText
{
    public function __construct(private readonly ?Package $package = null) {}

    /** [the risk, what to do] of a finding; null = the code says enough
     *  @return array{string, string}|null */
    public static function explain(LintFinding $finding): ?array
    {
        if ($finding->level !== LintFinding::SECURITY) {
            return null;
        }
        $code = $finding->code;
        return match (true) {
            in_array($code, ['include', 'file2text', 'rss2html', 'fileinfo', 'filelink', 'linkcheck', 'gethostbyaddr', 'img', 'f_z'], true) => [
                _m('The text makes this AA read a file or a URL. The server asks for it, not the visitor: it can read local files of the server or reach addresses of the internal network; a relative URL is fetched with the cookies of the visitor.'),
                _m('Fine when it reads a public address the app needs (a feed, an image or a page of its own site). Do not install it when it points to a local path (../, /etc/, file://), to an internal address (localhost, 10.x, 192.168.x) or when the address is built from what a visitor sends (URL parameters).'),
            ],
            in_array($code, ['server', 'cookie', 'xuser', 'userinfo', 'user'], true) => [
                _m('The text prints data of the request, of a user or of the server: {server} server variables, {cookie} the cookies of the visitor, {userinfo} the login log of any user, {user:password} the password the user signed in with.'),
                _m('Fine when it shows the visitors their own data (their name, the address of the page). Do not install it when it prints passwords or server settings, or sends such data out - in a URL, an e-mail or a hidden form field.'),
            ],
            in_array($code, ['credentials', 'jwtencode', 'setsecret'], true) || str_starts_with($code, 'changepwd') => [
                _m('The text carries or issues a secret: a reading password of a slice ({credentials}), a signed token ({jwtencode}), a stored secret ({setsecret}) or a change of a password ({changepwd...}). A secret written in the app travels with it to every AA it is installed on.'),
                _m('Expected only in apps with a login or an API. Check that the secret is never printed to visitors; after installing replace it with a secret of this AA.'),
            ],
            in_array($code, ['mail', 'mailtemplate', 'mailform', SecurityLint::ATTACHMENTS], true) => [
                _m('The app sends e-mail from this AA. When a visitor may choose the recipient, anyone can send mail through this AA (spam); attachments are downloaded by the server, a field filled by a visitor lets the visitor choose what is attached.'),
                _m('Check that the recipients are fixed addresses or those of the item, not a value from the URL or a form, that the attachments are public files of the app, and that the mail is not sent by a page anyone can load again and again.'),
            ],
            in_array($code, ['newitem', 'updateitem', 'ensureitem', 'recompute', 'datovka'], true) => [
                _m('The text writes data whenever it is shown: it creates or changes items ({newitem}, {updateitem}, {ensureitem}, {recompute}) or sends a data box message ({datovka}).'),
                _m('Check which slice it writes into - it should be a slice of this app. Writing into another slice by its id, or with values taken from the URL, is suspicious; {datovka} on a public page sends a message on every load.'),
            ],
            $code === 'internal' => [
                _m('The text calls an internal PHP method of AA with the data of the item.'),
                _m('Apps rarely need it - install only when you know why the app calls it.'),
            ],
            $code === 'redirect' => [
                _m('The text sends the visitor to another address. An address taken from the URL lets anyone make a link of your site that leads to a foreign (phishing) page.'),
                _m('Fine with a fixed address of the site; not with an address built from URL parameters.'),
            ],
            $code === 'header' => [
                _m('The text sets an HTTP header which decides what browsers allow: Content-Security-Policy (CSP - which scripts and styles may run), CORS (which other sites may read the answers), Report-To and NEL (where the browsers of the visitors send their error reports). A status code or a content type is only a note (I).'),
                _m('Check that the CSP allows no scripts of foreign domains, that CORS is not opened to * unless the page is a public API, and that Report-To and NEL send the reports to an address you trust. Security headers the site sets on purpose are fine - confirm them.'),
            ],
            in_array($code, ['expand', 'dequote'], true) => [
                _m('The text expands a text as a template again. When that text comes from visitors (an item of a public form, a URL parameter), they can put AA commands into it and run them with the rights of this AA.'),
                _m('Fine over the texts of the app itself; do not install it when it expands item content filled by anonymous posting or parameters of the URL.'),
            ],
            $code === 'f_u' => [
                _m('The field alias calls a PHP function by its name, and it is not a literal usr_ function the check could recognise.'),
                _m('Ask the author which function it calls; install only when it is a usr_ function defined in include/usr_aliasfnc.php of this AA.'),
            ],
            in_array($code, [SecurityLint::ALIAS_COMPUTED, SecurityLint::COMPOSED, SecurityLint::PLACEHOLDER, SecurityLint::JSON_BRACE, SecurityLint::QUOTE_MAP], true) => [
                _m('The name of a command is built only when the page is shown (from another command, a placeholder, JSON escapes or the internal quoting of AA), so the check cannot tell which command runs - it may be any of the dangerous ones.'),
                _m('Read the text around it. Common templates do not need that; unless you see why it is built this way, do not install the app.'),
            ],
            $code === SecurityLint::LINT_FAILED => [
                _m('The text is so complex that the check ran out of its limits - it is not checked completely.'),
                _m('Read the text yourself before installing.'),
            ],
            $code === SecurityLint::SLICE_PWD => [
                _m('A URL carries the reading password of a slice (slice_pwd=...). The password travels with the app and shows in pages and server logs.'),
                _m('After installing remove it from the URL and set a new reading password of the slice on this AA.'),
            ],
            default => null,
        };
    }

    /** where a finding is, by the names of the package: `Site "Web" > spot "Header" (5) > content`;
     *  the path itself when it is not one of a package */
    public function place(string $path): string
    {
        $module = null;
        $parts  = [];
        foreach (($path === '') ? [] : explode('/', $path) as $segment) {
            if (!preg_match('/^([^\[@]*)(?:\[(.*)\])?(?:@(\w+))?\z/s', $segment, $m)) {
                return $path;
            }
            [, $kind, $key, $attr] = $m + [2 => '', 3 => ''];
            $label = match ($kind) {
                Module::SITE, Module::SLICE, Module::ALERTS => $this->module($kind, $key, $module),
                'site', 'slice', 'module', 'spot-tree', 'alerts_collection_filter', 'alerts_collection_howoften' => '',
                'site_spot', 'spot' => self::named(_m('spot'), $this->spotName($module, $key), $key),
                'field'             => self::named(_m('field'), $this->rowName($module, 'field', 'id', $key, 'name'), $key),
                'view'              => self::named(_m('view'), $this->rowName($module, 'view', 'id', $key, 'name'), $key),
                'email'             => self::named(_m('e-mail'), $this->rowName($module, 'email', 'id', $key, 'description'), $key),
                'alerts_filter'     => self::named(_m('filter'), $this->rowName($module, 'alerts_filter', 'id', $key, 'description'), $key),
                'alerts_collection' => self::named(_m('collection'), '', $key),
                'object'            => self::named(_m('object'), $this->objectName($module, $key), $key),
                'item'              => self::named(_m('item'), $this->itemName($module, $key), $key),
                'constant-group'    => self::named(_m('constant group'), $key, ''),
                'constant'          => self::named(_m('constant'), $this->constantName($key), $key),
                'settings'          => _m('settings'),
                'setting-values'    => _m('values of the settings'),
                'var'               => _m('variable'),
                'cond'              => _m('condition'),
                default             => $kind . ((($key !== '') && !str_starts_with($key, '#') && ($key !== '0')) ? "[$key]" : ''),
            };
            if ($label !== '') {
                $parts[] = $label;
            }
            if ($attr !== '') {
                $parts[] = ['name' => _m('name'), 'var' => _m('variable')][$attr] ?? $attr;
            }
        }
        return $parts ? implode(' › ', $parts) : $path;
    }

    /** `kind "name" (key)`; a long key (a module or item id) is left to the path shown with the place */
    private static function named(string $kind, string $name, string $key): string
    {
        $key = (strlen($key) >= 32) ? '' : $key;
        return $kind . (($name !== '') ? ' "' . $name . '"' : '') . ((($key !== '') && ($name === '' || $key !== $name)) ? " ($key)" : '');
    }

    private function module(string $type, string $id, ?Module &$module): string
    {
        $module = null;
        foreach ($this->package->modules ?? [] as $m) {
            if (strcasecmp($m->id, $id) === 0) {
                $module = $m;
            }
        }
        $name = $module ? ValueCodec::bytes($module->rows['module'][0]['name'] ?? '') : '';
        $kind = [Module::SITE => _m('Site'), Module::SLICE => _m('Slice'), Module::ALERTS => _m('Alerts')][$type];
        return self::named($kind, $name, '');
    }

    private function rowName(?Module $module, string $element, string $key_column, string $key, string $name_column): string
    {
        foreach ($module->rows[$element] ?? [] as $row) {
            if (ValueCodec::bytes($row[$key_column] ?? '') === $key) {
                return ValueCodec::bytes($row[$name_column] ?? '');
            }
        }
        return '';
    }

    private function spotName(?Module $module, string $id): string
    {
        foreach ($module->spotTree['spots'] ?? [] as $spot) {
            if (is_array($spot) && (ValueCodec::bytes($spot['id'] ?? '') === $id)) {
                return ValueCodec::bytes($spot['n'] ?? '');
            }
        }
        return '';
    }

    private function objectName(?Module $module, string $id): string
    {
        foreach ($module->objects ?? [] as $object) {
            if ((string) ($object['id'] ?? '') === $id) {
                return ValueCodec::bytes($object['name'] ?? '');
            }
        }
        return '';
    }

    private function itemName(?Module $module, string $id): string
    {
        foreach ($module->items ?? [] as $item) {
            if (strcasecmp(ValueCodec::bytes($item['id'] ?? ''), $id) === 0) {
                $headline = $item['content']['headline........'][0]['value'] ?? '';
                return mb_strimwidth(ValueCodec::bytes($headline), 0, 60, '…');
            }
        }
        return '';
    }

    private function constantName(string $id): string
    {
        foreach ($this->package->constantGroups ?? [] as $group) {
            foreach ($group->rows['constant'] ?? [] as $row) {
                if (ValueCodec::bytes($row['id'] ?? '') === $id) {
                    return ValueCodec::bytes($row['name'] ?? '');
                }
            }
        }
        return '';
    }
}
