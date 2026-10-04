<?php

declare(strict_types=1);

namespace AA\Central\Package;

use InvalidArgumentException;
use JsonException;

/** What the admin chose in the preview of an install besides "Into": new names of the modules, slices
 *  left out, the owner of the new modules (one of this AA's or a new one), values of the settings
 *  fields of the slices and, in a transfer, the conditions of the items each slice takes. The run keeps
 *  them (InstallRun), so the write plans exactly what the preview showed.
 *  All strings are request text - the planner checks them as package text (charset, length, lint). */
final readonly class ImportChoices
{
    /** a module id of the choices: a long id, lower case */
    private const ID_RE = '/^[0-9a-f]{32}\z/';

    /** a filter sent as no text: kept as one ItemFilter refuses - a filter left out would take all the items */
    public const NOT_TEXT = "\u{FFFD}";

    /**
     * @param array<string, string>                $names    source module id => its new name (trimmed, not empty)
     * @param list<string>                         $skip     source slice ids left out
     * @param string                               $owner    slice_owner id of the new modules; '' = the planner's default
     * @param array{name: string, email: string}|null $newOwner an owner the write creates instead of $owner
     * @param array<string, array<string, string>> $settings source slice id => settings field id => value typed
     *                                                       ('' fields left out: the value of the app stays)
     * @param array<string, string>                $filters  source slice id => ItemFilter text, trimmed, not empty
     */
    public function __construct(
        public array $names = [],
        public array $skip = [],
        public string $owner = '',
        public ?array $newOwner = null,
        public array $settings = [],
        public array $filters = [],
    ) {
        foreach ([...array_keys($names), ...$skip, ...array_keys($settings), ...array_keys($filters)] as $id) {
            if (!preg_match(self::ID_RE, (string) $id)) {
                throw new InvalidArgumentException('A module of the choices is a long id, lower case');
            }
        }
        if (($owner !== '') && !preg_match(self::ID_RE, $owner)) {
            throw new InvalidArgumentException('The owner is a long id, lower case');
        }
        foreach ($settings as $fields) {
            foreach (array_keys($fields) as $field) {
                if (!preg_match(Validator::SETTING_FIELD_RE, (string) $field)) {
                    throw new InvalidArgumentException('A settings field id starts with _ and has 16 characters');
                }
            }
        }
    }

    /** The choices a preview form sends: name[<module>], skip[<slice>], owner, new_owner, new_owner_email,
     *  setting[<slice>][<field>], filter[<slice>]; what is of no shape left out, text that is no UTF-8 too (no
     *  browser sends it) - the planner reports what is wrong. A filter is never left out: one of no text is
     *  NOT_TEXT, the filter of a slice left out goes with it. The form sends every name back: choose()
     *  renames only by another.
     *  @param array<mixed, mixed> $post */
    public static function fromPost(array $post): self
    {
        $long  = static fn(mixed $id): bool => is_string($id) && (bool) preg_match(self::ID_RE, strtolower($id));
        $text  = static fn(mixed $v): bool => is_string($v) && mb_check_encoding($v, 'UTF-8');
        $names = [];
        foreach ((array) ($post['name'] ?? []) as $id => $name) {
            if ($long($id) && $text($name) && (($name = trim($name)) !== '')) {
                $names[strtolower((string) $id)] = $name;
            }
        }
        $skip = [];
        foreach ((array) ($post['skip'] ?? []) as $id => $on) {
            if ($long($id) && ($on !== '') && ($on !== null)) {
                $skip[] = strtolower((string) $id);
            }
        }
        $settings = [];
        foreach ((array) ($post['setting'] ?? []) as $id => $fields) {
            foreach (is_array($fields) && $long($id) ? $fields : [] as $field => $value) {
                if ($text($value) && (trim($value) !== '') && preg_match(Validator::SETTING_FIELD_RE, (string) $field)) {
                    $settings[strtolower((string) $id)][(string) $field] = $value;
                }
            }
        }
        $filters = [];
        foreach ((array) ($post['filter'] ?? []) as $id => $value) {
            $value = $text($value) ? trim($value) : self::NOT_TEXT;
            if ($long($id) && ($value !== '') && !in_array(strtolower((string) $id), $skip, true)) {
                $filters[strtolower((string) $id)] = $value;
            }
        }
        $owner     = is_string($post['owner'] ?? null) && $long($post['owner']) ? strtolower($post['owner']) : '';
        $new_name  = is_string($post['new_owner'] ?? null) ? trim($post['new_owner']) : '';
        $new_email = is_string($post['new_owner_email'] ?? null) ? trim($post['new_owner_email']) : '';
        // the planner says what is too long or no text - a new owner asked for is never the list's instead
        $new_owner = ($new_name === '') ? null : ['name' => $text($new_name) ? $new_name : '', 'email' => $text($new_email) ? $new_email : ''];
        sort($skip);
        return new self($names, array_values(array_unique($skip)), $new_owner ? '' : $owner, $new_owner, $settings, $filters);
    }

    /** the choices without the values typed for some settings fields @param array<string, list<string>> $fields source slice id => field ids */
    public function withoutSettings(array $fields): self
    {
        $settings = $this->settings;
        foreach ($fields as $slice => $ids) {
            if (isset($settings[$slice])) {
                $settings[$slice] = array_diff_key($settings[$slice], array_flip($ids));
            }
        }
        return new self($this->names, $this->skip, $this->owner, $this->newOwner, array_filter($settings), $this->filters);
    }

    /** the choices as the run keeps them - key order fixed, so equal choices give an equal text */
    public function toJson(): string
    {
        $names    = $this->names;
        $filters  = $this->filters;
        $settings = array_map(static function (array $f): array {
            ksort($f);
            return $f;
        }, $this->settings);
        ksort($names);
        ksort($settings);
        ksort($filters);
        return json_encode(
            ['names' => $names, 'skip' => $this->skip, 'owner' => $this->owner, 'newOwner' => $this->newOwner, 'settings' => $settings, 'filters' => $filters],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @throws InvalidArgumentException not the text of toJson() */
    public static function fromJson(string $json): self
    {
        if ($json === '') {
            return new self();
        }
        try {
            $d = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('The choices are no JSON', 0, $e);
        }
        $owner = $d['newOwner'] ?? null;
        return new self(
            (array) ($d['names'] ?? []),
            array_values((array) ($d['skip'] ?? [])),
            (string) ($d['owner'] ?? ''),
            is_array($owner) ? ['name' => (string) ($owner['name'] ?? ''), 'email' => (string) ($owner['email'] ?? '')] : null,
            (array) ($d['settings'] ?? []),
            array_map('strval', (array) ($d['filters'] ?? [])),
        );
    }

    public function equals(self $other): bool
    {
        return $this->toJson() === $other->toJson();
    }

    public function skips(string $module): bool
    {
        return in_array(strtolower($module), $this->skip, true);
    }
}
