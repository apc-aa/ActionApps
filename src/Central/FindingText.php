<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\ItemFilter;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\SecurityLint;
use AA\Central\Package\SettingDefaults;
use AA\Central\Package\ValueCodec;

/** Findings of an app check in words of the admin: what is wrong and what to do about it, and where it is
 *  - named by the site, slice, spot, view or field of the package instead of its ids. An install is rare,
 *  so the texts assume the reader does not know the codes. */
final class FindingText
{
    /** codes whose found value may be the secret itself - a password, a key, a signed payload */
    private const SECRET_CODES = ['credentials', 'jwtencode', 'setsecret', SecurityLint::SLICE_PWD];

    public function __construct(private readonly ?Package $package = null) {}

    /** the levels by their danger, as the headings of the findings name them @return array<string, string> level => label */
    public static function levels(): array
    {
        return [LintFinding::SECURITY => _m('Security findings (S)'), LintFinding::ERROR => _m('Errors (E)'), LintFinding::WARNING => _m('Warnings (W)'), LintFinding::INFO => _m('Notes (I)')];
    }

    /** what the level means for the install - shown by the heading of the level */
    public static function level(string $level): string
    {
        return match ($level) {
            LintFinding::SECURITY => _m('Parts of the app that could harm this AA or its visitors if the app is not what it claims. They block nothing, but you confirm them before installing.'),
            LintFinding::ERROR    => _m('The app cannot be installed until they are solved.'),
            LintFinding::WARNING  => _m('The app can be installed; check these places after installing.'),
            default               => _m('For your information - usually nothing to do.'),
        };
    }

