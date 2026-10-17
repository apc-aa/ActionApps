<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Credential\Store;
use AA\Isds\Account;
use AA\Isds\Accounts;
use AA\Isds\Binding;
use AA\Isds\SliceProblem;
use AA\Isds\SliceRules;
use AA_Module;

/**
 * The ISDS accounts include/config.php sets up (AA\Isds\Accounts): what to fix in their constants,
 * the constants AA does not read, and whether the site and the slices each account is bound to are
 * ready (SliceRules - the rules the page that sends applies). Constant names only, never a value.
 * No network: CheckIsds logs in.
 */
class CheckIsdsAccounts extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('ISDS accounts');
    }

    public static function description(): string
    {
        return _m('Checks the ISDS accounts include/config.php sets up for sending to data boxes: their constants, and the site and slices each account sends for.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'mail';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $constants = $this->constants();
        $bindings  = Accounts::bindings($constants);
        $strays    = Accounts::strays($constants);
        if (!$bindings && !$strays) {
            return [new Finding(Finding::OK, _m('No ISDS account is set up in include/config.php'))];
        }
        $rules    = $this->sliceRules();
        $findings = [];
        foreach ($bindings as $binding) {
            if (!$binding->usable()) {
                $findings[] = self::accountFinding($binding);
            }
            $finding = self::slicesFinding($binding, $rules);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        if ($strays) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('include/config.php has ISDS constants AA does not read: %1', [count($strays)]),
                count: count($strays),
                evidence: array_map(static fn(string $name): array => [_m('Constant') => safe($name)], $strays),
                advice: _m('An ISDS account is set up by the constants %1, the account named by one word of capital letters and digits; production ones start with %2. Correct the names, or remove the constants.', [implode(', ', Accounts::constants(Account::TEST, Accounts::PLACEHOLDER)), Accounts::prefix(Account::PRODUCTION, '')]),
                key: 'strays',
            );
        }

        return $findings ?: [new Finding(Finding::OK, _m('ISDS accounts ready to send: %1', [implode(', ', array_map(static fn(Binding $b): string => Accounts::prefix($b->environment, $b->name), $bindings))]))];
    }

    /** the account's own constants hold the problem; production cannot be used at all yet */
    private static function accountFinding(Binding $binding): Finding
    {
        $label      = Accounts::prefix($binding->environment, $binding->name);
        $production = $binding->environment === Account::PRODUCTION;

        return new Finding(
            $production ? Finding::WARNING : Finding::ERROR,
            $production ? _m('ISDS account %1 cannot be used: AA cannot log in to production ISDS yet', [$label]) : _m('ISDS account %1 cannot be used', [$label]),
            evidence: array_map(static fn(string $problem): array => [_m('Problem') => safe($problem)], $binding->problems),
            advice: $production
                ? _m('Production ISDS lets an application in with a certificate only, which AA cannot do yet. Remove the constants of the account from include/config.php until then and send from a test account.')
                : _m('Fix in include/config.php what the evidence names. The account is set up by the constants %1.', [implode(', ', Accounts::constants($binding->environment, $binding->name))]),
            key: "account:{$binding->environment}:{$binding->name}",
        );
    }

    /** the slices of a binding that names them, and its sites - even of an unusable binding, it gets fixed */
    private static function slicesFinding(Binding $binding, SliceRules $rules): ?Finding
    {
        if ($binding->campaignSlice === '') {
            return null;
        }
        $problems = [];
        foreach ($binding->sites as $site) {
            array_push($problems, ...$rules->siteProblems($site, $binding->campaignSlice, $binding->messageSlice));
        }
        array_push($problems, ...$rules->sliceProblems($binding->campaignSlice, $binding->messageSlice));
        if (!$problems) {
            return null;
        }

        return new Finding(
            // production cannot send yet, its account is a warning too
            ($binding->environment === Account::PRODUCTION) ? Finding::WARNING : Finding::ERROR,
            _m('The slices of ISDS account %1 are not ready for sending', [Accounts::prefix($binding->environment, $binding->name)]),
            count: count($problems),
            evidence: array_map(static fn(SliceProblem $problem): array => [_m('Problem') => safe($problem->text)], $problems),
            advice: _m('Fix what the evidence names before sending from these slices.'),
            links: self::links($problems, $rules),
            key: "slices:{$binding->environment}:{$binding->name}",
            place: $rules->name($binding->campaignSlice),
        );
    }

    /**
     * The pages that fix the problems, one link for each page of each module. Two modules of one name
     * are told apart by the start of their ids.
     * @param list<SliceProblem> $problems
     * @return array<string, string> label => url
     */
    private static function links(array $problems, SliceRules $rules): array
    {
        $names = [];
        foreach ($problems as $problem) {
            if (($problem->module !== '') && ($problem->page !== '')) {
                $names[$problem->module] ??= $rules->name($problem->module);
            }
        }
        $counts = array_count_values($names);
        $links  = [];
        foreach ($problems as $problem) {
            if (!isset($names[$problem->module]) || ($problem->page === '')) {
                continue;
            }
            $name = $names[$problem->module];
            if ($counts[$name] > 1) {
                $name .= ' (' . substr($problem->module, 0, 8) . ')';
            }
            $links[self::pageLabel($problem->page) . ': ' . $name] = AA_Module::getModuleUrl($problem->module, $problem->page);
        }

        return $links;
    }

    private static function pageLabel(string $page): string
    {
        return match ($page) {
            SliceRules::SLICE_PAGE   => _m('Slice settings'),
            SliceRules::FIELDS_PAGE  => _m('Fields'),
            SliceRules::FEEDING_PAGE => _m('Inner Node Feeding'),
            SliceRules::NOTIFY_PAGE  => _m('Email Notification'),
            SliceRules::SITE_PAGE    => _m('Site settings'),
            default                  => $page,
        };
    }

    // ------------------------------------------------------------ what tests replace

    /** @return array<string, mixed> as Store::userConstants() */
    protected function constants(): array
    {
        return Store::userConstants();
    }

    protected function sliceRules(): SliceRules
    {
        return new SliceRules();
    }
}
