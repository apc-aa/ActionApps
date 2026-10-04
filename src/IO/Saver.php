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

use AA;
use AA\IO\Grabber\AbstractGrabber;
use AA\Later\BatchBudget;
use AA\Util\ReportTrait;
use AA_Condition;
use AA_Item;
use AA_Set;
use AA_Transformation;
use Exception;
use ItemContent;
use UnexpectedValueException;
use zids;

/** Saver class - Used for filling items into AA
 * @param AbstractGrabber grabber          - provides source for item data
 * @param $transformations - defines, how the source item should be transformed
 *                            into destination item
 */
class Saver implements AA\Later\LaterInterface
{
    use ReportTrait {
        report as protected traitreport;
    }

    /** @var AbstractGrabber the object which delivers the data */
    protected $grabber;

    /** describes, what to do with data before storing
     *  @var AA_Transformation[]
     */
    protected $transformations;

    /** id of destination slice */
    protected $slice_id;

    /** store-policy - how to store - by_grabber | update | update_silent | update/insert | add | overwrite | insert_as_new | insert_new | insert | insert_if_new */
    protected string $store_mode;

    /** id-policy    - how to construct id - old | new | combined | ["<field_id.......1>",...] */
    protected string $id_mode;

    /** how to deal with errors LOG | STRICT */
    protected string $error_mode;

    /** [{aa-expression},..] - expression to validate the inserted item - if set, all rows must begin with '1' or contain just white characters for storing the item
     */
    private array $validate = [];

    /** [{aa-expression},..] - expression to compute - if set, all rows are evaluated (unaliased) - could be used with $fire=false to test inserted data  */
    private array $compute = [];

    /** key for compute array - probably like {id}, ...   */
    private ?string $compute_key = null;

    /** [key=>[aa-expression-result,..],..] - computed expressions (with computed keys) for all items */
    private array $compute_results = [];

    /** array of newly inserted ids (after run()) - the long ones */
    private array $_new_ids = [];

    /** array of ids of rewritten items (after run()) - the long ones */
    private array $_updated_ids = [];

    /** array of slice ids with rewritten items (after run()) - the long ones */
    private array $_updated_slices = [];

    /** the last run() took items until the grabber had no more */
    private bool $reached_end = false;

    // protected $_messages = [];


    /** AA\IO\Saver function
     *
     * @param AbstractGrabber  $grabber
     * @param AA_Transformation[]             $transformations
     * @param string                          $slice_id
     * @param  string                         $store_mode  @see  Saver::$store_mode
     * @param  string                         $id_mode
     * @param  string                         $error_mode
     */
    public function __construct(AbstractGrabber $grabber, $transformations = null, $slice_id = null, string $store_mode = '', string $id_mode = '', string $error_mode = '')
    {
        $this->grabber         = $grabber;
        $this->transformations = is_array($transformations) ? $transformations : [];
        $this->slice_id        = $slice_id;
        $this->store_mode      = $store_mode ?: 'overwrite';
        $this->id_mode         = $id_mode ?: 'old';
        $this->error_mode      = $error_mode ?: 'LOG';
    }

    /** resets item / slice counters */
    public function clear_ids(): void
    {
        $this->_new_ids = [];
        $this->_updated_ids = [];
        $this->_updated_slices = [];
    }

