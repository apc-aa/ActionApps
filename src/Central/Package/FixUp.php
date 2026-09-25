<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** One change the importer makes to a package (FixUps; design balicek-v1.md § 15). Data for the preview -
 *  ImportPlan::$package has it applied already. `old` and `new` quote package text: escape them (safe()) when shown. */
final readonly class FixUp
{
    /** email.attachments of SecurityLint::ATTACHMENTS_DROPPED (a local path or a computed value) - cleared */
    public const ATTACHMENTS = SecurityLint::ATTACHMENTS_DROPPED;
    /** slice.lang_file (the old copy) not of the list - the module's lang_file instead (Validator W) */
    public const LANG_FILE = Validator::LANG_FILE_REPLACED;
    /** ga_id, alternate_url, app_* of the site settings - dropped (Validator W); `old` = the value(s) */
    public const SETTINGS = Validator::SETTINGS_DROPPED;
    /** strip_tags() of a spot name, variable, condition variable or value with a tag start - the site
     *  tree admin prints them unescaped (SecurityLint::SPOT_MARKUP) */
    public const SPOT_MARKUP = 'spot-markup-stripped';
    /** a site without <spot-tree>: site.structure = '' */
    public const NO_TREE = 'no-spot-tree';
    /** meta/publish is `users` on the target (Q-H1) */
    public const PUBLISH = 'publish';
    /** an alerts_filter shared by Alerts modules (the same row, Validator) is created once - by the
     *  first module; `old` = the filter id, `new` = the path of the module that creates it */
    public const FILTER_SHARED = 'filter-shared';

    public function __construct(
        public string $path,
        public string $code,
        public string $old,
        public string $new,
    ) {}
}
