<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;

/** Add tables for new polls module */
class AddPolls extends \AA\Optimize\AbstractOptimize
{
    public const POLLS_DEFAULT_VALUES = [
        'id'         => 'PollsTemplate...',
        'name'       => 'Polls Template',
        'deleted'    => 0,
        'type'       => 'P',
        'slice_url'  => '',
        'lang_file'  => 'en-utf8_polls_lang.php',
        'created_at' => 1043151515,
        'created_by' => '',
        'owner'      => 'AA_Core.........',
        'flag'       => 0,
    ];

    /** Name function
     * @return string - a name
     */
    public static function name(): string
    {
        return _m("Add Polls tables");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string
    {
        return _m("Creates the missing tables of the Polls module and the template poll. The polls already there stay.");
    }

    /** implemented actions within this class */
    public static function actions(): array
    {
        return ['repair'];
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array
    {
        return ['sql_update'];
    }

    /** the tables of the module, as the Metabase has them */
    private const TABLES = ['polls', 'polls_answer', 'polls_design', 'polls_ip_lock', 'polls_log'];

    /** Test function
     * @return bool
     */
    public function test(): bool
    {
        $ret = true;
        foreach (self::TABLES as $table) {
            if (!DB_AA::exists_table($table)) {
                $this->message(_m('Table %1 is missing', [$table]));
                $ret = false;
            }
        }
        if (!DB_AA::test('module', [['id', 'PollsTemplate...']])) {
            $this->message(_m('Poll module is not installed'));
            $ret = false;
        }
        return $ret;
    }

    /** Creates only the missing tables (CREATE TABLE IF NOT EXISTS) - the sites' polls and votes are kept;
     *  UpdateDbStructure, earlier in sql_update, brings the existing ones to the template */
    public function repair(): bool
    {
        $metabase = AA::Metabase();
        foreach (self::TABLES as $table) {
            $this->query($metabase->getCreateSql($table));
        }

        // IGNORE: the votes and edits of a template poll a site uses stay; an error (a table UpdateDbStructure could not
        // bring to the template) is reported, the rest of sql_update goes on
        $errors = [];
        $errors[] = $this->tryQuery("INSERT IGNORE INTO `polls` (`id`, `module_id`, `status_code`, `headline`, `publish_date`, `expiry_date`, `locked`, `logging`, `ip_locking`, `ip_lock_timeout`, `set_cookies`, `cookies_prefix`, `design_id`, `aftervote_design_id`, `params`) VALUES('506f6c6c73467273744578616d706c65', 'PollsTemplate...', 0, 'Do you like ActionApps', 1194217200, 1500000000, 0, 0, 0, 0, 0, 'AA_POLLS', '506f6c6c7344657369676e4578616d70', '', '')");
        $errors[] = $this->tryQuery("INSERT IGNORE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572312e2e2e2e', '506f6c6c73467273744578616d706c65', 'Yes', 0, 1)");
        $errors[] = $this->tryQuery("INSERT IGNORE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572322e2e2e2e', '506f6c6c73467273744578616d706c65', 'No', 0, 2)");
        $errors[] = $this->tryQuery("INSERT IGNORE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572332e2e2e2e', '506f6c6c73467273744578616d706c65', 'So-so', 0, 3)");
        $errors[] = $this->tryQuery("INSERT IGNORE INTO `polls_design` (`id`, `module_id`, `name`, `comment`, `top`, `answer`, `bottom`) VALUES('506f6c6c7344657369676e4578616d70', 'PollsTemplate...', 'Click for vote', 'Theme for more than 2 answers, vote for it by clicking on image', '<div id=\"poll__#POLL_ID_\" class=\"aa_poll\">\r\n<table>\r\n<tr><td colspan=\"2\"><b>_#QUESTION</b></td></tr>', '<tr>\r\n  <td width=40%>_#ANSWER__</td>\r\n  <td><a href=\"?poll_id=_#POLL_ID_&vote_id=_#ANS_ID__\"><div style=\"height:10px; width:{poll_share:500}px;background-color:#f00\">   </div>(_#ANS_PERC %)</a></td>\r\n</tr>', '</table>\r\n</div>')");

        if ($errors = array_filter($errors)) {
            $this->message(_m('The template poll not added - the database refused it: %1', [safe(implode('; ', $errors))]));
            return false;
        }
        // last: test() takes the module for the sign that the template poll is in place
        // Metabase packs ids from 32 hex chars - the template ids are raw 16-byte strings.
        AA::Metabase()->doReplace('module', ['id' => unpack_id('PollsTemplate...'), 'owner' => unpack_id('AA_Core.........')] + self::POLLS_DEFAULT_VALUES);
        $this->message(_m('Polls module ready'));
        return true;
    }
}