    /** run function
     * Now import all items to the slice
     *
     * The budget and the limit are checked before prepare() and before each item, so an item
     * taken is always processed to the end; reachedEnd() tells whether the grabber ran out.
     * The grabber's finish() follows every regular end, early stops included - not an exception
     * from the loop, a failed prepare() or a budget spent before prepare(). finish() does not
     * mean all was read: AARSS stores its newest-item mark there, so do not stop it early.
     *
     * @param  bool         $fire
     * @param ?BatchBudget  $budget its time; no further item is taken once spent() - null = no limit
     * @param  int          $limit  at most this many items taken from the grabber (stored or not); 0 = all
     *
     * @return int[] count of [changed,error] items
     */
    public function run(bool $fire = true, ?BatchBudget $budget = null, int $limit = 0): array
    {
        $this->reached_end = false;
        if ($budget?->spent()) {
            return [0, 0];
        }
        try {
            $this->grabber->prepare();    // maybe some initialization in grabber
        } catch (Exception $e) {
            $this->message("grabber->prepare() Error: " . $e->getMessage());
            return [0,1];
        }

        // returns grabber prepared item of just parsed source structure, which have to be converted to item by transformations?
        $directfill = $this->grabber->isDirectfill();

        $ok_count = 0;
        $err_count = 0;
        $taken = 0;

        while (!$this->outOfBudget($budget, $limit, $taken)) {
            if (!($source_content4id = $this->grabber->getItem())) {
                $this->reached_end = true;
                break;
            }
            $taken++;
            if (!$fire) {
                $this->message("AA\IO\Saver->run(): grabbed item: ", 2);
                $this->message($source_content4id);
            }

            // check, if grabber returns already prepared item
            $content4id = $directfill ? $source_content4id : new ItemContent();
            if (!($content4id instanceof ItemContent)) {
                // storing needs ItemContent - a direct-fill grabber must hand it over
                throw new UnexpectedValueException('Grabber ' . $this->grabber::class . ' returned ' . $content4id::class . ' instead of ItemContent');
            }

            // if we want some Transformations, do them here
            foreach ($this->transformations as $field_id => $transformation) {
                $field_content = $transformation->transform($field_id, $source_content4id);
                if (!$field_content) {
                    // no need to change, or something goes wrong - missing parameter for transformation, ....
                    continue;
                }
                $field_content->removeDuplicates();
                $content4id->setAaValue($field_id, $field_content);
            }

            // now the $content4id should be filled in both cases - directfill and indirect

            $id_mode = ($this->id_mode === 'by_grabber') ? $this->grabber->getIdMode() : $this->id_mode;
            $store_mode = ($this->store_mode === 'by_grabber') ? $this->grabber->getStoreMode() : $this->store_mode;

            if (!$fire) {
                $this->message("AA\IO\Saver->run(): id_mode: $id_mode, store_mode: $store_mode, transformed item: ", 2);
                $this->message($content4id, 2);
            }

            switch ($id_mode) {
                // Create new item id (always the same for item-slice pair)
                case 'combined':
                    $new_item_id = string2id($content4id->getItemID() . $this->slice_id);
                    break;

                    // Use id from source
                case 'old':
                    $new_item_id = $content4id->getItemID();
                    break;

                    // Generate completely new id
                    //default         :
                    //case 'new'      : $new_item_id = new_id();                 break;


                case '':
                case 'new':
                    $new_item_id = new_id();
                    break;

                    // ["relation.......1","year............"]
                default:
                    // should be allowed just to slice admins (for security reasons)
                    if (($id_mode[0] === '[') && is_array($flds = json_decode($id_mode))) {
                        // this allows you to update item based on the key fields.
                        // if $id_mode is ["relation.......1","year............"], then saver searches the slice for the item matching the two fields.
                        // If found, then the item is updated
                        // If not found, the item is created, but only with fields if $content4id (not whole insert)
                        // update and insert_if_new restrict previous just for updates or inserts

                        $set = new AA_Set([$this->slice_id]);
                        foreach ($flds as $fld) {
                            $condval = $content4id->getValue($fld);
                            if (trim($condval) === '') {
                                $err_count++;
                                $this->message(['err' => "AA\IO\Saver->run(): key condition empty for $fld"]);
                                continue 3; // another item
                            }

                            $set->addCondition(new AA_Condition($fld, '==', $content4id->getValue($fld)));
                        }
                        $zids = $this->findByKey($set);
                        if (!$fire) {
                            $this->message("AA\IO\Saver->run(): update/insert - condition:", 2);
                            $this->message($set, 2);
                            $this->message($zids, 2);
                        }
                        if (!$zids->isEmpty()) {
                            if (!str_contains($store_mode, 'update')) {
                                continue 2; // another item
                            }
                            $new_item_id = $zids->longids(0);
                        } else {
                            if (!str_contains($store_mode, 'insert')) {
                                continue 2; // another item
                            }
                            $new_item_id = new_id();
                        }
                        break;
                    }
                    $new_item_id = new_id();
            }

            $old_item_id = $content4id->getItemID();

            // set the item to be received from remote node
            $content4id->setItemID($new_item_id);

            // @todo - move to translations
            if (!is_null($this->slice_id)) {
                // for AA\IO\AbstractGrabber\AbstractGrabber\Form we have the slice_id already filled
                $content4id->setSliceID($this->slice_id);
            }

            AA::$slice_id = $content4id->getSliceID();   // pass validator the current slice of added item for slice unique

            $item = ($this->compute || $this->validate) ? new AA_Item($content4id) : null;

            if ($this->validate && ($err_arr = $this->test_validate($item))) {
                $err_count++;
                $this->message(['err' => "AA\IO\Saver->run(): validate failed: " . implode("\n", $err_arr)]);
                continue;
            }

            if ($store_mode === 'overwrite' || str_starts_with($store_mode, 'insert')) {
                if (!$content4id->complete4Insert($this->error_mode)) {
                    $err_count++;
                    $this->message(['err' => "AA\IO\Saver->run(): complete4Insert failed: " . ItemContent::lastErrMsg()]);
                    continue;
                }
            } elseif (!$content4id->validate4Update()) {
                // @todo do validation for updates
                $err_count++;
                $this->message(['err' => "AA\IO\Saver->run(): validate4Update failed: " . ItemContent::lastErrMsg()]);
                continue;
            }

            if ($this->compute) {
                is_null($this->compute_key)
                    ? $this->compute_results[] = $this->compute_arr($this->compute, $item)
                    : $this->compute_results[trim(AA::Un($this->compute_key, '', $item))] = $this->compute_arr($this->compute, $item);
            }

            $this->_updated_slices[$content4id->getOwnerId()] = true;

            if ($fire) {
                // id_mode - overwrite or insert_if_new
                // (the $new_item_id should not be changed by storeItem)
                if (!($new_item_id = $this->store($content4id, $store_mode))) {     // invalidatecache, feed
                    // AA\IO\Saver->run(): storeItem failed or skiped duplicate:
                    $err_count++;
                    $this->message(['err' => 'AA\IO\Saver->run(): storeItem failed or skipped duplicate: ' . ItemContent::lastErrMsg()]);
                } else {
                    // @todo better check of new ids
                    if ($store_mode === 'insert') {
                        $this->_new_ids[] = $new_item_id;
                    } else {
                        $this->_updated_ids[] = $new_item_id;
                    }
                    $ok_count++;

                    // Update relation table to show where came from
                    if ($old_item_id && $new_item_id != $old_item_id) {
                        AddRelationFeed($new_item_id, $old_item_id, REL_FLAG_SOURCE);
                    }
                }
            } else {
                $ok_count++;
                $this->message("AA\IO\Saver->run(): would be stored ID / content: " . ItemContent::lastErrMsg(), 2);
                $this->message($new_item_id, 2);
                $this->message($content4id);
            }
        } // while grabber->getItem()
        $this->grabber->finish();    // maybe some finalization in grabber

        $this->message("AA\IO\Saver->run(): finish - $ok_count OK, $err_count Err." . ItemContent::lastErrMsg());

        if (!$fire) {
            print($this->report());
        }
        return [$ok_count, $err_count];
    }

