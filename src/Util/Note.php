<?php

declare(strict_types=1);

namespace AA\Util;

use AA;
use AA_Condition;
use AA_Object;
use AA_Property;
use AA_Set;

/** A note attached to anything in AA that has an address: an admin page, a
 *  view, a column, a State Check finding. Stored as an AA_Object (object_*
 *  tables), owned by the module it was written in. A resolved note on a
 *  finding is what hides the finding on State Check (see hides()).
 *
 *  Resource addresses, one string so a prefix can be searched:
 *    page:{module}:{script}      admin/se_fields.php on module X
 *    view:{module}:{vid}
 *    col:{module}:{vid}:{column}
 *    finding:{Check}:{key}       Finding::$check + Finding::$key
 */
class Note extends AA_Object implements NamedInterface
{
    public const STATE_VALID = 'valid';
    public const STATE_RESOLVED = 'resolved';
    public const STATE_DELETED = 'deleted';
    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_ME = 'me';
    /** what a note's HTML may keep - links get only a safe href back (sanitizeHtml) */
    public const ALLOWED_TAGS = '<a><b><strong><i><em><u><s><code><pre><br><p><ul><ol><li><blockquote>';
    /** the resolve choices offered on State Check, in this order */
    public const UNTIL_CHOICES = ['today', 'week', 'month', 'forever', 'date'];

    public $resource = '';
    public $created = 0;
    public $author = '';
    public $audience = self::AUDIENCE_ALL;
    public $text = '';
    public $state = self::STATE_VALID;
    /** unixtime the note (a resolution) holds until; 0 = forever */
    public $until = 0;

    public static function name(): string
    {
        return _m('Note');
    }

    public static function description(): string
    {
        return _m('A note on an admin page, a view or a State Check finding');
    }

    public static function getClassProperties(): array
    {
        //                                     id          name              type      multi  persist validator, required
        return [
            'resource' => new AA_Property('resource', _m('Resource'), 'string', false, true, 'string', true),
            'created'  => new AA_Property('created', _m('Created'), 'int', false, true, 'int', true),
            'author'   => new AA_Property('author', _m('Author'), 'string', false, true, 'string', true),
            'audience' => new AA_Property('audience', _m('For'), 'string', false, true, ['enum', [self::AUDIENCE_ALL => _m('everyone'), self::AUDIENCE_ME => _m('me only')]], true),
            'text'     => new AA_Property('text', _m('Text'), 'text', false, true, 'text', false),
            'state'    => new AA_Property('state', _m('State'), 'string', false, true, ['enum', [self::STATE_VALID => _m('valid'), self::STATE_RESOLVED => _m('resolved'), self::STATE_DELETED => _m('deleted')]], true),
            'until'    => new AA_Property('until', _m('Until'), 'int', false, true, 'int', false),
        ];
    }

    /** the address of a resource: Note::resource('finding', $check, $key) */
    public static function resource(string $kind, string ...$parts): string
    {
        return $kind . ':' . implode(':', $parts);
    }

    /** the address of the admin page being rendered: page:{module}:{script relative to the install} */
    public static function pageResource(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $base   = (string) constant('AA_INSTAL_PATH');
        if (str_starts_with($script, $base)) {
            $script = substr($script, strlen($base));
        }
        return self::resource('page', (string) AA::$module_id, $script);
    }

    /** writes a new note for the current user and returns it */
    public static function create(string $owner, string $resource, string $text, string $audience = self::AUDIENCE_ALL, string $state = self::STATE_VALID, int $until = 0): self
    {
        $note = new self();
        $note->setOwnerId($owner);
        $note->resource = $resource;
        $note->created  = now();
        $note->author   = AuthGetUid();
        $note->audience = in_array($audience, [self::AUDIENCE_ALL, self::AUDIENCE_ME], true) ? $audience : self::AUDIENCE_ALL;
        $note->text     = $text;
        $note->state    = $state;
        $note->until    = $until;
        $note->save();
        return $note;
    }

