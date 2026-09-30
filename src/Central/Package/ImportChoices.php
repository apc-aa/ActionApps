<?php

declare(strict_types=1);

namespace AA\Central\Package;

use InvalidArgumentException;
use JsonException;

/** What the admin chose in the preview of an install besides "Into": new names of the modules, slices
 *  left out, the owner of the new modules (one of this AA's or a new one) and values of the settings
 *  fields of the slices. The run keeps them (InstallRun), so the write plans exactly what the preview showed.
 *  All strings are request text - the planner checks them as package text (charset, length, lint). */
final readonly class ImportChoices
{
    /** longest name of a new owner or its e-mail - slice_owner columns, char(80) */
    public const OWNER_MAX = 80;

    /**
     * @param array<string, string>                $names    source module id => its new name (trimmed, not empty)
     * @param list<string>                         $skip     source slice ids left out
     * @param string                               $owner    slice_owner id of the new modules; '' = the planner's default
     * @param array{name: string, email: string}|null $newOwner an owner the write creates instead of $owner
     * @param array<string, array<string, string>> $settings source slice id => settings field id => value typed
     *                                                       ('' fields left out: the value of the app stays)
     */
    public function __construct(
        public array $names = [],
        public array $skip = [],
        public string $owner = '',
        public ?array $newOwner = null,
        public array $settings = [],
    ) {
        foreach ([...array_keys($names), ...$skip, ...array_keys($settings)] as $id) {
            if (!preg_match('/^[0-9a-f]{32}\z/', (string) $id)) {
                throw new InvalidArgumentException('A module of the choices is a long id, lower case');
            }
        }
        if (($owner !== '') && !preg_match('/^[0-9a-f]{32}\z/', $owner)) {
            throw new InvalidArgumentException('The owner is a long id, lower case');
        }
        foreach ($settings as $fields) {
            foreach (array_keys($fields) as $field) {
                if (!preg_match('/^_[\w.]{15}\z/', (string) $field)) {
                    throw new InvalidArgumentException('A settings field id starts with _ and has 16 characters');
                }
            }
        }
    }

    /** The choices a preview form sends: name[<module>], skip[<slice>], owner, new_owner, new_owner_email,
     *  setting[<slice>][<field>]; what is of no shape left out - the planner reports what is wrong
     *  @param array<mixed, mixed> $post */
    public static function fromPost(array $post): self
    {
        $long  = static fn(mixed $id): bool => is_string($id) && (bool) preg_match('/^[0-9a-f]{32}\z/i', $id);
        $names = [];
        foreach ((array) ($post['name'] ?? []) as $id => $name) {
            if ($long($id) && is_string($name) && (($name = trim($name)) !== '')) {
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
                if (is_string($value) && (trim($value) !== '') && preg_match('/^_[\w.]{15}\z/', (string) $field)) {
                    $settings[strtolower((string) $id)][(string) $field] = $value;
                }
            }
        }
        $owner     = is_string($post['owner'] ?? null) && $long($post['owner']) ? strtolower($post['owner']) : '';
        $new_name  = is_string($post['new_owner'] ?? null) ? trim($post['new_owner']) : '';
        $new_email = is_string($post['new_owner_email'] ?? null) ? trim($post['new_owner_email']) : '';
        $new_owner = ($new_name === '') ? null : ['name' => $new_name, 'email' => $new_email];      // the planner says what is too long
        sort($skip);
        return new self($names, array_values(array_unique($skip)), $new_owner ? '' : $owner, $new_owner, $settings);
    }

    /** the choices as the run keeps them - key order fixed, so equal choices give an equal text */
    public function toJson(): string
    {
        $names    = $this->names;
        $settings = array_map(static function (array $f): array {
            ksort($f);
            return $f;
        }, $this->settings);
        ksort($names);
        ksort($settings);
        return json_encode(
            ['names' => $names, 'skip' => $this->skip, 'owner' => $this->owner, 'newOwner' => $this->newOwner, 'settings' => $settings],
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
