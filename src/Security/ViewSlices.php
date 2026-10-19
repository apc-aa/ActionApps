<?php

declare(strict_types=1);

namespace AA\Security;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\LogOnce;
use AA_Credentials;
use Closure;
use zids;

/**
 * The slices and items an item listing view called from the URL (view.php, jsview.php, discussion.php)
 * lists when its parameters name others than the view is set up for: set[<vid>]=slice_id-... or
 * slices-..., and the item ids of cmd[<vid>]=x|o|i-...; the same for slices[] of slice.php. Slices with
 * a reading password the request does not know are left out: GetItemContent() hides their fields, but
 * the ids, the count and the conditions of a listing would still tell about them. A page that needs
 * such a slice calls the view from its template ({view...}), where views are trusted; slice_pwd= in the
 * URL fits only a server-side include - in a link, AJAX or jsview.php the password would end up in the
 * page source, logs, Referer and history.
 *
 * What stays open: a protected slice's own view still answers the conditions of the URL (vid= or
 * cmd[]=v- picks such a view), and so does the main slice of slice.php, which the request chooses;
 * the single items of slice.php (x=, o=, sh_itm=, items[]); a condition on a remote field
 * (d-<field>@<slice>/<relation>) reaches a protected slice through a related public one; discussions
 * (discus views); the hit of a refused x- item; noitem from the URL, expanded as a template; ?debug
 * without a permission; the template commands - {view}, {view.php?...}, {ids}, {count} - which are
 * trusted, also when a template builds an inner view per item from URL values (als[], cmd[]=x-<vid>-url).
 */
final class ViewSlices
{
    /** log.type of the slices left out - selector vid:slice_id (or slice.php:slice_id), params the page without its query */
    public const EVENT = 'VIEW_DENY';

    /** @var ?array<string, int> ids of the protected slices as keys */
    private ?array $protected_memo = null;

    /** @var array<string, bool> slice id => the request knows its password */
    private array $known_memo = [];

    /**
     * @param Closure(): list<string>                  $protected   ids of the slices with a reading password
     * @param Closure(string): bool                    $known       the request knows the reading password of the slice
     * @param Closure(list<string>): list<string>      $stored      which of the ids are stored, not deleted slices
     * @param Closure(zids): array<int|string, string> $item_slices item id (short, or long in lower case) => its slice
     * @param Closure(string): mixed                   $log         writes a log row with the selector
     */
    public function __construct(
        private readonly Closure $protected,
        private readonly Closure $known,
        private readonly Closure $stored,
        private readonly Closure $item_slices,
        private readonly Closure $log,
    ) {}

    public static function forRequest(): self
    {
        return new self(
            static fn(): array => AA::Contentcache()->get_result([self::class, 'protectedFromDb']),
            static fn(string $slice_id): bool => self::knownToRequest($slice_id),
            static fn(array $ids): array => self::storedFromDb($ids),
            static fn(zids $zids): array => AA::Contentcache()->get_result([self::class, 'itemSlicesFromDb'], [$zids->onetype(), $zids->getArray()]),
            static fn(string $selector): bool => LogOnce::daily(self::EVENT, $selector, ProtectionLog::requested()),
        );
    }

    /**
     * The slice, slices and items the view shows. A refused slice_id leaves the view's own slice with
     * no items, so the page looks like an empty listing whether the slice is protected or does not exist.
     * @param string    $own_slice_id the view's own slice, as unpack_id() writes it
     * @param string    $slice_id     set[]=slice_id-..., '' when not given
     * @param ?string[] $slices       set[]=slices-...
     * @param ?zids     $zids         ids the view is asked to show
     * @return array{string, ?list<string>, ?zids} slice, slices, ids - an empty zids shows nothing
     */
    public function narrow(int $vid, string $own_slice_id, string $slice_id, ?array $slices, ?zids $zids): array
    {
        $nothing = false;
        if ($slice_id === '') {
            $slice_id = $own_slice_id;
        } else {
            $allowed  = $this->slices($vid, $own_slice_id, [$slice_id]);
            $slice_id = $allowed[0] ?? $own_slice_id;
            $nothing  = !$allowed;
        }
        if (!is_null($slices)) {
            $slices  = $this->slices($vid, $own_slice_id, $slices);
            $nothing = $nothing || !$slices;
        }
        if ($nothing) {
            return [$slice_id, $slices, new zids()];
        }
        return [$slice_id, $slices, is_null($zids) ? null : $this->items($vid, $own_slice_id, $zids)];
    }

