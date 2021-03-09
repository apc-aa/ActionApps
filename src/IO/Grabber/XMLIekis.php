<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:06
 */

namespace AA\IO\Grabber;

use ItemContent;

/** AA\IO\AbstractGrabber\AbstractGrabber\XMLIekis - grabs data from XML files in one directory
 */
class XMLIekis extends AbstractGrabber
{

    protected $dir;
    /** directory, where teh files are */
    private $_files;

    /** list if files to grab - internal array */

    function __construct($dir) {
        $this->dir = $dir;
    }

    /** Name of the grabber - used for grabber selection box */
    public function name(): string {
        return _m('iEKIS XML files');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('special iEKIS XML files in one directory');
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        $this->_files = [];
        // instance of class iCalFile (external file ical.php)
        if ($handle = opendir($this->dir)) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != "..") {
                    $this->_files[] = $file;
                }
            }
            closedir($handle);
        }
        sort($this->_files);
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        if (!($file = current($this->_files))) {
            return false;
        }
        next($this->_files);

        $xml = simplexml_load_file($this->dir . $file);
        $att = $xml->attributes();

        foreach ($xml->text as $key => $text) {
            $text_att = $text->attributes();
            switch ($text_att['typ']) {
                case 'otazka':
                    $t0a = $text_att;
                    $t0 = (string)$text;
                    break;
                case 'odpoved':
                    $t1a = $text_att;
                    $t1 = (string)$text;
                    break;
            }
        }

        $item = new ItemContent();
        $item->setValue('number.........1', $att['idp']);
        $item->setValue('unspecified.....', $att['obor']);
        $item->setValue('publish_date....', strtotime($att['datum'] . ' ' . $att['cas']));
        $item->setValue('post_date.......', strtotime($att['datum'] . ' ' . $att['cas']));
        $item->setValue('unspecified....1', $att['stav']);
        $item->setValue('unspecified....2', $att['kod']);
        $item->setValue('unspecified....3', $att['pristupne']);
        $item->setValue('headline........', $att['predmet']);
        $item->setValue('text...........1', $t0a['jmeno']);
        $item->setValue('con_email.......', $t0a['email']);
        $item->setValue('address.........', $t0a['kontakt']);
        $item->setValue('unspecified....4', $t0a['predmet']);
        $item->setValue('start_date......', strtotime($t1a['datum'] . ' ' . $t1a['cas']));
        $item->setValue('unspecified....5', $t1a['expert']);
        $item->setValue('unspecified....6', $t1a['predmet']);
        $item->setValue('text...........4', $t0);
        $item->setValue('text...........5', $t1);
        $item->setValue('edit_note.......', 'importovano ze stareho i-ekis.cz - ' . $file);
        $item->setValue('switch.........2', '1');  // ulozit pro mpo
        $item->setValue('switch..........', '0');  // storno

        $TEMAS = [
            '1' => 'b2269049caf8e47737b718e62ccc905c',   // Územní energetické koncepce
            '2' => 'b2269049caf8e47737b718e62ccc905c',   // Akční plány
            '3' => 'afbab0cf8bfb524ce48e50862fb8fa88',   // Energetické audity a průkazy (99)
            '4' => 'b2325f0658cab17838f8ce6e49a26688',   // Kotle a kotelny (524)
            '5' => '09952314df2e76d4fc9cd1dc8baec9ae',   // Energie slunce (237)
            '6' => '144f1726c94eb7e86a491dea2bc84021',   // Energie vody (131)
            '7' => 'ae02cc88721e77af713d30c766834e9f',   // Energie větru (180)
            '8' => '1754f99f9d83562c81d99a28818cf585',   // Energie biomasy (332)
            '9' => '1754f99f9d83562c81d99a28818cf585',   // Využití bioplynů (35)
            '10' => '18804c2b8e611fba74ceb46038494e68',   // Využití odpadního tepla (13)
            '11' => '442340d0d7ff8a68c76902a2da083d44',   // Tepelná čerpadla (153)
            '12' => '5f4fe6025c68814d449f29e3f1cdd01a',   // Palivové články (6)
            '13' => '49282fdb6ee5d9c524cd5552417b855a',   // Elektrické vytápění (247)
            '14' => '1b2fbed9465f609835c51841620d8b6a',   // Kogenerace, trigenerace (35)
            '15' => '5941e5f49d61ac033130660ea394122d',   // Měření a regulace (594)
            '16' => 'b2269049caf8e47737b718e62ccc905c',   // Rekonstrukce rozvodů sídlištního celku
            '17' => 'b2269049caf8e47737b718e62ccc905c',   // Rekonstrukce otopné soustavy v objektu
            '18' => '5f93ea7654fafea8cf29ddb8b80351ef',   // Úsporná opatření v průmyslu (12)
            '19' => 'b2269049caf8e47737b718e62ccc905c',   // Monitoring a targeting (1)
            '20' => 'b2269049caf8e47737b718e62ccc905c',   // Moderní postupy, technologie a materiály
            '21' => 'a8e078b5bf57ec58a2c7245f71f0d3d4',   // Zateplování objektů (2101)
            '22' => '15efaadbec739b0965a098da205a0d31',   // Nízkoenergetické a pasivní domy (179)
            '23' => '3c3e0d17813eccc67afd3a267329595a',   // Projekty energetických služeb se zárukou
            '24' => '3c3e0d17813eccc67afd3a267329595a',   // Financování z fondů Evropské unie
            '25' => 'b2269049caf8e47737b718e62ccc905c'    // Ostatní (536)
        ];

        if ($TEMAS[(string)$att['obor']]) {
            $item->setValue('relation.......2', $TEMAS[(string)$att['obor']]);
        }

        return $item;
    }
}