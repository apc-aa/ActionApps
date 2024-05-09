<?php

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use function _m;

/** Recreates the "ActionApps Core" constants (delete and insert). **/
class RedefineCoreConstants extends \AA\Optimize\AbstractOptimize {

    /** Name function
     * @return string - a name
     */
    public static function name(): string {
        return _m("Redefine core constants");
    }

    /** Description function
     * @return string - a description
     */
    public static function description(): string {
        return _m("Updates core constants - like Code-pages, Languge Constants and AA Bins");
    }

    /** checks if this Optimize class belongs to specified type (like "sql_update") */
    public static function types(): array { return ['sql_update']; }

    /** Test function
     * @return bool
     */
    public function test(): bool {
        $ret = true;

        // Code-pages
        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM constant', [['group_id', 'lt_codepages']]);

        if ($row_count < 9) {
            $this->message(_m('Code-pages constants (lt_codepages) are not up-to-date - found: %1 from 9', [$row_count]));
            $ret = false;
        }

        // Language
        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM constant', [['group_id', 'lt_languages']]);
        if ($row_count < 46) {
            $this->message(_m('Language constants (lt_languages) are not up-to-date - found: %1 from 46', [$row_count]));
            $ret = false;
        }

        // AA Bins
        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM constant', [
            [
                'group_id',
                'AA_Core_Bins....',
            ],
        ]);
        if ($row_count < 3) {
            $this->message(_m('AA Bins constants (AA_Core_Bins....) are not up-to-date - found: %1 from 3', [$row_count]));
            $ret = false;
        }

        // APC-wide categories
        $row_count = DB_AA::select1('count', 'SELECT count(*) as count FROM constant', [
            [
                'group_id',
                'lt_apcCategories',
            ],
        ]);
        if ($row_count < 82) {
            $this->message(_m('APC-wide categories (lt_apcCategories) are not up-to-date - found: %1 from 82', [$row_count]));
            $ret = false;
        }
        return $ret;
    }


    /** Main update function
     * @return bool
     */
    public function repair(): bool {


        $this->message(_m('Deleting Code-pages, Languge Constants, AA Bins constants and APC-wide categories'));
        $this->query("DELETE FROM constant WHERE group_id IN ('lt_codepages', 'lt_languages', 'AA_Core_Bins....', 'lt_apcCategories')");

        $this->message(_m('Recreate Code-pages'));
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined000', 'lt_codepages', 'iso8859-1', 'iso8859-1', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined001', 'lt_codepages', 'iso8859-2', 'iso8859-2', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined002', 'lt_codepages', 'windows-1250', 'windows-1250', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined003', 'lt_codepages', 'windows-1253', 'windows-1253', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined004', 'lt_codepages', 'windows-1254', 'windows-1254', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined005', 'lt_codepages', 'koi8-r', 'koi8-r', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined006', 'lt_codepages', 'ISO-8859-8', 'ISO-8859-8', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined007', 'lt_codepages', 'windows-1258', 'windows-1258', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined061', 'lt_codepages', 'windows-1251', 'windows-1251', '', '100')");

        $this->message(_m('Recreate Languages'));
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined008', 'lt_languages', 'Afrikaans', 'AF', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined009', 'lt_languages', 'Arabic', 'AR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined010', 'lt_languages', 'Basque', 'EU', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined011', 'lt_languages', 'Byelorussian', 'BE', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined012', 'lt_languages', 'Bulgarian', 'BG', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined013', 'lt_languages', 'Catalan', 'CA', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined014', 'lt_languages', 'Chinese (ZH-CN)', 'ZH', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined015', 'lt_languages', 'Chinese', 'ZH-TW', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined016', 'lt_languages', 'Croatian', 'HR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined017', 'lt_languages', 'Czech', 'CS', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined018', 'lt_languages', 'Danish', 'DA', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined019', 'lt_languages', 'Dutch', 'NL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined020', 'lt_languages', 'English', 'EN-GB', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined021', 'lt_languages', 'English (EN-US)', 'EN', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined022', 'lt_languages', 'Estonian', 'ET', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined023', 'lt_languages', 'Faeroese', 'FO', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined024', 'lt_languages', 'Finnish', 'FI', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined025', 'lt_languages', 'French (FR-FR)', 'FR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined026', 'lt_languages', 'French', 'FR-CA', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined027', 'lt_languages', 'German', 'DE', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined028', 'lt_languages', 'Greek', 'EL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined029', 'lt_languages', 'Hebrew (IW)', 'HE', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined030', 'lt_languages', 'Hungarian', 'HU', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined031', 'lt_languages', 'Icelandic', 'IS', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined032', 'lt_languages', 'Indonesian (IN)', 'ID', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined033', 'lt_languages', 'Italian', 'IT', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined034', 'lt_languages', 'Japanese', 'JA', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined035', 'lt_languages', 'Korean', 'KO', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined036', 'lt_languages', 'Latvian', 'LV', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined037', 'lt_languages', 'Lithuanian', 'LT', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined038', 'lt_languages', 'Neutral', 'NEUTRAL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined039', 'lt_languages', 'Norwegian', 'NO', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined040', 'lt_languages', 'Polish', 'PL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined041', 'lt_languages', 'Portuguese', 'PT', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined042', 'lt_languages', 'Portuguese', 'PT-BR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined043', 'lt_languages', 'Romanian', 'RO', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined044', 'lt_languages', 'Russian', 'RU', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined045', 'lt_languages', 'Serbian', 'SR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined046', 'lt_languages', 'Slovak', 'SK', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined047', 'lt_languages', 'Slovenian', 'SL', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined048', 'lt_languages', 'Spanish (ES-ES)', 'ES', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined049', 'lt_languages', 'Swedish', 'SV', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined050', 'lt_languages', 'Thai', 'TH', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined051', 'lt_languages', 'Turkish', 'TR', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined052', 'lt_languages', 'Ukrainian', 'UK', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined053', 'lt_languages', 'Vietnamese', 'VI', '', '100')");

        $this->message(_m('Recreate AA Bins'));
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined058', 'AA_Core_Bins....', 'Approved', '1', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined059', 'AA_Core_Bins....', 'Holding Bin', '2', '', '200')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined060', 'AA_Core_Bins....', 'Trash Bin', '3', '', '300')");

        $this->message(_m('APC wide categories'));
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined100', 'lt_apcCategories', 'Internet & ICT', 'Internet & ICT', '', '100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined101', 'lt_apcCategories', 'Internet & ICT - Free software & Open Source', 'Internet & ICT - Free software & Open Source', '', '110')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined102', 'lt_apcCategories', 'Internet & ICT - Access', 'Internet & ICT - Access', '', '120')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined103', 'lt_apcCategories', 'Internet & ICT - Connectivity', 'Internet & ICT - Connectivity', '', '130')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined104', 'lt_apcCategories', 'Internet & ICT - Women and ICT', 'Internet & ICT - Women and ICT', '', '140')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined105', 'lt_apcCategories', 'Internet & ICT - Rights', 'Internet & ICT - Rights', '', '150')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined106', 'lt_apcCategories', 'Internet & ICT - Governance', 'Internet & ICT - Governance', '', '160')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined107', 'lt_apcCategories', 'Development', 'Development', '', '200')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined108', 'lt_apcCategories', 'Development - Resources', 'Development - Resources', '', '210')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined109', 'lt_apcCategories', 'Development - Structural adjustment', 'Development - Structural adjustment', '', '220')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined110', 'lt_apcCategories', 'Development - Sustainability', 'Development - Sustainability', '', '230')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined111', 'lt_apcCategories', 'News and media', 'News and media', '', '300')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined112', 'lt_apcCategories', 'News and media - Alternative', 'News and media - Alternative', '', '310')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined113', 'lt_apcCategories', 'News and media - Internet', 'News and media - Internet', '', '320')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined114', 'lt_apcCategories', 'News and media - Training', 'News and media - Training', '', '330')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined115', 'lt_apcCategories', 'News and media - Traditional', 'News and media - Traditional', '', '340')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined116', 'lt_apcCategories', 'Environment', 'Environment', '', '400')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined117', 'lt_apcCategories', 'Environment - Agriculture', 'Environment - Agriculture', '', '410')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined118', 'lt_apcCategories', 'Environment - Animal rights/protection', 'Environment - Animal rights/protection', '', '420')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined119', 'lt_apcCategories', 'Environment - Climate', 'Environment - Climate', '', '430')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined120', 'lt_apcCategories', 'Environment - Biodiversity/conservetion', 'Environment - Biodiversity/conservetion', '', '440')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined121', 'lt_apcCategories', 'Environment - Energy', 'Environment - Energy', '', '450')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined122', 'lt_apcCategories', 'Environment - Campaigns', 'Environment - Campaigns', '', '455')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined123', 'lt_apcCategories', 'Environment - Legislation', 'Environment - Legislation', '', '460')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined124', 'lt_apcCategories', 'Environment - Genetics', 'Environment - Genetics', '', '465')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined125', 'lt_apcCategories', 'Environment - Natural resources', 'Environment - Natural resources', '', '470')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined126', 'lt_apcCategories', 'Environment - Rural development', 'Environment - Rural development', '', '475')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined127', 'lt_apcCategories', 'Environment - Transport', 'Environment - Transport', '', '480')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined128', 'lt_apcCategories', 'Environment - Urban ecology', 'Environment - Urban ecology', '', '485')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined129', 'lt_apcCategories', 'Environment - Pollution & waste', 'Environment - Pollution & waste', '', '490')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined130', 'lt_apcCategories', 'NGOs', 'NGOs', '', '500')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined131', 'lt_apcCategories', 'NGOs - Fundraising', 'NGOs - Fundraising', '', '510')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined132', 'lt_apcCategories', 'NGOs - Funding agencies', 'NGOs - Funding agencies', '', '520')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined133', 'lt_apcCategories', 'NGOs - Grants/scholarships', 'NGOs - Grants/scholarships', '', '530')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined134', 'lt_apcCategories', 'NGOs - Jobs', 'NGOs - Jobs', '', '540')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined135', 'lt_apcCategories', 'NGOs - Management', 'NGOs - Management', '', '550')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined136', 'lt_apcCategories', 'NGOs - Volunteers', 'NGOs - Volunteers', '', '560')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined137', 'lt_apcCategories', 'Society', 'Society', '', '600')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined138', 'lt_apcCategories', 'Society - Charities', 'Society - Charities', '', '610')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined139', 'lt_apcCategories', 'Society - Community', 'Society - Community', '', '620')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined140', 'lt_apcCategories', 'Society - Crime & rehabilitation', 'Society - Crime & rehabilitation', '', '630')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined141', 'lt_apcCategories', 'Society - Disabilities', 'Society - Disabilities', '', '640')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined142', 'lt_apcCategories', 'Society - Drugs', 'Society - Drugs', '', '650')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined143', 'lt_apcCategories', 'Society - Ethical business', 'Society - Ethical business', '', '660')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined144', 'lt_apcCategories', 'Society - Health', 'Society - Health', '', '670')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined145', 'lt_apcCategories', 'Society - Law and legislation', 'Society - Law and legislation', '', '675')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined146', 'lt_apcCategories', 'Society - Migration', 'Society - Migration', '', '680')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined147', 'lt_apcCategories', 'Society - Sexuality', 'Society - Sexuality', '', '685')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined148', 'lt_apcCategories', 'Society - Social services and welfare', 'Society - Social services and welfare', '', '690')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined149', 'lt_apcCategories', 'Economy & Work', 'Economy & Work', '', '700')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined150', 'lt_apcCategories', 'Economy & Work - Informal Sector', 'Economy & Work - Informal Sector', '', '710')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined151', 'lt_apcCategories', 'Economy & Work - Labour', 'Economy & Work - Labour', '', '720')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined152', 'lt_apcCategories', 'Culture', 'Culture', '', '800')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined153', 'lt_apcCategories', 'Culture - Arts and literature', 'Culture - Arts and literature', '', '810')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined154', 'lt_apcCategories', 'Culture - Heritage', 'Culture - Heritage', '', '820')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined155', 'lt_apcCategories', 'Culture - Philosophy', 'Culture - Philosophy', '', '830')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined156', 'lt_apcCategories', 'Culture - Religion', 'Culture - Religion', '', '840')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined157', 'lt_apcCategories', 'Culture - Ethics', 'Culture - Ethics', '', '850')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined158', 'lt_apcCategories', 'Culture - Leisure', 'Culture - Leisure', '', '860')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined159', 'lt_apcCategories', 'Human rights', 'Human rights', '', '900')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined160', 'lt_apcCategories', 'Human rights - Consumer Protection', 'Human rights - Consumer Protection', '', '910')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined161', 'lt_apcCategories', 'Human rights - Democracy', 'Human rights - Democracy', '', '920')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined162', 'lt_apcCategories', 'Human rights - Minorities', 'Human rights - Minorities', '', '930')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined163', 'lt_apcCategories', 'Human rights - Peace', 'Human rights - Peace', '', '940')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined164', 'lt_apcCategories', 'Education', 'Education', '', '1000')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined165', 'lt_apcCategories', 'Education - Distance learning', 'Education - Distance learning', '', '1010')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined166', 'lt_apcCategories', 'Education - Non-formal education', 'Education - Non-formal education', '', '1020')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined167', 'lt_apcCategories', 'Education - Schools', 'Education - Schools', '', '1030')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined168', 'lt_apcCategories', 'Politics & Government', 'Politics & Government', '', '1100')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined169', 'lt_apcCategories', 'Politics & Government - Internet', 'Politics & Government - Internet', '', '1110')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined170', 'lt_apcCategories', 'Politics & Government - Local', 'Politics & Government - Local', '', '1120')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined171', 'lt_apcCategories', 'Politics & Government - Policies', 'Politics & Government - Policies', '', '1130')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined172', 'lt_apcCategories', 'Politics & Government - Administration', 'Politics & Government - Administration', '', '1140')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined173', 'lt_apcCategories', 'People', 'People', '', '1200')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined174', 'lt_apcCategories', 'People - Children', 'People - Children', '', '1210')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined175', 'lt_apcCategories', 'People - Adolescents/teenagers', 'People - Adolescents/teenagers', '', '1220')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined176', 'lt_apcCategories', 'People - Gender', 'People - Gender', '', '1230')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined177', 'lt_apcCategories', 'People - Older people', 'People - Older people', '', '1240')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined178', 'lt_apcCategories', 'People - Family', 'People - Family', '', '1250')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined179', 'lt_apcCategories', 'World', 'World', '', '1300')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined180', 'lt_apcCategories', 'World - Globalization', 'World - Globalization', '', '1310')");
        $this->query("INSERT INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined181', 'lt_apcCategories', 'World - Debt', 'World - Debt', '', '1320')");

        $this->message(_m('Make sure, the constants are registered'));
        $this->query("REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined054', 'lt_groupNames', 'Code Pages', 'lt_codepages', '', '0')");
        $this->query("REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined055', 'lt_groupNames', 'Languages Shortcuts', 'lt_languages', '', '1000')");
        $this->query("REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined057', 'lt_groupNames', 'AA Core Bins', 'AA_Core_Bins....', '', '10000')");
        $this->query("REPLACE INTO constant (id, group_id, name, value, class, pri) VALUES( 'AA-predefined056', 'lt_groupNames', 'APC-wide Categories', 'lt_apcCategories', '', '1000')");

        $this->message(_m('Redefine core constants - done.'));
        return true;
    }
}