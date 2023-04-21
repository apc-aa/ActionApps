<?php
/**
 *
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\IO;


use AA\Util\NamedInterface;

class Importer implements \AA\Later\LaterInterface {
    protected $id;

    /** @var Saver  */
    protected $saver;

    protected $run=true;

    /**
     * Importer constructor.
     * (the params could be changed to saver_params, grabber_params, Transformation_params later)
     * @param Saver  $saver
     * @param bool   $run
     * @param string $id
     */
    public function __construct(Saver $saver, bool $run=true, string $id='') {
        $this->id    = $id;
        $this->saver = $saver;
        $this->run   = $run;
    }

    /**
     * @return string
     */
    public function getSources(): array {
        return $this->saver->getSources();
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
     *  @return string - message about execution to be logged
     *  @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params= []) {
        [$ok,$err] = $this->saver->run($this->run);
        $ret = "changed items OK: $ok";
        if ($err) {
            $ret .= ", error items: $err";
        }
        return $ret . (($p = $this->saver->report(1, 'plain')) ? ", $p" : '');
    }

    /** used in Report to identify the Importer in the log
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }
}