    /** true when the last run() took items until the grabber had none left. False after a stop at
     *  the budget or limit - even one hit at the last item, the grabber is not asked again - and
     *  after a failed prepare(). */
    public function reachedEnd(): bool
    {
        return $this->reached_end;
    }

    private function outOfBudget(?BatchBudget $budget, int $limit, int $taken): bool
    {
        return (($limit > 0) && ($taken >= $limit)) || ($budget?->spent() ?? false);
    }

    /** The item the key fields of an incoming item point to - at most one
     *  @param AA_Set $set the destination slice with one == condition per key field
     */
    protected function findByKey(AA_Set $set): zids
    {
        return $set->query(null, 1);
    }

    /** Stores the item
     *  @param array $flags passed to ItemContent::storeItem()
     *  @return string|false the id of the stored item, false when nothing was stored
     */
    protected function store(ItemContent $content, string $mode, array $flags = []): string|false
    {
        return $content->storeItem($mode, $flags);
    }

    /** Checks if the item is valid based on custom validate string
     * @param  AA_Item|null  $item
     * @return array array of error strings (empty array [] on success
     */
    protected function test_validate(?AA_Item $item): array
    {
        $err_array    = [];
        $unaliased = $this->compute_arr($this->validate, $item);
        foreach ($unaliased as $validate_str) {
            $array        = preg_split("/\R/", $validate_str);
            foreach ($array as $row) {
                $row = trim($row);
                if ($row && $row[0] !== '1') {
                    $err_array[] = $row;
                }
            }
        }
        return $err_array;
    }

    /** unalias all the strings in the array - used in validate and compute */
    private function compute_arr(array $arr, ?AA_Item $item): array
    {
        return array_map(static fn($exp) => trim(AA::Un($exp, '', $item)), $arr);
    }

    /** Returns array of long new saved ids */
    public function newIds(): array
    {
        return $this->_new_ids;
    }

    /** Returns array of long updated ids */
    public function updatedIds(): array
    {
        return $this->_updated_ids;
    }

    public function changedIds(): array
    {
        return array_merge($this->_new_ids, $this->_updated_ids);
    }

    public function changedModules(): array
    {
        return array_keys($this->_updated_slices);
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     *  You can use $toexecute->later($saver, array(), 'feed_external') instead
     *  of calling $saver->run() - the saving will be planed for future
     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
     *  @return string - message about execution to be logged
     *  @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        $ret = $this->run();
        return "OK: $ret[0], Err: $ret[1]";
    }

    /** overrides the report from the trait - we are adding the Grabber report as well
     * @param int    $verbosity
     * @param string $type
     * @return string - messages in table
     */
    public function report(int $verbosity = 1, string $type = 'html'): string
    {
        return $this->traitreport($verbosity, $type) . $this->grabber->report($verbosity, $type);
    }

    /** loads all the data based on grabber setting and return it */
    public function getSources(): array
    {
        return $this->grabber->getSources();
    }

    /**
     * @param string $validate
     * {aa-expression} - expression to validate the inserted item - if set, must be equal to '1' for storing the item
     */
    public function addValidate(string $validate): static
    {
        $this->validate[] = $validate;
        return $this;
    }

    /**
     * @param  array        $texts
     * @param  string|null  $key
     *
     * @return Saver
     */
    public function setCompute(array $texts, ?string $key = null): static
    {
        $this->compute     = $texts;
        $this->compute_key = $key;
        return $this;
    }

    /** Returns result of computes */
    public function getComputeResults(): array
    {
        return $this->compute_results;
    }
}
