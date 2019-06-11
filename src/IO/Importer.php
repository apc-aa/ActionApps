<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 11.6.19
 * Time: 9:56
 */

namespace AA\IO;


class Importer implements \AA\Util\LaterInterface {
    protected $saver;

    /**
     * Importer constructor.
     * (the params could be changed to saver_params, grabber_params, Transformaton_params later)
     * @param $saver
     */
    public function __construct($saver) {
        $this->saver = $saver;
    }

    public function toexecutelater($params = array()) {
        list($ok,$err) = $this->saver->run(true);
        $ret = "changed items OK: $ok";
        if ($err) {
            $ret .= ", error items: $err, ". $this->saver->report();
        }
        return $ret;
    }

}