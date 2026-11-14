<?php

declare(strict_types=1);

namespace AA\Mailbulk;

use AA;
use AA_Aliases;
use AA_Collection;
use AA_Item;
use AA_Module_Alerts;
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

    private const TITLE_LENGTH = 250;

    /** false = not read yet, null = the item is gone */
    private ItemContent|false|null $item = false;
    /** @var ?array<string, array<string, string>> */
    private ?array $itemAliases = null;
    /** @var ?array<string, string> reader long id => address of the selection */
    private ?array $addresses = null;
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
        $unknown = $this->list->unknownCondsFields($this->mailto());
        if ($unknown) {
            // validReaders() would find nobody, without a word
            return _m('%1 names fields the slice of the readers lacks or that cannot be checked: %2', [self::MAILTO, implode(', ', $unknown)]);
        }
        return null;
    }

    public function title(): string
    {
        $title = html_entity_decode(strip_tags($this->expand('_#MAILSUBJ', $this->withoutReader())), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $title)), 0, self::TITLE_LENGTH);
    }

    public function selection(): array
    {
        return array_keys($this->addresses());
    }

    public function messageFor(string $readerId, bool $test = false): ?Message
    {
        $reader = $this->contents([$readerId])[$readerId] ?? null;
        if (($reader === null) || !$this->list->isValidReader($reader)) {
            return null;
        }
        $code  = $test ? self::TEST_CODE : (string) $reader->getValue(FIELDID_ACCESS_CODE);
        $links = $this->list->readerLinks($code);
        $item  = $this->itemWith($readerId, $links['unsubscribe'], $links['form']);
        // without the code the link would unsubscribe nobody
        $headers = ($test || ($code === '')) ? [] : AA_Collection::unsubscribeHeaders($links['unsubscribe']);
        return new Message(
            trim((string) $reader->getValue(FIELDID_EMAIL)),
            trim($this->expand('_#MAILSUBJ', $item)),
            $this->expand('_#MAILBODY', $item),
            trim($this->expand('_#MAILFROM', $item)),
            $headers,
        );
    }

    /** The addresses a test mail goes to: _#MAILTEST split at commas, else $userEmail.
     *  @return list<string> */
    public function testAddresses(string $userEmail): array
    {
        $given = isset($this->aliases()[self::MAILTEST]) ? explode(',', $this->expand(self::MAILTEST, $this->withoutReader())) : [];
        $valid = array_values(array_filter(array_map('trim', $given), static fn(string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        if ($valid) {
            return $valid;
        }
        return (filter_var($userEmail, FILTER_VALIDATE_EMAIL) !== false) ? [$userEmail] : [];
    }

    /** the reader whose mail a test sends: the one with $address, else the first of the selection; null = none */
    public function testReader(string $address): ?string
    {
        $addresses = $this->addresses();
        $found     = array_search(strtolower(trim($address)), $addresses, true);
        return ($found !== false) ? (string) $found : (array_key_first($addresses) ?? null);
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

    /** the language of the Alerts module, as its own mails have it */
    protected function lang(): string
    {
        if ($this->lang === null) {
            $module     = AA_Module_Alerts::getOwnModule($this->list->getModuleId());
            $this->lang = ($module instanceof AA_Module_Alerts) ? substr($module->getLang(), 0, 2) : '';
        }
        return $this->lang;
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

    /** the item with the aliases of one reader - a new object for each, so nothing of one reader stays for the next */
    private function itemWith(string $readerId, string $unsubscribe, string $form): AA_Item
    {
        $item = $this->item() ?? throw new \RuntimeException('The item to send is gone');
        $own  = (new AA_Aliases())
            ->addTextAlias('_#MAILTOID', $readerId)
            ->addTextAlias('_#MAILUNSB', $unsubscribe)
            ->addTextAlias('_#MAILPREF', $form)
            ->addTextAlias('_#MAILOPEN', '');
        return new AA_Item($item, array_merge($this->aliases(), $own->getArray()));
    }

    private function withoutReader(): AA_Item
    {
        return $this->itemWith('', '', '');
    }

    /** the conds of _#MAILTO__; '' = all valid readers */
    private function mailto(): string
    {
        return isset($this->aliases()[self::MAILTO]) ? trim($this->expand(self::MAILTO, $this->withoutReader())) : '';
    }

    /** @return array<string, string> long id => lower-case address of each valid reader the mail goes to, the first one of an address only */
    private function addresses(): array
    {
        if ($this->addresses === null) {
            $ids      = $this->list->validReaders($this->mailto())->longids();
            $contents = $ids ? $this->contents($ids, [FIELDID_EMAIL]) : [];
            $first    = [];
            foreach ($ids as $id) {
                $address = strtolower(trim((string) ($contents[$id] ?? null)?->getValue(FIELDID_EMAIL)));
                if ($address !== '') {
                    $first[$address] ??= $id;
                }
            }
            $this->addresses = array_flip($first);
        }
        return $this->addresses;
    }

    /** $alias expanded over $item in the language of the list - only while expanding, a queue process goes on with other tasks */
    private function expand(string $alias, AA_Item $item): string
    {
        $lang = $this->lang();
        if ($lang === '') {
            return AA::Un($alias, '', $item);
        }
        $old = $GLOBALS['apc_state']['xlang'] ?? null;
        $GLOBALS['apc_state']['xlang'] = $lang;
        try {
            return AA::Un($alias, '', $item);
        } finally {
            if ($old === null) {
                unset($GLOBALS['apc_state']['xlang']);
            } else {
                $GLOBALS['apc_state']['xlang'] = $old;
            }
        }
    }
}