    /** notes on one resource the current user may see, newest first; deleted ones left out
     * @return self[]
     */
    public static function forResource(string $resource): array
    {
        return self::query(new AA_Condition('resource', '==', $resource));
    }

    /** the newest resolved note per finding resource, keyed by resource
     * @return array<string, self>
     */
    public static function resolved(): array
    {
        $by_resource = [];
        foreach (self::query(new AA_Condition('state', '==', self::STATE_RESOLVED)) as $note) {
            $by_resource[$note->resource] ??= $note;   // query() is newest first
        }
        return $by_resource;
    }

    /** @return self[] newest first, visible to the current user, not deleted */
    private static function query(AA_Condition $condition): array
    {
        $set = new AA_Set();
        $set->addCondition($condition);
        $uid   = AuthGetUid();
        $notes = [];
        foreach (AA_Object::querySet(self::class, $set) as $id) {
            $note = AA_Object::load($id, self::class);
            if ($note instanceof self && $note->state !== self::STATE_DELETED && $note->isVisibleTo($uid)) {
                $notes[] = $note;
            }
        }
        usort($notes, static fn(self $a, self $b) => (int) $b->created <=> (int) $a->created);
        return $notes;
    }

    public function isVisibleTo(string $uid): bool
    {
        return ($this->audience === self::AUDIENCE_ALL) || ($this->author === $uid);
    }

    /** Does this resolution still hide its finding? A state finding (last = 0,
     *  "cron is dead") is hidden while the resolution holds; an event finding
     *  (last = the newest occurrence) also stays hidden as long as nothing
     *  happened after it was resolved.
     * @param int $last  Finding::$last
     */
    public function hides(int $last, int $now): bool
    {
        return self::hidesRule((int) $this->created, (int) $this->until, $last, $now);
    }

    public static function hidesRule(int $created, int $until, int $last, int $now): bool
    {
        if (($until === 0) || ($now < $until)) {
            return true;
        }
        return ($last > 0) && ($last <= $created);
    }

    /** the "until" of a resolution from the form choice: today (default), week,
     *  month, forever (0) or a date - the whole day, so the finding comes back
     *  the next morning, not at midnight of the day picked */
    public static function untilFromChoice(string $choice, string $date, int $now): int
    {
        $end_of = static fn(int $t) => (int) strtotime('tomorrow', $t);
        return match ($choice) {
            'forever' => 0,
            'week'    => $end_of($now + 7 * 86400),
            'month'   => $end_of($now + 31 * 86400),
            'date'    => ($t = strtotime($date)) ? $end_of($t) : $end_of($now),
            default   => $end_of($now),
        };
    }

    /** Only the tags of ALLOWED_TAGS survive and they lose every attribute; a
     *  link keeps its href when it is http(s), mailto or site-relative. Notes
     *  are written by any editor and read by superadmins, so this is the trust
     *  boundary, not a nicety.
     */
    public static function sanitizeHtml(string $html): string
    {
        $html = strip_tags($html, self::ALLOWED_TAGS);
        return (string) preg_replace_callback('/<(\/?)([a-z][a-z0-9]*)\b([^>]*)>/i', static function (array $m): string {
            [, $close, $tag, $attrs] = $m;
            $tag = strtolower($tag);
            if (($close !== '') || ($tag !== 'a')) {
                return "<$close$tag>";
            }
            if (preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $h)) {
                $href = trim(html_entity_decode($h[1] ?: ($h[2] ?? '') ?: ($h[3] ?? '')));
                if (preg_match('#^(https?:|mailto:|/[^/])#i', $href)) {
                    return '<a href="' . safe($href) . '" rel="noopener">';
                }
            }
            return '<a>';
        }, $html);
    }

    public function textHtml(): string
    {
        return self::sanitizeHtml((string) $this->text);
    }

    public function authorName(): string
    {
        return (string) AA::$perm->getUserName($this->author);
    }

    public function setState(string $state): void
    {
        $this->state = $state;
        $this->update(['state']);
    }
}