    /**
     * The requested slices that may be listed: the own slice, stored slices without a reading password
     * and protected ones whose password the request knows. A protected one left out is logged once a
     * day; any other id is left out silently, so only real slices reach the log.
     * @param int|string $lister       view id, or the script
     * @param string     $own_slice_id as unpack_id() writes it
     * @param string[]   $requested
     * @return list<string>
     */
    public function slices(int|string $lister, string $own_slice_id, array $requested): array
    {
        // ids from the request may be upper case - compare and log them the way unpack_id() writes them
        $ids = array_values(array_unique(array_map(static fn($id): string => strtolower((string) $id), $requested)));
        if (!array_diff($ids, [$own_slice_id])) {
            return $ids;
        }
        $protected = $this->protectedIds();
        $open      = array_values(array_filter($ids, static fn(string $id): bool => ($id !== $own_slice_id) && !isset($protected[$id])));
        $stored    = $open ? array_flip(($this->stored)($open)) : [];
        $allowed   = [];
        foreach ($ids as $slice_id) {
            if (($slice_id === $own_slice_id) || isset($stored[$slice_id]) || (isset($protected[$slice_id]) && $this->knows($lister, $slice_id))) {
                $allowed[] = $slice_id;
            }
        }
        return $allowed;
    }

    /**
     * The ids without the items of protected slices other than the own one; an id of no item goes too.
     * The items are looked up only when another slice of the install has a reading password.
     */
    public function items(int|string $lister, string $own_slice_id, zids $zids): zids
    {
        $protected = $this->protectedIds();
        unset($protected[$own_slice_id]);
        if ($zids->isEmpty() || !$protected) {
            return $zids;
        }
        $item_slices = ($this->item_slices)($zids);
        // the keys of the lookup: short ids as numbers, long ids in lower case
        $keys = $zids->use_short_ids() ? array_map('intval', $zids->getArray()) : array_map('strtolower', (array) $zids->longids());
        $kept = [];
        foreach ($zids->getArray() as $i => $id) {
            $slice_id = $item_slices[$keys[$i] ?? ''] ?? null;
            if (!is_null($slice_id) && (!isset($protected[$slice_id]) || $this->knows($lister, $slice_id))) {
                $kept[] = $id;
            }
        }
        return (count($kept) === $zids->count()) ? $zids : new zids($kept, $zids->onetype());
    }

    /** @return array<string, int> */
    private function protectedIds(): array
    {
        return $this->protected_memo ??= array_flip(($this->protected)());
    }

    /** whether the request knows the password of the protected slice; logs it once when not */
    private function knows(int|string $lister, string $slice_id): bool
    {
        if (!isset($this->known_memo[$slice_id])) {
            $this->known_memo[$slice_id] = ($this->known)($slice_id);
            if (!$this->known_memo[$slice_id]) {
                ($this->log)(ParamImplode([(string) $lister, $slice_id]));
            }
        }
        return $this->known_memo[$slice_id];
    }

    /** AA_Slice keeps the reading password hashed, as AA_Credentials compares it - never read the column itself */
    public static function knownToRequest(string $slice_id): bool
    {
        return AA_Credentials::singleton()->checkSlice($slice_id);
    }

    /**
     * Ids of the slices with a reading password, deleted ones too - their items are still there.
     * NOT LIKE '' keeps a password of spaces, which <> '' would skip and AA_Slice takes for one;
     * '0', which AA_Slice ignores, is in the list, but knownToRequest() lets it pass.
     * @return list<string>
     */
    public static function protectedFromDb(): array
    {
        return DB_AA::select('', 'SELECT LOWER(HEX(`id`)) FROM `slice`', [['reading_password', '', 'NOT LIKE']]);
    }

    /**
     * @param list<string> $ids
     * @return list<string> the ids of stored, not deleted slices - no type is an old slice, as AA_Slice::isStoredSlice() reads it
     */
    private static function storedFromDb(array $ids): array
    {
        $types = DB_AA::select(['unpackid' => 'type'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `type` FROM `module`', [['id', $ids, 'l'], ['deleted', 0, 'i']]);
        return array_map('strval', array_keys(array_filter($types, static fn($type): bool => (((string) $type) ?: 'S') === 'S')));
    }

    /**
     * Only the item table is read and only the slice is used, so the reading password need not apply.
     * @param list<int|string> $ids
     * @return array<int|string, string> item id (short, or long in lower case) => its slice
     */
    public static function itemSlicesFromDb(string $type, array $ids): array
    {
        $slices = [];
        foreach (GetItemContent(new zids($ids, $type), false, true, ['short_id........']) as $key => $content) {
            $slices[$key] = (string) $content['u_slice_id......'][0]['value'];
        }
        return $slices;
    }
}
