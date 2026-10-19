<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Isds\Registry\Box;
use AA_Field;

/**
 * The settings of one mass sending - one data box it sends from - as {datovkabulk:page} gets them
 * from the template: checked against the slices, the site and misc/datovka/datovka_conf.php each time, never
 * stored. Holds no secret. In the template a JSON object, as {jsonasoc:...} makes it:
 *     {jsonasoc:campaign_slice:<id>:message_slice:<id>:source_slice:<id>
 *       :types:{jsonasoc:mesto:Cities:mestocast:City districts}:default_types:["mesto"]
 *       :member_field:switch.........9:test_box:abc1234:max_recipients:1000:environment:test}
 * {jsonasoc} gives a nested object or list as its JSON text, so types, default_types and test_box
 * take that text too; one type or box may be a plain text. An empty value is a value not set.
 * Required are the three slices and types. Who may send is a matter of AA rights (Access), not of
 * the settings. environment can only narrow to the test ISDS - production comes from datovka_conf.php.
 */
final readonly class Setup
{
    public const MAX_RECIPIENTS     = 5000;
    public const DEFAULT_RECIPIENTS = 1000;

    /** what a template may set - a name fits object_text.property, 16 bytes */
    public const SETTINGS = [
        'campaign_slice', 'message_slice', 'source_slice', 'types', 'default_types', 'member_field',
        'test_box', 'max_recipients', 'environment', 'letterhead',
    ];

    /**
     * @param string                   $site         the site whose page sends; ids in small letters
     * @param array<array-key, string> $types        item type => its label, as offered; PHP keeps a type of digits as an int key
     * @param list<string>             $defaultTypes the types chosen at first
     * @param string                   $memberField  the field of the source that tells a member; '' = none
     * @param list<string>             $testBoxes    the only boxes a test message goes to
     * @param string                   $environment  Account::TEST or Account::PRODUCTION, as datovka_conf.php binds it
     * @param string                   $senderBox    the box a hosted filing service sends from; '' = ISDS tells it
     *                                               when asked, which a page never does
     * @param string                   $letterhead   plain text above every letter, one line a row - the sender,
     *                                               an address; HTML in it shows as written
     */
    private function __construct(
        public string $site,
        public string $campaignSlice,
        public string $messageSlice,
        public string $sourceSlice,
        public array $types,
        public array $defaultTypes,
        public string $memberField,
        public array $testBoxes,
        public int $maxRecipients,
        public string $environment,
        public string $senderBox,
        public string $letterhead,
    ) {}

    /**
     * Settings as a checked Setup gave them - never from the values of a template, only from what
     * fromTemplate() produced before (a queued run keeps them); taken as they are.
     * @param array<array-key, string> $types
     * @param list<string>             $defaultTypes
     * @param list<string>             $testBoxes
     */
    public static function fromSnapshot(
        string $site,
        string $campaignSlice,
        string $messageSlice,
        string $sourceSlice,
        array $types,
        array $defaultTypes = [],
        string $memberField = '',
        array $testBoxes = [],
        int $maxRecipients = self::DEFAULT_RECIPIENTS,
        string $environment = Account::TEST,
        string $senderBox = '',
        string $letterhead = '',
    ): self {
        return new self($site, $campaignSlice, $messageSlice, $sourceSlice, $types, $defaultTypes, $memberField, $testBoxes, $maxRecipients, $environment, $senderBox, $letterhead);
    }

    /**
     * The settings of the template, when the slices, the site and datovka_conf.php are ready for them.
     * Of a slice the site does not use, or datovka_conf.php does not bind to it, it tells only that:
     * a template of any site may name any slice.
     * @param string       $settings the JSON object of the template
     * @param string       $site     the site whose page is rendered - never a value of the template
     * @param ?DatovkaConf $conf     as Accounts::binding()
     * @throws SetupException what to fix
     * @throws \InvalidArgumentException $site is no id
     */
    public static function fromTemplate(string $settings, string $site, ?SliceRules $rules = null, #[\SensitiveParameter] ?DatovkaConf $conf = null): self
    {
        $site   = ModuleId::normalize($site) ?? throw new \InvalidArgumentException('A site id is 32 hexadecimal digits');
        $rules ??= new SliceRules();
        $values = self::decoded($settings, '{');
        if (is_null($values)) {
            throw new SetupException([new SliceProblem(_m('The settings of the mass sending are not a JSON object - write them with {jsonasoc:...}'))]);
        }
        // a template written on more lines leaves line ends around a value
        $values   = array_map(static fn(mixed $value): mixed => is_string($value) ? trim($value) : $value, $values);
        $values   = array_filter($values, static fn(mixed $value): bool => !in_array($value, ['', null], true));
        $problems = [];
        $unknown  = array_diff(array_map('strval', array_keys($values)), self::SETTINGS);
        if ($unknown) {
            $problems[] = new SliceProblem(_m('Unknown settings of the mass sending: %1 - the settings are %2', [implode(', ', $unknown), implode(', ', self::SETTINGS)]));
        }
        $campaign    = self::slice($values, 'campaign_slice', $problems);
        $message     = self::slice($values, 'message_slice', $problems);
        $source      = self::slice($values, 'source_slice', $problems);
        $types       = self::types($values['types'] ?? null, $problems);
        $defaults    = self::texts($values['default_types'] ?? [], 'default_types', $problems);
        $memberField = self::field($values, 'member_field', $problems);
        $testBoxes   = self::boxes($values['test_box'] ?? [], $problems);
        $max         = self::max($values['max_recipients'] ?? self::DEFAULT_RECIPIENTS, $problems);
        $environment = $values['environment'] ?? '';
        $letterhead  = $values['letterhead'] ?? '';

        $typeIds = array_map('strval', array_keys($types));
        if ($types && ($unchosen = array_diff($defaults, $typeIds))) {
            $problems[] = new SliceProblem(_m('default_types names types that are not among types: %1', [implode(', ', $unchosen)]));
        }
        if (!in_array($environment, ['', Account::TEST], true)) {
            $problems[] = new SliceProblem(_m('environment may only be "%1" - misc/datovka/datovka_conf.php decides when production is used', [Account::TEST]));
        }
        if (!is_string($letterhead)) {
            $problems[] = new SliceProblem(_m('Set letterhead to a text'));
        }

        $account = null;
        if (($campaign !== '') && ($message !== '')) {
            $siteProblems = $rules->siteProblems($site, $campaign, $message);
            try {
                $account = Accounts::forSlices($site, $campaign, $message, ($environment === Account::TEST) ? Account::TEST : '', $conf);
            } catch (ConfigException $e) {
                // says how to bind an account, nothing of the accounts there are
                $problems[] = new SliceProblem($e->getMessage());
            }
            // the slices themselves only once they are the site's, bound to it - of others the template learns nothing
            $slicesKnown = !$siteProblems && !is_null($account);
            array_push($problems, ...($slicesKnown ? $rules->sliceProblems($campaign, $message) : $siteProblems));
        }
        if (($source !== '') && $types) {
            array_push($problems, ...$rules->sourceProblems($site, $source, $typeIds, $memberField));
        }
        if ($problems || is_null($account)) {
            throw new SetupException($problems, $campaign);
        }

        return new self($site, $campaign, $message, $source, $types, $defaults, $memberField, $testBoxes, $max, $account->environment, ($account->kind === Account::HOSTED) ? $account->login : '', (string) $letterhead);
    }

    /**
     * fromTemplate() for AA::Contentcache() - the Setup, or what to fix, once a request
     */
    public static function checked(string $settings, string $site, ?SliceRules $rules = null, #[\SensitiveParameter] ?DatovkaConf $conf = null): self|SetupException
    {
        try {
            return self::fromTemplate($settings, $site, $rules, $conf);
        } catch (SetupException $e) {
            return $e;
        }
    }

    /**
     * The JSON object or list $text holds. aa_json_decode() gives the texts in the encoding of AA, but
     * [] for a broken JSON too.
     * @param string $starts the first characters taken: '{' an object only
     * @return ?array<array-key, mixed> null when $text holds none
     */
    private static function decoded(string $text, string $starts = '{['): ?array
    {
        $text = trim($text);
        if (($text === '') || !str_contains($starts, $text[0])) {
            return null;
        }
        $value = aa_json_decode($text, true);
        if (!is_array($value) || (($value === []) && !in_array(preg_replace('/\s+/', '', $text), ['[]', '{}'], true))) {
            return null;
        }

        return $value;
    }

    /** a value {jsonasoc} gave as the JSON text of an object or a list - that object or list; else the value */
    private static function nested(mixed $value): mixed
    {
        return is_string($value) ? (self::decoded($value) ?? $value) : $value;
    }

    /**
     * @param array<array-key, mixed> $values
     * @param list<SliceProblem>      $problems
     * @return string the id in small letters; '' when missing or not one
     */
    private static function slice(array $values, string $setting, array &$problems): string
    {
        $id = is_string($values[$setting] ?? null) ? ModuleId::normalize(trim($values[$setting])) : null;
        if (is_null($id)) {
            $problems[] = new SliceProblem(_m('Set %1 to the id of a slice - 32 hexadecimal digits', [$setting]));
        }

        return $id ?? '';
    }

    /**
     * @param array<array-key, mixed> $values
     * @param list<SliceProblem>      $problems
     * @return string '' when not set
     */
    private static function field(array $values, string $setting, array &$problems): string
    {
        $field = $values[$setting] ?? '';
        if ($field === '') {
            return '';
        }
        if (!is_string($field) || !AA_Field::isValidFieldId($field)) {
            $problems[] = new SliceProblem(_m('Set %1 to the id of a field - 16 characters like text...........1', [$setting]));
            return '';
        }

        return $field;
    }

    /**
     * @param list<SliceProblem> $problems
     * @return array<array-key, string> type => label
     */
    private static function types(mixed $types, array &$problems): array
    {
        $types = self::nested($types);
        $ret   = [];
        if (is_array($types)) {
            foreach ($types as $type => $label) {
                if (!is_string($label) || (trim($label) === '') || (trim((string) $type) === '')) {
                    $ret = [];
                    break;
                }
                $ret[trim((string) $type)] = trim($label);
            }
        }
        if (!$ret) {
            $problems[] = new SliceProblem(_m('Set types to an object of the item types to offer, each with its label: {jsonasoc:<type>:<label>:...}'));
        }

        return $ret;
    }

    /**
     * @param list<SliceProblem> $problems
     * @return list<string> trimmed, each once
     */
    private static function texts(mixed $list, string $setting, array &$problems): array
    {
        $list = self::nested($list);
        $list = is_string($list) ? [$list] : $list;
        if (!is_array($list) || array_filter($list, static fn(mixed $v): bool => !is_string($v) || (trim($v) === ''))) {
            $problems[] = new SliceProblem(_m('Set %1 to a text, or to a list of texts: ["...","..."]', [$setting]));
            return [];
        }

        return array_values(array_unique(array_map('trim', $list)));
    }

    /**
     * @param list<SliceProblem> $problems
     * @return list<string> box ids as ISDS knows them
     */
    private static function boxes(mixed $list, array &$problems): array
    {
        $boxes = [];
        foreach (self::texts($list, 'test_box', $problems) as $box) {
            try {
                $boxes[] = Box::normalizeId($box);
            } catch (\InvalidArgumentException) {
                $problems[] = new SliceProblem(_m('test_box: %1 is not the id of a data box - 7 letters or digits', [$box]));
            }
        }

        return array_values(array_unique($boxes));
    }

    /** @param list<SliceProblem> $problems */
    private static function max(mixed $max, array &$problems): int
    {
        $number = (is_int($max) || (is_string($max) && ctype_digit($max))) ? (int) $max : 0;
        if (($number < 1) || ($number > self::MAX_RECIPIENTS)) {
            $problems[] = new SliceProblem(_m('Set max_recipients to a number from 1 to %1', [self::MAX_RECIPIENTS]));
            return self::DEFAULT_RECIPIENTS;
        }

        return $number;
    }
}
