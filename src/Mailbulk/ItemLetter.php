<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA;
use AA_Aliases;
use AA_Collection;
use AA_Item;
use AA_Slice;
use ItemContent;
use zids;

/**
 * The mail of an item to the readers of a list, from the aliases _#MAIL* of the item's slice. Each mail is
 * expanded over the item with the reader's own aliases added, so a template reads the reader through
 * {item:{_#MAILTOID}:...}. The item is read live, once per letter; the caller opens the slices.
 */
class ItemLetter implements Letter
{
    public const REQUIRED = ['_#MAILSUBJ', '_#MAILBODY', '_#MAILFROM'];

    public const MAILTO = '_#MAILTO__';
    public const MAILTEST = '_#MAILTEST';

    /** the code of no reader - the links of a test mail unsubscribe nobody */
    public const TEST_CODE = 'ABCDE';

    /** a test goes to this many addresses at most */
    public const TEST_ADDRESSES = 10;

    /** readers whose addresses one read asks for */
    private const CHUNK = 5000;

    /** false = not read yet, null = the item is gone */
    private ItemContent|false|null $item = false;
    /** @var ?array<string, array<string, string>> */
    private ?array $itemAliases = null;
    /** @var ?array<string, string> reader long id => address of the selection */
    private ?array $addresses = null;
    private ?AA_Item $withoutReader = null;
    private ?string $mailto = null;
    private ?string $lang = null;

    public function __construct(
        protected readonly AA_Collection $list,
        protected readonly string $itemId,
        protected readonly string $trackBase = '',
    ) {}

    public function problem(): ?string
    {
        $item = $this->item();
        if (($item === null) || !$item->isActive()) {
            return _m('The item does not exist or is not active');
        }
        if (!($this->list->getReaderSlice() instanceof AA_Slice)) {
            return _m('The list has no slice of readers');
        }
        $missing = array_diff(self::REQUIRED, array_keys($this->aliases()));
        if ($missing) {
            return _m('The slice of the item lacks the aliases %1', [implode(', ', $missing)]);
        }
        if (self::fromAddress($this->from($this->withoutReader())) === null) {
            return _m('%1 gives no valid sender address', ['_#MAILFROM']);
        }
        $dropped = $this->list->droppedConds($this->mailto());
        if ($dropped) {
            // validReaders() would find nobody, without a word
            return _m('The readers cannot be selected - the slice of the readers cannot check these conditions (of %1 or the confirmation of the readers): %2', [self::MAILTO, implode(', ', $dropped)]);
        }
        return null;
    }

    public function title(): string
    {
        return mb_substr(self::plain($this->expand('_#MAILSUBJ', $this->withoutReader())), 0, Campaign::TITLE_LENGTH);
    }

    public function selection(): array
    {
        return array_keys($this->addresses());
    }

    public function messageFor(string $readerId, bool $test = false): ?Message
    {
        $reader = $this->contents([$readerId])[$readerId] ?? null;
        if (($reader === null) || !$this->list->isValidReader($reader) || !self::isAddress(trim((string) $reader->getValue(FIELDID_EMAIL)))) {
            return null;
        }
        $code  = $test ? self::TEST_CODE : (string) $reader->getValue(FIELDID_ACCESS_CODE);
        $links = $this->list->readerLinks($code);
        // a test must count nothing; the base is '' when the list measured nothing at "send" - all mails of a run alike
        $track = !$test && ($this->trackBase !== '');
        $image = $track ? $this->openImage($readerId) : '';
        $item  = $this->itemWith($readerId, $links['unsubscribe'], $links['form'], $image);
        // without the code the link would unsubscribe nobody
        $headers = ($test || ($code === '')) ? [] : AA_Collection::unsubscribeHeaders($links['unsubscribe']);
        $html    = $this->expand('_#MAILBODY', $item);
        if ($track) {
            $html = $this->tracked($html, $readerId, $code, [$links['unsubscribe'], $links['form']], $image);
        }
        return new Message(
            trim((string) $reader->getValue(FIELDID_EMAIL)),
            self::plain($this->expand('_#MAILSUBJ', $item)),
            $html,
            $this->from($item),
            $headers,
        );
    }

    /** The addresses a test mail goes to: the valid ones of _#MAILTEST split at commas, else $userEmail; at most
     *  TEST_ADDRESSES.
     *  @return list<string> */
    public function testAddresses(string $userEmail): array
    {
        $given = isset($this->aliases()[self::MAILTEST]) ? explode(',', $this->expand(self::MAILTEST, $this->withoutReader())) : [];
        $valid = array_values(array_filter(array_map('trim', $given), self::isAddress(...)));
        if ($valid) {
            return array_slice($valid, 0, self::TEST_ADDRESSES);
        }
        return self::isAddress($userEmail) ? [$userEmail] : [];
    }

    /** the reader whose mail a test sends: the one with $address, else the first of the selection; null = none */
    public function testReader(string $address): ?string
    {
        $addresses = $this->addresses();
        $found     = array_search(strtolower(trim($address)), $addresses, true);
        return ($found !== false) ? (string) $found : array_key_first($addresses);
    }

    /** @param list<string> $ids long ids
     *  @param list<string> $fields only these fields; [] = all
     *  @return array<string, ItemContent> long id => content */
    protected function contents(array $ids, array $fields = []): array
    {
        $result = [];
        foreach (GetItemContent(new zids($ids, 'l'), false, false, $fields) as $id => $content4id) {
            $result[(string) $id] = new ItemContent($content4id);
        }
        return $result;
    }

    /** @return array<string, array<string, string>> alias => definition */
    protected function aliasesOf(string $sliceId): array
    {
        $slice = AA_Slice::getOwnModule($sliceId);
        return ($slice instanceof AA_Slice) ? (array) $slice->aliases() : [];
    }