    /** a finding in words: `title`, `what` it means, what to do (`todo`), and `found` - the heading of the
     *  column with the found value when "Found" says too little; null = an unknown code
     *  @return array{title: string, what: string, todo: string, found?: string}|null */
    public static function explain(LintFinding $finding): ?array
    {
        $code = $finding->code;
        if ($finding->level === LintFinding::SECURITY) {
            return self::security($code);
        }
        return match (true) {
            // commands the security check names I: a harmless form of them
            in_array($code, SecurityLint::INFO_COMMANDS, true) => ['title' => _m('Prints facts about the installation'),
                'what' => _m('{debug}, {version} or {serverload} show visitors debugging output, the version of AA or the load of the server.'),
                'todo' => _m('Usually harmless. Remove it from public pages if visitors should not see it.')],
            $code === 'user' => ['title' => _m('Shows data of the signed-in user'),
                'what' => _m('The text prints something of the user who is signed in, such as the name - not the password.'),
                'todo' => _m('Nothing to do.')],
            $code === 'f_u' => ['title' => _m('Calls a function of this AA'),
                'what' => _m('The field alias calls a usr_ function; such functions are defined by each AA in include/usr_aliasfnc.php.'),
                'todo' => _m('Check that this AA has the function, otherwise the field shows nothing.')],
            $code === 'img' => ['title' => _m('A thumbnail of a picture of the app'),
                'what' => _m('The text makes a thumbnail of a picture of the app or of a field of the item.'),
                'todo' => _m('Nothing to do.')],
            $code === 'header' => ['title' => _m('Sets a common HTTP header'),
                'what' => _m('The text sets a status code, the type of the content, a file name or a common protective header - nothing that allows browsers more.'),
                'todo' => _m('Nothing to do.')],

            // the source, the package
            in_array($code, ['export-failed', 'export-denied'], true) => ['title' => _m('The other AA did not give the app'),
                'what' => _m('The AA the app comes from refused to export it or failed while exporting.'),
                'todo' => _m('Check that you are signed in there and may edit all the slices of the app. Then try again; if it repeats, look at the logs of that AA.')],
            in_array($code, ['module-rows', 'table-not-allowed', 'invalid-id', 'duplicate-id', 'object', 'items', 'item-key', 'batch', 'row-missing', 'setting-field', 'int-range', 'transfer-only', 'group-name', 'dangling-ref'], true) => ['title' => _m('The app package is damaged'),
                'what' => _m('The package has a part in a form AA never writes - it was edited by hand, cut, or made by another version of AA.'),
                'todo' => ($finding->level === LintFinding::ERROR) ? _m('Export the app from its AA again. When the error stays, update the AA it comes from (or this one) to the current version.') : _m('The part is left out. Usually nothing to do.')],
            in_array($code, ['unknown-column', 'unknown-element', 'unknown-attribute', 'unknown-class', 'newer-format', 'schema-location'], true) => ['title' => _m('Parts this AA does not know'),
                'what' => _m('The package was made by a newer version of AA, or edited by hand; this AA leaves out what it does not know.'),
                'todo' => _m('Usually nothing. When something of the app is missing after installing, update this AA and install again.')],
            $code === 'items-published' => ['title' => _m('Items in a published app'),
                'what' => _m('A published app carries only definitions - no items, readers or passwords.'),
                'todo' => _m('Publish the app without its items; items travel only in a transfer between two AAs.')],
            $code === 'charset-broken' => ['title' => _m('The server converts characters wrongly'),
                'what' => _m('The PHP of this server converts the Central European characters wrongly, so no text of the app could be trusted.'),
                'todo' => _m('Ask the administrator of the server to fix the iconv of PHP.')],

            // this AA
            $code === 'no-transaction' => ['title' => _m('Database tables without transactions'),
                'what' => _m('Some tables of this AA are not InnoDB. An install that failed in the middle could not be taken back and would leave half an app.'),
                'todo' => _m('Run the optimization named in the value (AA > Optimize) and preview the app again.'),
                'found' => _m('Tables')],
            $code === 'setting-not-number' => ['title' => _m('A setting takes a number'),
                'what' => _m('The value you typed for this slice setting is no whole number, and the field stores whole numbers only.'),
                'todo' => _m('Type a number, or leave the field empty to keep the value of the app, and check the changes.'),
                'found' => _m('Value')],
            $code === 'setting-invalid' => ['title' => _m('A setting refuses the value'),
                'what' => _m('The value you typed for this slice setting does not pass the check of its field (an e-mail, a URL, a number, a value of a list, yes or no, ...).'),
                'todo' => _m('Type a value the field takes, or leave the field empty to keep the value of the app, and check the changes.'),
                'found' => _m('Value')],
            $code === 'setting-insert-func' => ['title' => _m('A setting to fill in later'),
                'what' => _m('The field of this slice setting changes the value when it is saved (a password stores its hash, for example), so a value typed here would be stored wrong.'),
                'todo' => _m('Leave the field empty and check the changes; after installing fill it in: in the slice open Setting in the menu of the item manager.'),
                'found' => _m('Insert function')],
            $code === 'owner-invalid' => ['title' => _m('The new owner is not complete'),
                'what' => _m('A new owner needs a name and a valid e-mail, each at most 80 bytes (a letter with an accent may take two) and in characters the new modules can store.'),
                'todo' => _m('Fill in both in "Owner of the new modules" above and check the changes.'),
                'found' => _m('E-mail')],
            $code === 'reader-left-out' => ['title' => _m('Alerts lose a part of a slice left out'),
                'what' => _m('The Alerts module uses a slice you left out - its readers, the view of a filter or an e-mail template. The Alerts module is installed without it.'),
                'todo' => _m('After installing set the reader slice, the filters and the e-mails in the settings of the Alerts module - or install that slice too.'),
                'found' => _m('Slice, view or e-mail')],
            $code === 'group-owner-left-out' => ['title' => _m('A list of values of a slice left out'),
                'what' => _m('The list of values (constant group) belongs to a slice you left out, and other parts of the app use it - it is installed without an owner.'),
                'todo' => _m('Usually nothing.'),
                'found' => _m('Slice left out')],
            $code === 'owner-unknown' => ['title' => _m('No owner for the new modules'),
                'what' => _m('The new slices and sites need an owner of this AA, and none was chosen.'),
                'todo' => _m('Choose the owner in "Owner of the new modules" above and check the changes.')],
            $code === 'login-charset' => ['title' => _m('Reader logins with broken characters'),
                'what' => _m('Login names of some readers in the AA the app comes from are not valid text, so this AA cannot check whether they collide with its own logins.'),
                'todo' => _m('Fix the login names of those readers in the AA the app comes from and transfer again.')],
            in_array($code, ['charset', 'too-long'], true) => ['title' => _m('A text this AA cannot store'),
                'what' => ($code === 'too-long') ? _m('The text is longer than this AA can store in that place.') : _m('The text has characters the character set of this AA (or of the slice) cannot hold - usually an old windows-1250 slice and a character like an emoji.'),
                'todo' => _m('Shorten or change the text in the AA the app comes from and preview again, or install into a UTF-8 slice.')],
            $code === 'may-be-too-long' => ['title' => _m('A text may not fit'),
                'what' => _m('The text is close to the length this AA stores there; it may be cut.'),
                'todo' => _m('After installing check the end of the text.')],
            $code === 'item-too-big' => ['title' => _m('An item too big to transfer'),
                'what' => _m('One item alone is bigger than the transfer carries at once.'),
                'todo' => _m('Make the item smaller in its AA (move big texts or files out) and transfer again.')],
            in_array($code, ['lang-file', 'lang-file-replaced', 'lang-file-normalised'], true) => ['title' => _m('Language of a module'),
                'what' => ($finding->level === LintFinding::ERROR) ? _m('The module has a language this AA does not know, so it does not know its character set either.') : _m('The language file of the module is written as this AA names it; the language stays the same.'),
                'todo' => ($finding->level === LintFinding::ERROR) ? _m('Set a language in the settings of the module in the AA the app comes from and export again.') : _m('Nothing to do.')],
            in_array($code, ['spot-tree', 'spot-markup', 'spot-markup-stripped', 'spot-unused', 'no-spot-tree'], true) => ['title' => _m('The tree of the site'),
                'what' => match ($code) {
                    'spot-tree'   => _m('The tree of the pages (spots) of the site is damaged.'),
                    'spot-unused' => _m('Contents of spots no longer in the tree of the site - they are left out.'),
                    'no-spot-tree' => _m('The site has no tree of pages - it starts empty.'),
                    default       => _m('A name or a condition of a spot contains HTML tags; the tags are removed from the names, conditions stay as they are.'),
                },
                'todo' => ($code === 'spot-tree') ? _m('Open the site in its AA, save its tree in Code Manager and export again.') : _m('Nothing to do; check the names and conditions of the spots after installing.')],
            $code === 'state-file' => ['title' => _m('A control file of the other AA'),
                'what' => ($finding->level === LintFinding::ERROR) ? _m('The site names a file on the server of the AA it comes from, which this AA would run - it cannot be installed so.') : _m('The site names a file on the server of the AA it comes from; it is left out.'),
                'todo' => ($finding->level === LintFinding::ERROR) ? _m('Remove the file from the site settings in the AA the app comes from and export again.') : _m('Nothing to do, unless the site needs such a file here - then set it in Module Settings of the site.')],
            $code === 'group-unpublished' => ['title' => _m('Values of a slice outside the published app'),
                'what' => _m('The app uses a list of values (a constant group) of a slice no published site carries - publishing would show its values.'),
                'todo' => _m('Let the field use another list of values, or publish the slice that owns it.')],
            in_array($code, ['group-name-exhausted', 'group-renamed', 'group-kept', 'group-redirected', 'group-left-out', 'group-outside', 'group-other', 'group-missing'], true) => ['title' => _m('Lists of values (constant groups)'),
                'what' => match ($code) {
                    'group-name-exhausted' => _m('There is no free name for a list of values of the app.'),
                    'group-renamed'        => _m('This AA already has a list of values of that name, so the app gets its list under a new name; the app is changed to use it.'),
                    'group-missing'        => _m('A field offers values of a list that does not exist - it offers nothing.'),
                    'group-outside'        => _m('The text reads a list of values the app does not carry; this AA must have a list of that name.'),
                    'group-kept', 'group-redirected' => _m('The existing slice keeps its own list of values; the app uses that one.'),
                    'group-other'          => _m('The text names a list of values in a form the install does not rewrite; when the list gets a new name here, the text keeps the old one.'),
                    default                => _m('A list of values is used as it is.'),
                },
                'todo' => match ($code) {
                    'group-name-exhausted' => _m('Rename or delete some of the lists of values in this AA and preview again.'),
                    'group-missing', 'group-outside' => _m('After installing, create the list or choose another one in the field settings.'),
                    'group-other'          => _m('When the list is renamed (see the notes), change the name in the text after installing.'),
                    default                => _m('Nothing to do.'),
                },
                'found' => _m('List')],
            $code === 'owner-missing' => ['title' => _m('A list of values without its slice'),
                'what' => _m('A list of values (a constant group) belongs to a slice that is not in the app or no longer exists. The list travels anyway; only the character set is taken from this AA.'),
                'todo' => _m('Usually nothing. On a UTF-8 AA it makes no difference.'),
                'found' => _m('Owner (slice id)')],
            $code === 'invalid-bytes' => ['title' => _m('Broken characters'),
                'what' => _m('A text is not valid UTF-8 - probably damaged in its AA. It travels byte by byte.'),
                'todo' => _m('After installing check the text and fix wrong characters.')],

            // references out of the app
            in_array($code, ['view-outside', 'email-outside'], true) && ($finding->level === LintFinding::ERROR) => ['title' => _m('Alerts use a part outside the app'),
                'what' => _m('An Alerts collection or filter uses a view or an e-mail template that is not in the app.'),
                'todo' => _m('Add the slice with that view or template to the app, or change the Alerts setting in the AA the app comes from, and preview again.'),
                'found' => _m('Number')],
            in_array($code, ['view-outside', 'email-outside', 'module-outside'], true) => ['title' => _m('A link to a part outside the app'),
                'what' => match ($code) {
                    'view-outside'  => _m('The text shows a view by its number, and that view is not in the app. Here the number means another view, or none.'),
                    'email-outside' => _m('The text sends an e-mail template by its number, and that template is not in the app. Here the number means another template, or none.'),
                    default         => _m('The text reads a slice or a site that is not in the app. Here that id means nothing.'),
                },
                'todo' => _m('Add the slice with it to the app (for a site: to its Uses) and preview again, or after installing change the number to the right one here.'),
                'found' => ($code === 'module-outside') ? _m('Id') : _m('Number')],
            in_array($code, ['computed', 'unknown-form', 'bare-number', 'glued-id', 'form'], true) => ['title' => _m('A link the check cannot follow'),
                'what' => match ($code) {
                    'computed'    => _m('The number of a view or an e-mail is computed when the page is shown, so it cannot be renumbered for this AA.'),
                    'bare-number' => _m('A number equal to the number of a view of the app, in a place where it may mean something else (a width, a date) - left as it is.'),
                    'form'        => _m('{form:...} uses a form of this AA; forms do not travel with an app.'),
                    default       => _m('The link is written in a form the install does not rewrite; it stays as it is.'),
                },
                'todo' => ($code === 'bare-number') ? _m('When it is a view there, change it after installing to the new number of that view.') : _m('After installing check that the place shows the right thing.')],
            in_array($code, ['reader-outside', 'alerts-outside', 'module-type', 'module-missing', 'alerts-filter-kept', 'alerts-field-kept'], true) => ['title' => _m('Modules outside the app'),
                'what' => match ($code) {
                    'reader-outside' => _m('The Alerts module sends items of a slice that is not in the app.'),
                    'alerts-outside' => _m('An Alerts module that is not in the app sends items of its slice; it stays behind.'),
                    'module-type'    => _m('The site uses a module of a kind an app does not carry (polls, links, jump) - it is left out.'),
                    'module-missing' => _m('The site lists a module that no longer exists - AA ignores it, so does the install.'),
                    default          => _m('An Alerts setting of a module outside the app is written as it is; here it does nothing.'),
                },
                'todo' => ($code === 'reader-outside') ? _m('Add that slice to the app, or remove it from the Alerts collection.') : _m('Nothing to do.')],
            $code === 'email-address' => ['title' => _m('An e-mail address of the other organisation'),
                'what' => _m('An e-mail template has a fixed address (the sender, a copy) - it travels with the app.'),
                'todo' => _m('After installing change the address in the e-mail templates to your own.')],
            $code === 'email-attachments-dropped' => ['title' => _m('An e-mail attachment left out'),
                'what' => _m('An e-mail template attaches a file of the server; that would attach a file of this server, so it is removed.'),
                'todo' => _m('After installing set the attachment again if it is needed.')],
            $code === 'settings-dropped' => ['title' => _m('Site settings left out'),
                'what' => _m('The Google Analytics id, other domains of the site and app settings belong to the other AA; they are not installed.'),
                'todo' => _m('Set them in Module Settings of the site after installing.')],
            $code === 'setting-default-skipped' => ['title' => _m('A slice setting without a value'),
                'what' => _m('A setting of the slice has a default that depends on who installs (a user, a random code, an expression) - it is left empty.'),
                'todo' => _m('Fill it in the Setting of the slice after installing.')],
            $code === 'object-dropped' => ['title' => _m('Objects the app does not carry'),
                'what' => _m('Some objects of the module do not travel: forms, notes on admin pages and similar helpers of the other AA.'),
                'todo' => _m('Usually nothing. A form the pages show ({form:...}) has to be made here again.'),
                'found' => _m('Kind (count)')],
            $code === 'subobject' => ['title' => _m('A part of an object is missing'),
                'what' => _m('An object (an alias, a form or a similar setting) refers to a part that no longer exists; the part is left out.'),
                'todo' => _m('After installing check the settings of the module that use the object.')],
            $code === 'profile-property' => ['title' => _m('An unknown profile setting'),
                'what' => _m('A default profile of the slice has a setting this AA does not know; AA ignores it.'),
                'todo' => _m('Nothing to do.')],

            // who may write, who may sign in
            $code === 'permission' => ['title' => _m('Who may write into the slice'),
                'what' => _m('The slice lets visitors of the web (not signed in) add or edit items, gives access to its file manager, or lets readers of a group field sign in. That is right for a public form - a sign-up, a comment - and wrong elsewhere.'),
                'todo' => _m('After installing check in Slice Admin > Slice that the slice should allow it; otherwise switch it off there.'),
                'found' => _m('Set to')],
            $code === 'perm-mode' => ['title' => _m('Who can sign in to the site'),
                'what' => _m('The site does not say which readers may sign in to its pages, so every user of this AA and the readers of all its Reader Management slices can.'),
                'todo' => _m('After installing open Module Settings of the site and set "User login databases" to "first Reader Management slice listed in Uses slices".'),
                'found' => _m('Set to')],
            in_array($code, ['preselected-by-role', 'preselected-reader-slice', 'preselect-dropped'], true) => ['title' => _m('An existing slice was chosen'),
                'what' => match ($code) {
                    'preselected-reader-slice' => _m('The app goes into your existing Reader Management slice - its views and fields will work with your real readers.'),
                    'preselect-dropped'        => _m('A slice of the same role exists, but it cannot take the slice of the app; a new slice is made.'),
                    default                    => _m('The slice of the app goes into your existing slice of the same role.'),
                },
                'todo' => _m('Choose another slice (or "a new slice") in the table above if this is not what you want.')],

            // into an existing slice
            in_array($code, ['target-slice', 'into-source', 'slice-type-differs', 'field-type-differs'], true) => ['title' => _m('The chosen slice cannot take the app'),
                'what' => match ($code) {
                    'into-source'        => _m('A transfer into the very slice it copies would copy its items into it again.'),
                    'slice-type-differs' => _m('One of the slices keeps the logins of readers (Reader Management) and the other not - the logins would not work.'),
                    'field-type-differs' => _m('A field of the chosen slice stores its values otherwise (text or number, a password) - the items would be broken.'),
                    default              => _m('The slice chosen in "Into" does not exist, you may not edit it, or it is chosen twice - the value says which.'),
                },
                'todo' => _m('Choose another slice, or "a new slice", in the table above and preview again.')],
            in_array($code, ['into-existing', 'view-exists', 'email-exists', 'object-exists', 'slice-state-kept'], true) => ['title' => _m('Into an existing slice'),
                'what' => _m('The slice of the app goes into an existing slice; what the slice has (its views, e-mails, settings) stays and the app uses it.'),
                'todo' => _m('Nothing to do.')],
            in_array($code, ['field-differs', 'view-differs', 'relation-target-differs'], true) => ['title' => _m('The existing slice differs'),
                'what' => match ($code) {
                    'relation-target-differs' => _m('A relation field of the existing slice selects from another slice than the app expects - the transferred links would point elsewhere.'),
                    'view-differs'            => _m('The existing slice has a view of the same name, but another one; the slice keeps its view.'),
                    default                   => _m('The existing slice has the field, but set otherwise; the slice keeps its setting.'),
                },
                'todo' => _m('If the app behaves oddly after installing, compare these settings with the ones of the app.'),
                'found' => _m('Differs in')],
            in_array($code, ['view-unmatched', 'email-unmatched', 'object-unmatched'], true) => ['title' => _m('Maybe twice'),
                'what' => _m('A view, an e-mail or an object without a unique name cannot be matched with the existing slice, so it is added - the slice may then have it twice.'),
                'todo' => _m('After installing delete the duplicate.')],
            $code === 'unique-field' => ['title' => _m('Values that must be unique'),
                'what' => _m('The slice has fields whose values must not repeat (a unique field, an SEO name, a sequence); transferred items may repeat values of the items already there.'),
                'todo' => _m('After the transfer check these fields of the new items.')],

            // items and readers of a transfer
            in_array($code, ['item-id-exists', 'item-id-in-use'], true) => ['title' => _m('The items are here already'),
                'what' => _m('This AA already has items with the same ids - the app was probably transferred here before (maybe deleted since, but not purged from the trash).'),
                'todo' => _m('Transfer into the slice that has them (choose it in "Into"), or delete the earlier copy completely, including the trash, and try again.')],
            in_array($code, ['reader-admin', 'reader-perms', 'reader-taken', 'reader-duplicate', 'reader-multivalue'], true) => ['title' => _m('Reader logins collide'),
                'what' => match ($code) {
                    'reader-admin', 'reader-perms' => _m('A reader has the same login name or e-mail as a user of this AA with rights to the administration.'),
                    'reader-taken'     => _m('A reader of this AA already has the same login name or e-mail.'),
                    'reader-duplicate' => _m('Two transferred readers have the same login name or e-mail.'),
                    default            => _m('A reader has more login names or e-mails.'),
                },
                'todo' => ($finding->level === LintFinding::ERROR) ? _m('Change the login of the reader in the AA it comes from (or of the user here) and preview again.') : _m('After the transfer check these readers can sign in.'),
                'found' => _m('Login')],
            in_array($code, ['reader-accounts', 'reader-2fa'], true) => ['title' => _m('Passwords of the readers travel'),
                'what' => _m('The transfer carries the logins of readers with their password hashes (and two-factor secrets).'),
                'todo' => _m('Nothing to do here; do not keep downloaded packages of such apps.')],
            in_array($code, ['item-field', 'item-status'], true) => ['title' => _m('Item values left out'),
                'what' => ($code === 'item-status') ? _m('An item is in no bin (active, holding, trash) - no list of the slice will show it.') : _m('Items have values of a field the slice does not have - they are left out.'),
                'todo' => _m('Usually nothing to do.')],
            $code === ItemFilter::INVALID => ['title' => _m('A condition of "Only items matching" cannot be used'),
                'what' => _m('AA cannot read the condition as it is written, or the source AA did not check it. A condition read wrongly could take other items than you mean, so no items are counted and the app cannot be installed until the condition passes a check.'),
                'todo' => _m('Correct the condition, or empty its field to transfer all the items of the slice, then check the changes again.'),
                'found' => _m('Why')],
            $code === FilterCheck::MATCHES_ALL => ['title' => _m('A condition that takes every item'),
                'what' => _m('Every item of the slice matches, so the condition leaves nothing out and the whole slice goes. That is allowed, but a condition is usually written to narrow: it may name a field every item has set, or a value they all share.'),
                'todo' => _m('If all the items should go, empty the field. If not, change the condition so that it narrows; the count beside the field shows how many items it takes.'),
                'found' => _m('Items matching')],
            $code === FilterCheck::LEFT_OUT => ['title' => _m('Links to items a condition leaves behind'),
                'what' => _m('Items that go along link to items of another slice, and the condition of that slice leaves some of them behind. After the transfer those links point to items this AA does not have. The count is the least; the check after the transfer counts every link pointing nowhere.'),
                'todo' => _m('Widen or empty the condition of the slice that leaves items behind; or give the slice with the links a condition that follows the link (printed under its field when there is one); or accept the links and clean them up after the transfer.'),
                'found' => _m('Links')],
            in_array($code, ['count-differs', 'edited-during-transfer', 'relation-dangling', 'source-count-unknown'], true) => ['title' => _m('Check the transferred items'),
                'what' => match ($code) {
                    'count-differs'          => _m('The slice here has another number of items than the one it comes from (there => here). Into an existing slice that is expected - its own items stay.'),
                    'edited-during-transfer' => _m('Items were edited in the other AA while the transfer ran; the changes may be missing here.'),
                    'relation-dangling'      => _m('Some items link to items this AA does not have.'),
                    default                  => _m('The other AA did not say how many items it has, so the transfer cannot be compared.'),
                },
                'todo' => ($code === 'relation-dangling') ? _m('Transfer also the slice with the linked items.') : _m('Run the transfer again when the numbers should match.')],
            in_array($code, ['domain-dropped'], true) => ['title' => _m('A domain of the site left out'),
                'what' => _m('The site answers to this domain in its AA, but here it belongs to another site or to this AA itself.'),
                'todo' => _m('Nothing to do, unless the domain should move here - then set it in the site settings.')],
            default => null,
        };
    }

