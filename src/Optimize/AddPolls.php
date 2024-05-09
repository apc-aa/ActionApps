<?php

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use function _m;

/** Add tables for new polls module */
class AddPolls extends \AA\Optimize\AbstractOptimize {

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
        'flag'       => 0
    ];

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Add Polls tables");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Create tables for new Polls module and adds first - template polls. It removes all current polls!");
    }

    /** implemented actions within this class */
    public static function actions(): array { return ['repair']; }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array { return ['sql_update']; }

    /** Test function
     * @return string - a message
     */
    public function test(): bool {
        $ret = true;

        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM module', [['id', 'PollsTemplate...']]);
        if ($row_count <> 1) {
            $this->message(_m('Poll module is not installed'));
            $ret = false;
        }
        return $ret;
    }

    /** Deletes the pagecache - the renaming and deleting is much, much quicker,
     *  than easy DELETE FROM ...
     * @return bool
     */
    public function repair(): bool {

        $metabase = AA::Metabase();
        $this->query("DROP TABLE IF EXISTS `polls`");
        $this->query($metabase->getCreateSql('polls'));

        $this->query("DROP TABLE IF EXISTS `polls_answer`");
        $this->query($metabase->getCreateSql('polls_answer'));

        $this->query("DROP TABLE IF EXISTS `polls_design`");
        $this->query($metabase->getCreateSql('polls_design'));

        $this->query("DROP TABLE IF EXISTS `polls_ip_lock`");
        $this->query($metabase->getCreateSql('polls_ip_lock'));

        $this->query("DROP TABLE IF EXISTS `polls_log`");
        $this->query($metabase->getCreateSql('polls_log'));

        AA::Metabase()->doReplace('log', self::POLLS_DEFAULT_VALUES);
        //$this->query("REPLACE INTO module (".join(',',$keys).") VALUES ('PollsTemplate...', 'Polls Template', 0, 'P', '', 'en_polls_lang.php', 1043151515, '', 'AA_Core.........', 0)");
        $this->query("REPLACE INTO `polls` (`id`, `module_id`, `status_code`, `headline`, `publish_date`, `expiry_date`, `locked`, `logging`, `ip_locking`, `ip_lock_timeout`, `set_cookies`, `cookies_prefix`, `design_id`, `aftervote_design_id`, `params`) VALUES('506f6c6c73467273744578616d706c65', 'PollsTemplate...', 0, 'Do you like ActionApps', 1194217200, 1500000000, 0, 0, 0, 0, 0, 'AA_POLLS', '506f6c6c7344657369676e4578616d70', '', '')");
        $this->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572312e2e2e2e', '506f6c6c73467273744578616d706c65', 'Yes', 0, 1)");
        $this->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572322e2e2e2e', '506f6c6c73467273744578616d706c65', 'No', 0, 2)");
        $this->query("REPLACE INTO `polls_answer` (`id`, `poll_id`, `answer`, `votes`, `priority`) VALUES('506f6c6c73416e73776572332e2e2e2e', '506f6c6c73467273744578616d706c65', 'So-so', 0, 3)");
        $this->query("REPLACE INTO `polls_design` (`id`, `module_id`, `name`, `comment`, `top`, `answer`, `bottom`) VALUES('506f6c6c7344657369676e4578616d70', 'PollsTemplate...', 'Click for vote', 'Theme for more than 2 answers, vote for it by clicking on image', '<div id=\"poll__#POLL_ID_\" class=\"aa_poll\">\r\n<table>\r\n<tr><td colspan=\"2\"><b>_#QUESTION</b></td></tr>', '<tr>\r\n  <td width=40%>_#ANSWER__</td>\r\n  <td><a href=\"?poll_id=_#POLL_ID_&vote_id=_#ANS_ID__\"><div style=\"height:10px; width:{poll_share:500}px;background-color:#f00\">   </div>(_#ANS_PERC %)</a></td>\r\n</tr>', '</table>\r\n</div>')");

        $this->message(_m('Polls module created'));

        return true;
    }
}