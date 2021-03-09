<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:07
 */

namespace AA\IO\Grabber;

use iCalFile;
use ItemContent;

/** AA\IO\AbstractGrabber\AbstractGrabber\Ical - iCal  calendar format grabber
 *  could be called like
 *     $a = new AA\IO\AbstractGrabber\AbstractGrabber\Ical('https://example.org/calendar.ics');
 */
class Ical extends AbstractGrabber
{

    protected $url;
    /** URL of .ics file */
    protected $ical;
    /** instance iCalFile */
    protected $vCalPos;
    /** pointer of vCalendar */
    protected $vComponentsTypePos;
    /** pointer of type components */
    protected $vComponentsPos;

    /** pointer of components */


    function __construct($url) {
        $this->url = $url;
    }

    /** Name of the grabber - used for grabber selection box */
    public function name(): string {
        return _m('iCal');
    }

    /** Description of the grabber - used as help text for the users.
     *  Description is in in HTML
     */
    public function description(): string {
        return _m('Import data from iCal (.ics) format');
    }

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {
        // instance of class iCalFile (external file ical.php)
        $this->ical = new iCalFile($this->url);
        // set pointers
        $this->vCalPos = 0;
        $this->vComponentsPos = 0;
        $this->vComponentsTypePos = 0;
    }

    /** Method called by the AA\IO\Saver to get next item from the data input */
    function getItem() {
        // set pointers
        $vCalPos = 0;
        $vComponentsPos = 0;
        $vComponentsTypePos = 0;

        //TODO solve empty ical->components exception ( case of empty or wrong ics file)

        //check if ical->components isn't empty (case of empty or wrong ics file)
        if (isset($this->ical->components['VCALENDAR'])) {
            //get $vcalendar index
            foreach ($this->ical->components['VCALENDAR'] as $vcalKey => $vcalItem) {
                if ($vCalPos < $this->vCalPos) {
                    $vCalPos++;
                    continue;
                }
                //chek if vcalItem-> isn't empty
                if (isset($vcalItem->components)) {
                    //get keys of components type
                    foreach ($vcalItem->components as $componentTypeKey => $componentTypeItem) {

                        if ($vComponentsTypePos < $this->vComponentsTypePos) {
                            $vComponentsTypePos++;
                            $vComponentsPos = 0;
                            continue;
                        }
                        //insert type of component
                        $content4id['type'][0]['value'] = $componentTypeKey;
                        //get index of components key
                        foreach ($componentTypeItem as $componentKey => $componentItem) {

                            if ($vComponentsPos < $this->vComponentsPos) {
                                $vComponentsPos++;
                                continue;
                            }
                            //check if is set componentItem->components
                            if (isset($componentItem->components)) {
                                //get keys of properties
                                foreach ($componentItem->components as $valueKey => $valueArray) {
                                    //get index of properties
                                    foreach ($valueArray as $valueIndex => $valueItem) {
                                        //check if exists method get_value()
                                        if (method_exists($valueItem, 'get_value')) {
                                            //fill array $content4id
                                            $content4id[$valueKey][$valueIndex]['value'] = $valueItem->get_value();
                                        }
                                    }
                                }

                                // instance of ItemContent with array content4id
                                $ic = new ItemContent($content4id);
                                //unset array content4id
                                unset($content4id);

                                $vComponentsPos++;
                                $this->vCalPos = $vCalPos;
                                $this->vComponentsPos = $vComponentsPos;
                                $this->vComponentsTypePos = $vComponentsTypePos;
                                return $ic;
                            }

                            $vComponentsPos++;
                        }

                        $vComponentsTypePos++;
                        $vComponentsPos = 0;
                        if ($vComponentsTypePos > $this->vComponentsTypePos) {
                            $this->vComponentsPos = 0;
                        }
                    }

                }
                $vCalPos++;
                $vComponentsPos = 0;
                $vComponentsTypePos = 0;
            }
        }
        return 0;
    }
}