    /** the security findings: what the code can do and when it is fine
     *  @return array{title: string, what: string, todo: string}|null */
    private static function security(string $code): ?array
    {
        return match (true) {
            in_array($code, ['include', 'file2text', 'rss2html', 'fileinfo', 'filelink', 'linkcheck', 'gethostbyaddr', 'img', 'f_z'], true) => ['title' => _m('Reads a file or a web address'),
                'what' => _m('The text makes this AA read a file or a URL. The server asks for it, not the visitor: it can read local files of the server or reach addresses of the internal network; a relative URL is fetched with the cookies of the visitor.'),
                'todo' => _m('Fine when it reads a public address the app needs (a feed, an image or a page of its own site). Do not install it when it points to a local path (../, /etc/, file://), to an internal address (localhost, 10.x, 192.168.x) or when the address is built from what a visitor sends (URL parameters).')],
            in_array($code, ['server', 'cookie', 'xuser', 'userinfo', 'user'], true) => ['title' => _m('Prints data of the visitor, a user or the server'),
                'what' => _m('The text prints data of the request, of a user or of the server: {server} server variables, {cookie} the cookies of the visitor, {userinfo} the login log of any user, {user:password} the password the user signed in with.'),
                'todo' => _m('Fine when it shows the visitors their own data (their name, the address of the page). Do not install it when it prints passwords or server settings, or sends such data out - in a URL, an e-mail or a hidden form field.')],
            in_array($code, ['credentials', 'jwtencode', 'setsecret'], true) || str_starts_with($code, 'changepwd') => ['title' => _m('Carries a password or another secret'),
                'what' => _m('The text carries or issues a secret: a reading password of a slice ({credentials}), a signed token ({jwtencode}), a stored secret ({setsecret}) or a change of a password ({changepwd...}). A secret written in the app travels with it to every AA it is installed on.'),
                'todo' => _m('Expected only in apps with a login or an API. Check that the secret is never printed to visitors; after installing replace it with a secret of this AA.')],
            in_array($code, ['mail', 'mailtemplate', 'mailform', SecurityLint::ATTACHMENTS], true) => ['title' => _m('Sends e-mail'),
                'what' => _m('The app sends e-mail from this AA. When a visitor may choose the recipient, anyone can send mail through this AA (spam); attachments are downloaded by the server, a field filled by a visitor lets the visitor choose what is attached.'),
                'todo' => _m('Check that the recipients are fixed addresses or those of the item, not a value from the URL or a form, that the attachments are public files of the app, and that the mail is not sent by a page anyone can load again and again.')],
            in_array($code, ['newitem', 'updateitem', 'ensureitem', 'recompute', 'datovka'], true) => ['title' => _m('Writes data whenever it is shown'),
                'what' => _m('The text writes data whenever it is shown: it creates or changes items ({newitem}, {updateitem}, {ensureitem}, {recompute}) or sends a data box message ({datovka}).'),
                'todo' => _m('Check which slice it writes into - it should be a slice of this app. Writing into another slice by its id, or with values taken from the URL, is suspicious; {datovka} on a public page sends a message on every load.')],
            $code === 'internal' => ['title' => _m('Calls PHP code of AA'),
                'what' => _m('The text calls an internal PHP method of AA with the data of the item.'),
                'todo' => _m('Apps rarely need it - install only when you know why the app calls it.')],
            $code === 'redirect' => ['title' => _m('Sends the visitor elsewhere'),
                'what' => _m('The text sends the visitor to another address. An address taken from the URL lets anyone make a link of your site that leads to a foreign (phishing) page.'),
                'todo' => _m('Fine with a fixed address of the site; not with an address built from URL parameters.')],
            $code === 'header' => ['title' => _m('Sets a security header'),
                'what' => _m('The text sets an HTTP header which decides what browsers allow: Content-Security-Policy (CSP - which scripts and styles may run), CORS (which other sites may read the answers), Report-To and NEL (where the browsers of the visitors send their error reports), Referrer-Policy (to which sites the browsers send the address of the page). A status code or a content type is only a note (I).'),
                'todo' => _m('Check that the CSP allows no scripts of foreign domains, that CORS is not opened to * unless the page is a public API, that Report-To and NEL send the reports to an address you trust, and that Referrer-Policy is neither unsafe-url nor no-referrer-when-downgrade. Security headers the site sets on purpose are fine - confirm them.')],
            in_array($code, ['expand', 'dequote'], true) => ['title' => _m('Runs a text as a template again'),
                'what' => _m('The text expands a text as a template again. When that text comes from visitors (an item of a public form, a URL parameter), they can put AA commands into it and run them with the rights of this AA.'),
                'todo' => _m('Fine over the texts of the app itself; do not install it when it expands item content filled by anonymous posting or parameters of the URL.')],
            $code === 'f_u' => ['title' => _m('Calls a PHP function'),
                'what' => _m('The field alias calls a PHP function by its name, and it is not a literal usr_ function the check could recognise.'),
                'todo' => _m('Ask the author which function it calls; install only when it is a usr_ function defined in include/usr_aliasfnc.php of this AA.')],
            in_array($code, [SecurityLint::ALIAS_COMPUTED, SecurityLint::COMPOSED, SecurityLint::PLACEHOLDER, SecurityLint::JSON_BRACE, SecurityLint::QUOTE_MAP], true) => ['title' => _m('A command whose name is built when shown'),
                'what' => _m('The name of a command is built only when the page is shown (from another command, a placeholder, JSON escapes or the internal quoting of AA), so the check cannot tell which command runs - it may be any of the dangerous ones.'),
                'todo' => _m('Read the text around it. Common templates do not need that; unless you see why it is built this way, do not install the app.')],
            $code === SecurityLint::LINT_FAILED => ['title' => _m('Too complex to check'),
                'what' => _m('The text is so complex that the check ran out of its limits - it is not checked completely.'),
                'todo' => _m('Read the text yourself before installing.')],
            $code === SecurityLint::SLICE_PWD => ['title' => _m('A reading password in a URL'),
                'what' => _m('A URL carries the reading password of a slice (slice_pwd=...). The password travels with the app and shows in pages and server logs.'),
                'todo' => _m('After installing remove it from the URL and set a new reading password of the slice on this AA.')],
            $code === 'domain-kept' => ['title' => _m('The domain moves with the site'),
                'what' => _m('The site answers to this domain in its AA; the transfer gives the domain to the new site here.'),
                'todo' => _m('Confirm it when the site moves here. When the site in the other AA stays in use, remove the domain from the settings of the new site after the transfer.')],
            default => null,
        };
    }

