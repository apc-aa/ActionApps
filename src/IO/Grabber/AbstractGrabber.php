<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 10:56
 */

namespace AA\IO\Grabber;

use AA_Components;
use AA_Content;
use AA_Form;
use ItemContent;

/** AA\IO\AbstractGrabber\AbstractGrabber - Base class, which is used as abstraction for data input
 *  From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 *
 * @todo this class should be abstract after we switch to PHP5
 */
abstract class AbstractGrabber {
    protected $_messages = array();

    /** */
    static function factoryFromFile($file) {
        $grabbers = AA_Components::getClassNames('AA\\IO\\Grabber\\AbstractGrabber');
        exit;
    }

    /** true, if grabber returns already prepared item. false, when just parsed source structure is returned which must be converted by transformations */
    function isDirectfill() {
        return true;
    }

    /** name function
     *  Name of the grabber - used for grabber selection box
     */
    abstract public function name();

    /** description function
     *  Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description() {
    }

    /** htmlSetting function
     *  HTML code for parameters - defines parameters of this grabber.
     *  Each grabber could have its own parameters (like separator for CSV, ...)
     * @param $input_prefix
     * @param $params
     * @return string
     */
    function htmlSetting($input_prefix, $params) {     // $input_prefix - here grabber_id
        $properties = static::getClassProperties();
        $form = new AA_Form;
        foreach ($properties as $id => $property) {
            $property->addPropertyFormrows($form);
        }
        $content = new AA_Content();
        $content->setId($input_prefix);
        return $form->getRowsHtml($content);
    }

    /** getClassProperties function of AA_Serializable - used for htmlSetting()
     */
    static function getClassProperties() {
        return array();
    }

    /** getItem function
     *  Method called by the AA\IO\Saver to get next item from the data input
     * @return ItemContent
     */
    function getItem() {
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getStoreMode() method
     */
    function getIdMode() {
        return 'combined';
    }

    /** If AA\IO\Saver::store_mode is 'by_grabber' then this method tells Saver,
     *  how to store the item.
     * @see also getIdMode() method
     */
    function getStoreMode() {
        return 'insert_if_new';
    }

    /** prepare function
     *  Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
    }

    /** get data encoding - like UTF-8, windows-1250, ... */
    function getCharset() {
        return 'UTF-8';
    }

    /** finish function
     *  Function called by AA\IO\Saver after we get the last item from the data
     *  input
     */
    function finish() {
    }

    /** message function
     *  Records Error/Information message
     * @param $text
     */
    function message($text) {
        $this->_messages[] = $text;
    }

    /** report function
     * Print Error/Information messaages
     */
    function report() {
        return join('<br>', $this->_messages);
    }

    /** clear_report function
     *
     */
    function clear_report() {
        unset($this->_messages);
        $this->_messages = array();
    }
}