    private function item(): ?ItemContent
    {
        if ($this->item === false) {
            $this->item = $this->contents([$this->itemId])[$this->itemId] ?? null;
        }
        return $this->item;
    }

    /** @return array<string, array<string, string>> the aliases of the item's slice */
    private function aliases(): array
    {
        if ($this->itemAliases === null) {
            $item              = $this->item();
            $this->itemAliases = ($item === null) ? [] : $this->aliasesOf($item->getSliceID());
        }
        return $this->itemAliases;
    }

    /** the item with the aliases of one reader - the commands caching their output per item tell the readers apart
     *  by these text aliases (AA_Item::getCacheId()) */
    private function itemWith(string $readerId, string $unsubscribe, string $form, string $open = ''): AA_Item
    {
        $item = $this->item() ?? throw new \RuntimeException('The item to send is gone');
        $own  = (new AA_Aliases())
            ->addTextAlias('_#MAILTOID', $readerId)
            ->addTextAlias('_#MAILUNSB', $unsubscribe)
            ->addTextAlias('_#MAILPREF', $form)
            ->addTextAlias('_#MAILOPEN', $open);
        return new AA_Item($item, array_merge($this->aliases(), $own->getArray()));
    }

    /** the image counting the opening, '' when no token can be made */
    private function openImage(string $readerId): string
    {
        $url = $this->hitUrl($readerId, TrackToken::OPEN);
        return ($url === null) ? '' : '<img src="' . safe($url) . '" width="1" height="1" alt="">';
    }

    /** $html with its links tracked and the image added, unless _#MAILOPEN placed it already
     *  @param list<string> $skip */
    private function tracked(string $html, string $readerId, string $code, array $skip, string $image): string
    {
        $html = Links::track($html, fn(string $url): string => self::personal($url, $readerId, $code) ? $url : ($this->hitUrl($readerId, TrackToken::CLICK, $url) ?? $url), $skip);
        if (($image === '') || str_contains($html, $image)) {
            return $html;
        }
        $end = strripos($html, '</body>');
        return ($end === false) ? $html . $image : substr_replace($html, $image, $end, 0);
    }

    /** a link of the reader's own (unsubscribe or form with more parameters, a link built from _#MAILTOID) - once
     *  tracked, its address with the reader's code or id would land in the statistics, which editors see */
    private static function personal(string $url, string $readerId, string $code): bool
    {
        if (($readerId !== '') && (stripos($url, $readerId) !== false)) {
            return true;
        }
        if ($code === '') {
            return false;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $found = false;
        array_walk_recursive($query, static function (mixed $value) use ($code, &$found): void {
            $found = $found || ($value === $code);
        });
        return $found;
    }

    /** the tracked address under the base the run keeps - a step may run in a tree with another path;
     *  null for a link a token cannot carry, which then stays as it is. It ends in the token, never in an
     *  image extension htmlMimeMail would embed. */
    private function hitUrl(string $readerId, string $kind, string $url = ''): ?string
    {
        try {
            $token = TrackToken::make($this->list->getId(), $this->itemId, $readerId, $kind, $url);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return $this->trackBase . 'api/index.php?_path=v1/mail/hit&t=' . $token;
    }

    private function withoutReader(): AA_Item
    {
        return $this->withoutReader ??= $this->itemWith('', '', '');
    }

    /** the conds of _#MAILTO__; '' = all valid readers */
    private function mailto(): string
    {
        return $this->mailto ??= isset($this->aliases()[self::MAILTO]) ? trim($this->expand(self::MAILTO, $this->withoutReader())) : '';
    }

    /** _#MAILFROM as the mail header wants it - an alias printing HTML gives &amp; and &lt; */
    private function from(AA_Item $item): string
    {
        return trim(html_entity_decode($this->expand('_#MAILFROM', $item), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** the address of "Name <address>" or of a bare address; null = none valid */
    private static function fromAddress(string $from): ?string
    {
        if (preg_match('/^[^<>\r\n]*<([^<>]*)>\z/', $from, $m)) {
            $from = trim($m[1]);
        }
        return self::isAddress($from) ? $from : null;
    }

    /** one valid address - the rule of the test addresses of Alerts */
    private static function isAddress(string $address): bool
    {
        return (AA_Collection::testEmails($address) ?? []) !== [];
    }

    /** text of the HTML a template gives, on one line - for the subject and the overviews */
    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // the /u pattern refuses invalid UTF-8 - the text must not get lost for that
        return trim(preg_replace('/\s+/u', ' ', $text) ?? preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /** @return array<string, string> long id => lower-case address of each valid reader the mail goes to, the first one of an address only;
     *  readers without one valid address are left out */
    private function addresses(): array
    {
        if ($this->addresses === null) {
            $first = [];
            foreach (array_chunk($this->list->validReaders($this->mailto())->longids(), self::CHUNK) as $ids) {
                $contents = $this->contents($ids, [FIELDID_EMAIL]);
                foreach ($ids as $id) {
                    $address = strtolower(trim((string) ($contents[$id] ?? null)?->getValue(FIELDID_EMAIL)));
                    // a list of addresses or an error text in place of the address gets nothing
                    if (self::isAddress($address)) {
                        $first[$address] ??= $id;
                    }
                }
            }
            $this->addresses = array_flip($first);
        }
        return $this->addresses;
    }

    /** $alias expanded over $item in the language of the list - only while expanding, a queue process goes on with other tasks */
    private function expand(string $alias, AA_Item $item): string
    {
        return AA_Collection::inLang($this->lang ??= $this->list->lang(), static fn(): string => AA::Un($alias, '', $item));
    }
}