    /** the found value in words where a code stores a number of a setting; the value itself otherwise */
    public static function found(LintFinding $finding, string $path): string
    {
        $ref = $finding->ref;
        if (($ref !== '') && preg_match('~/setting-values/([^/\[]+)~', $path, $setting) && SettingDefaults::secret($setting[1])) {
            return '••••••';        // a setting that may hold a password: no finding shows a part of it
        }
        if (($ref !== '') && (in_array($finding->code, self::SECRET_CODES, true) || str_starts_with($finding->code, 'changepwd'))) {
            // `{credentials:` or `slice_pwd=` says what it is, the rest may be the secret
            return (preg_match('~^(?:\{\w+[:(]|slice_pwd=)~i', $ref, $head) ? $head[0] : '') . '••••••';
        }
        if ($finding->code === ItemFilter::INVALID) {
            [$why, $what] = array_pad(explode(':', $ref, 2), 2, '');
            return match ($why) {
                'too-long'   => _m('Longer than %1 bytes.', [ItemFilter::MAX_BYTES]),
                'too-many'   => _m('More than %1 conditions.', [ItemFilter::MAX_CONDITIONS]),
                'form'       => _m('A condition starts with d-.'),
                'triples'    => _m('Not in threes of field, operator and value - most likely a dash inside a value or a date, which is written doubled: 2026--01--01.'),
                'characters' => _m('A control character, a line break or a backslash.'),
                'field'      => _m('No field %1 in this slice.', [$what]),
                'operator'   => _m('"%1" is not an operator of AA.', [$what]),
                'value'      => _m('A value is missing, cannot be read, or would match every item.'),
                'phrase'     => _m('<> takes one exact value - no OR, AND, NOT or quotes.'),
                'syntax'     => _m('The value compared with %1 cannot be read.', [$what]),
                'charset'    => _m('A value has a character the slice cannot store.'),
                FilterCheck::UNCHECKED => _m('The source AA did not answer about it. An older AA cannot filter items and has to be updated first; otherwise check the changes again.'),
                default      => $ref,
            };
        }
        if ($finding->code === 'perm-mode') {
            return [
                '1' => _m('first Reader Management slice listed in Uses slices'),
                '2' => _m('all Reader Management slices listed in Uses slices'),
                '3' => _m('AA + all Reader Management slices in AA (old behavior)'),
            ][$ref] ?? _m('not set');
        }
        if ($finding->code === 'permission') {
            $values = match (substr($path, (int) strrpos($path, '/') + 1)) {
                'permit_anonymous_post' => ['1' => _m('Active'), '2' => _m('Hold bin'), '3' => _m('Active with antiSPAM tokens')],
                'permit_anonymous_edit' => ['1' => _m('All items'), '2' => _m('Only items posted anonymously'), '3' => _m('-"- and not edited in AA'),
                    '4' => _m('Authorized by a password field'), '5' => _m('Readers, authorized by HTTP auth'), '6' => _m('Only by Planned tasks (cron-toexecute)')],
                default => [],
            };
            return isset($values[$ref]) ? $values[$ref] . " ($ref)" : $ref;
        }
        return $ref . (preg_match('/^\{(?!.*\}\z)/s', $ref) ? '…' : '');     // a command cut at its first inner {..}
    }

    /** a column a finding names, by its label in the admin; '' = none known */
    private static function column(string $column): string
    {
        return match ($column) {
            'permit_anonymous_post' => _m('Allow anonymous posting of items'),
            'permit_anonymous_edit' => _m('Allow anonymous editing of items'),
            'fileman_access'        => _m('File Manager Access'),
            'auth_field_group'      => _m('Auth Group Field'),
            'perm_mode'             => _m('User login databases'),
            'alias1_func', 'alias2_func', 'alias3_func' => _m('alias %1', [$column[5]]),
            default                 => '',
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
                default             => self::column($kind) ?: $kind . ((($key !== '') && !str_starts_with($key, '#') && ($key !== '0')) ? "[$key]" : ''),
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
