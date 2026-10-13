<?php

//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/** Removes empty positions and normalizes keys to be from 0 to x with step of 1
*/
function normalize_arr($arr): array
{
    $ret = [];
    if (isset($arr) && is_array($arr)) {
        foreach ($arr as $v) {
            if (isset($v)) {
                $ret[] = $v;
            }
        }
    }
    return $ret;
}

class AA_Site_Spot_Match
{
    public $op;
    public $val;

    public function __construct($op = '', $val = '')
    {
        $this->op  = $op;
        $this->val = $val;
    }

    public static function factoryByString($string, $var = null): AA_Site_Spot_Match
    {
        if (str_starts_with($string, '=:')) { // begins with =: - special identification of new matches
            $arr = ParamExplode($string);
            return new AA_Site_Spot_Match($arr[1], $arr[2]);
        }
        // special case - for {aa_expressions} we used "=" in history
        return new AA_Site_Spot_Match(($var && $var[0] === '{') ? '=' : 'REGEXP', $string);
    }

    public function implode(): string
    {
        return ParamImplode(['=',$this->op, $this->val]);
    }

    public function match($value): bool
    {
        return match ($this->op) {
            '='        => ($value == $this->val),
            'REGEXP'   => (bool) preg_match('/' . str_replace('/', '\/', $this->val) . '/', $value),
            'contains' => (strlen($value) && str_contains($this->val, $value)),
            default    => false,
        };
    }
}

class spot
{
    public $id;          // spot id
    public $n;           // spot name
    public $c;           // spot conditions
    public $v;           // spot variables
    public $p;           // id of parent spot
    public $po;          // positions - array of spot ids defining the sequence
    public $ch;          // choices - array of spot ids defining the choices for the spot
    public $f;           // flags
    // the names of variables are short in order the outpot of serialize() function
    // would be as short as possible

    /** @var string[] translation from long variable names to the current - shorter
     *  @todo grab it form AA_Metabase
     */
    public const SPOT_VAR_NAMES =  [
        'id'         => 'id',
        'name'       => 'n',
        'conditions' => 'c',
        'variables'  => 'v',
        'parent'     => 'p',
        'positions'  => 'po',
        'choices'    => 'ch',
        'flag'       => 'f',
    ];

    public function __construct($id = false, $name = false, $conditions = false, $variables = false, $parent = false, $positions = false, $choices = false, $flag = 0)
    {
        $this->id = $id;
        $this->n  = $name;
        $this->c  = $conditions; // Array of conditions to match to be this
        // branch executed

        $this->v  = $variables;   // Array of variable names used in
        // branching. The only spots with variables
        // defined may branch the code
        $this->p  = $parent;
        $this->po = $positions;
        $this->ch = $choices;
        $this->f  = $flag;
    }

    public function addInSequence($spot): void
    {
        $this->po[] = $spot->Id();
        $spot->set('parent', $this->Id());  // set/repair parent of inserted spot
    }

    public function addChoice($spot): void
    {
        $this->ch[] = $spot->Id();
        $spot->set('parent', $this->Id());  // set/repair parent of inserted spot
    }

    public function addVariable($name): void
    {
        $this->v[$name] = $name;
    }

    public function removeVariable($name): void
    {
        unset($this->v[$name]);
    }

    public function addCondition($var, $match): void
    {
        $this->c[$var] = $match->implode();
    }

    public function removeCondition($name): void
    {
        unset($this->c[$name]);
    }

    public function isLeaf(): bool
    {
        return ((!is_array($this->ch) || (count($this->ch) < 1)) && (!is_array($this->po) || (count($this->po) < 2)));
    }

    /** Returns true, if the $spot_id is in sequence of this spot (position) */
    public function isSequence($spot_id): bool
    {
        return (false !== ($k = array_search($spot_id, (array) $this->po)));
    }

    /** Returns true, if the $spot_id is choice of this spot */
    public function isChoice($spot_id): bool
    {
        return (false !== ($k = array_search($spot_id, (array) $this->ch)));
    }

    /** Check the positions (po) array and choices (ch) array,
    *  and fixes possible problems
    *     - removes empty positions (where they come from?)
    *     - normalizes keys to be from 0 to .. with step of 1
    */
    public function normalize(): void
    {
        $this->po = normalize_arr($this->po);
        $this->ch = normalize_arr($this->ch);
    }

    public function removeSpot($spot_id)
    {
        // search in options
        $priorsib = $this->id;
        if (isset($this->ch) and is_array($this->ch)) {
            foreach ($this->ch as $k => $v) {
                if ($v == $spot_id) {
                    unset($this->ch[$k]);
                    return $priorsib;   // Returning prior sibling
                }
                $priorsib = $v;  // Used for where to move pointer to
            }
        }
        //search in sequence
        if (isset($this->po) and is_array($this->po)) {
            foreach ($this->po as $k => $v) {
                if ($v == $spot_id) {
                    unset($this->po[$k]);
                    return $priorsib;   // Returning prior sibling
                }
                $priorsib = $v;  // Used for where to move pointer to
            }
        }
        return false;
    }

    public function moveUp($spot_id): bool
    {
        $this->normalize();  // just check, if there are no problems in this spot

        // search in options
        if (false !== ($k = array_search($spot_id, (array) $this->ch))) {
            if ($k == 0) {
                return false;
            }
            $this->ch[$k]   = $this->ch[$k - 1];
            $this->ch[$k - 1] = $spot_id;
            return true;
        }
        //search in sequence
        if (false !== ($k = array_search($spot_id, (array) $this->po))) {
            if ($k <= 1) {   // can't move to the first position in sequence
                return false;
            }
            $this->po[$k]   = $this->po[$k - 1];
            $this->po[$k - 1] = $spot_id;
            return true;
        }
        return false;
    }

    public function moveDown($spot_id): bool
    {
        $this->normalize();  // just check, if there are no problems in this spot

        // search in options
        if (false !== ($k = array_search($spot_id, (array) $this->ch))) {
            if ($k == count($this->ch) - 1) { // last
                return false;
            }
            $this->ch[$k]   = $this->ch[$k + 1];
            $this->ch[$k + 1] = $spot_id;
            return true;
        }
        //search in sequence
        if (false !== ($k = array_search($spot_id, (array) $this->po))) {
            if ($k == 0 || $k == count($this->po) - 1) {    // can't move to the first position in sequence
                return false;
            }
            $this->po[$k]   = $this->po[$k + 1];
            $this->po[$k + 1] = $spot_id;
            return true;
        }
        return false;
    }

    public function Name()
    {
        return $this->n;
    }
    public function Id()
    {
        return $this->id;
    }
    public function Conditions()
    {
        return $this->c;
    }
    public function Variables()
    {
        return $this->v;
    }

    public function get_translated($what)
    {
        return $this->$what;
    }
    public function get($what)
    {
        return $this->get_translated(self::SPOT_VAR_NAMES[$what]);
    }

    public function set_translated($what, $value): void
    {
        $this->$what = $value;
    }
    public function set($what, $value): void
    {
        $this->set_translated(self::SPOT_VAR_NAMES[$what], $value);
    }

    public function conditionMatches($state): bool
    {
        if (isset($this->c) and is_array($this->c)) {  //c is array of conditions
            foreach ($this->c as $var => $cond) {
                $value = ($var[0] === '{') ? AA::Un($var, '', $state['item']) : $state[$var];
                if (!(AA_Site_Spot_Match::factoryByString($cond, $var)->match($value))) {
                    return false;
                }
            }
        }
        return true;
    }
}

class sitetree
{
    use AA\Util\SerializerTrait;

    /** @var spot[] -  Array of spots */
    private array $tree;
    private $start_id;

    /** @var int Current spot id */
    private int $current_id;

    public function __construct($spot = false)
    {
        $this->tree[1]    = new spot($spot['spot_id'], $spot['name'] ?: 'start', $spot['conditions'], $spot['variables'], $spot['spot_id'], [$spot['spot_id']], $spot['flag']);
        $this->start_id   = (int) $spot['spot_id'];
        $this->current_id = $this->start_id;
    }

    /** current_id could be undefined when we deserialize older object */
    public function __unserialize(array $data): void
    {
        $this->assignUnserializedData($data);
        $this->current_id ??= $this->start_id;
    }

    /** Limits of a tree fromArray() accepts - it is the gate of the aa-app import, the data untrusted */
    public const MAX_SPOTS = 5000;       // spots of a tree
    public const MAX_DEPTH = 64;         // nested choices - walkTree() recurses once per level
    public const MAX_TEXT  = 65536;      // bytes of a spot name, variable, condition

    /** The tree as plain data - the canonical form of the aa-app package (design balicek-v1.md § 6.3):
     *  {start_id, spots: list by number of {id, n, p, f, v, c, po, ch}}. Ids (also p, po, ch, start_id)
     *  are strings, f an int, n a string; v (name => name) and c (variable => condition) keyed arrays
     *  of strings or null; po and ch lists or null. A falsy scalar (`false` of addInSequence(), the 0 of
     *  a site_spot.flag the constructor passes as choices) is null; empty positions and choices are left
     *  out and an empty list is null - walkTree() skips them and treats both alike; choices in key order
     *  (walkTree() ksort()s them). NULL entries of the tree are no spots. The current spot of the editor
     *  is no data.
     *  @throws UnexpectedValueException a spot property of a type no tree has */
    public function toArray(): array
    {
        $spots = [];
        foreach ($this->tree as $spot) {
            if (!($spot instanceof spot)) {
                continue;       // NULL of `& $this->tree[$id]` for an id no longer there (get(), set(), move())
            }
            $ch = self::canonicalArray($spot->ch, 'ch');
            if ($ch) {
                ksort($ch);
            }
            $spots[] = [
                'id' => (string) $spot->id,
                'n'  => (string) $spot->n,
                'p'  => (string) $spot->p,
                'f'  => (int) $spot->f,
                'v'  => self::canonicalArray($spot->v, 'v'),
                'c'  => self::canonicalArray($spot->c, 'c'),
                'po' => self::canonicalIds(self::canonicalArray($spot->po, 'po')),
                'ch' => self::canonicalIds($ch),
            ];
        }
        usort($spots, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        return ['start_id' => (string) $this->start_id, 'spots' => $spots];
    }

    /** The tree of toArray() data - also as a reader gives it (numbers for ids, keys left out = null).
     *  Ids (id, p, po, ch, start_id) become ints, as new_id() makes them: isOption() compares choices
     *  strictly with the int getCurrentId().
     *
     *  The gate of the import: only a tree walkTree() walks - the start is spot 1 (walkTree(), getSite()
     *  hard-wire it), its own parent, heading its sequence (po starts with the spot itself, as the
     *  constructor and addChoice() build it, so does every option); every other id in a po or ch is a
     *  spot whose parent is the listing spot, listed once; every spot reachable from spot 1 (no cycle);
     *  MAX_SPOTS, MAX_DEPTH, MAX_TEXT.
     *  @throws InvalidArgumentException anything else */
    public static function fromArray(array $data): sitetree
    {
        $spots = $data['spots'] ?? null;
        if (!is_array($spots) || !$spots || (count($spots) > self::MAX_SPOTS)) {
            throw new InvalidArgumentException('sitetree: 1 to ' . self::MAX_SPOTS . ' spots');
        }
        $id   = static fn(mixed $v): int => ((is_int($v) || is_string($v)) && preg_match('/^[1-9][0-9]{0,9}\z/', (string) $v))
            ? (int) $v
            : throw new InvalidArgumentException('sitetree: spot id ' . var_export($v, true));
        $ids  = static fn(mixed $list): ?array => is_array($list)
            ? (array_map($id, array_values($list)) ?: null)
            : self::canonicalArray($list, 'po', InvalidArgumentException::class);
        $tree = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $tree->tree = [];
        foreach ($spots as $s) {
            $spot_id = $id($s['id'] ?? null);
            if (isset($tree->tree[$spot_id])) {
                throw new InvalidArgumentException("sitetree: duplicate spot $spot_id");
            }
            $v = self::canonicalArray($s['v'] ?? null, 'v', InvalidArgumentException::class);
            $c = self::canonicalArray($s['c'] ?? null, 'c', InvalidArgumentException::class);
            $n = (string) (is_scalar($s['n'] ?? null) ? $s['n'] : '');
            foreach ([$n, ...array_keys($v ?? []), ...array_keys($c ?? []), ...($c ?? [])] as $text) {
                if (strlen((string) $text) > self::MAX_TEXT) {
                    throw new InvalidArgumentException("sitetree: a text of spot $spot_id is longer than " . self::MAX_TEXT . ' bytes');
                }
            }
            foreach ($v ?? [] as $key => $name) {
                if ((string) $key !== $name) {
                    throw new InvalidArgumentException("sitetree: variable key $key of spot $spot_id is not its name");
                }
            }
            $tree->tree[$spot_id] = new spot($spot_id, $n, $c, $v, $id($s['p'] ?? null), $ids($s['po'] ?? null), $ids($s['ch'] ?? null), (int) ($s['f'] ?? 0));
        }
        $tree->start_id = $id($data['start_id'] ?? null);
        self::checkStructure($tree->tree, $tree->start_id);
        $tree->current_id = $tree->start_id;
        return $tree;
    }

    /** fromArray(): the tree is one walkTree() walks @param spot[] $spots @throws InvalidArgumentException */
    private static function checkStructure(array $spots, int $start): void
    {
        $fail = static fn(string $message): never => throw new InvalidArgumentException("sitetree: $message");
        if (($start !== 1) || !isset($spots[1]) || ($spots[1]->p !== 1)) {
            $fail('the start is spot 1, its own parent');
        }
        $listed = [];
        foreach ($spots as $spot_id => $spot) {
            if (!isset($spots[$spot->p]) || (($spot->p === $spot_id) && ($spot_id !== 1))) {
                $fail("spot $spot_id: no parent");
            }
            if (($spot_id === 1) && (($spot->po[0] ?? null) !== 1)) {
                $fail('spot 1 heads its sequence');
            }
            foreach (['po' => $spot->po, 'ch' => $spot->ch] as $what => $list) {
                foreach ($list ?? [] as $k => $child) {
                    if (($what === 'po') && ($k === 0) && ($child === $spot_id)) {
                        continue;       // the spot heading its sequence
                    }
                    if (($child === $spot_id) || !isset($spots[$child]) || ($spots[$child]->p !== $spot_id) || isset($listed[$child])) {
                        $fail("spot $spot_id: $what $child is no spot of its own, or listed twice");
                    }
                    $listed[$child] = true;
                    if (($what === 'ch') && (($spots[$child]->po[0] ?? null) !== $child)) {
                        $fail("spot $child: an option heads its sequence");
                    }
                }
            }
        }
        $seen  = [1 => true];
        $queue = [[1, 0]];
        while ($queue) {
            [$spot_id, $depth] = array_pop($queue);
            if ($depth > self::MAX_DEPTH) {
                $fail('choices nested deeper than ' . self::MAX_DEPTH);
            }
            $children = [
                ...array_map(static fn(int $c): array => [$c, $depth], array_slice($spots[$spot_id]->po ?? [], 1)),   // the sequence
                ...array_map(static fn(int $c): array => [$c, $depth + 1], $spots[$spot_id]->ch ?? []),             // one level down
            ];
            foreach ($children as [$child, $child_depth]) {
                if (isset($seen[$child])) {
                    $fail("spot $child: reached twice");
                }
                $seen[$child] = true;
                $queue[]      = [$child, $child_depth];
            }
        }
        if (count($seen) !== count($spots)) {
            $fail('spots unreachable from spot 1: ' . implode(' ', array_keys(array_diff_key($spots, $seen))));
        }
    }

    /** v, c (keyed) or po, ch as an array of strings; a falsy scalar (null, false, '', 0, '0') is null
     *  @param class-string<\Throwable> $error */
    private static function canonicalArray(mixed $value, string $what, string $error = UnexpectedValueException::class): ?array
    {
        if (!is_array($value)) {
            return ((is_scalar($value) || ($value === null)) && !$value) ? null : throw new $error("sitetree: $what is no array");
        }
        foreach ($value as $key => $v) {
            $value[$key] = is_scalar($v) || ($v === null) ? (string) $v : throw new $error("sitetree: $what holds a nested value");
        }
        return $value;
    }

    /** po, ch: the ids that walkTree() does not skip, as a list of strings; none = null */
    private static function canonicalIds(?array $ids): ?array
    {
        return ($ids === null) ? null : (array_values(array_filter($ids)) ?: null);
    }

    public function setCurrentId(int $id): void
    {
        $this->current_id = $id;
    }

    public function getCurrentId(): int
    {
        return $this->current_id;
    }

    /** Creates the spot object and adds it in the sequence (positions) */
    public function addInSequence($where, $name, $conditions = false, $variables = false, $flag = false): bool
    {
        // parent is not set yet (set by addInSequence() in next step);
        $spot = new spot($this->new_id(), $name, $conditions, $variables, false, $flag);
        if ($this->_addInSequence($spot, $where)) {
            $this->tree[$spot->Id()] = $spot;
            return true;
        }
        return false;
    }

    /** Adds already created spot object into sequence (positions array) */
    public function _addInSequence($spot, $where): bool
    {
        //get real parent
        $parent_spot = & $this->tree[$where];

        // this is true for simple spot, which is normal member of any sequence
        // real parent must have positions set
        if (!$parent_spot->get('positions')) {
            // if we want to add spot to simple spot in sequence, then we have
            // to add it its parent (the first in the sequence
            $parent_spot = & $this->tree[$parent_spot->get('parent')];
        }

        // parent is set by addInSequence() in next step;
        $parent_spot->addInSequence($spot);  // Note this is going to the spot, not recursing

        return true;
    }

    public function new_id()
    {
        return max(array_keys($this->tree)) + 1;
    }

    /** Creates the spot object and adds it in the choices array
     * @param $where
     * @param $name
     * @param bool $conditions
     * @param bool $variables
     * @param bool $flag
     * @return bool
     */
    public function addChoice($where, $name, $conditions = false, $variables = false, $flag = false): bool
    {
        // parent is not set yet (set by addChoice() in next step);
        $new_id = $this->new_id();
        $spot = new spot($new_id, $name, $conditions, $variables, false, [$new_id], $flag);
        if ($this->_addChoice($spot, $where)) {
            $this->tree[$spot->Id()] = $spot;
            return true;
        }
        return false;
    }

    /** Adds already created spot object in the choices array */
    public function _addChoice($spot, $where): bool
    {
        //get real parent
        $where_spot = & $this->tree[$where];
        if (!$where_spot->get('variables')) {  // before creating choice must be defined the list of dependency variables
            return false;
        }
        // parent is set by addChoice() in next step;
        $where_spot->addChoice($spot);
        return true;
    }

    public function removeSpot($spot_id)
    {
        $spot = & $this->tree[$spot_id];

        if ($spot && $spot->isLeaf()) {
            $parent_id = $spot->get('parent');
            $parent = & $this->tree[$parent_id];
            if (!$parent) {
                return false;
            }
            if ($priorsib = $parent->removeSpot($spot_id)) {
                unset($this->tree[$spot_id]);
                return $priorsib;
            }
        }
        return false;
    }

    /** Moves the spot up or down within the sitetree. The move is done only
     *  within the same parent.
     * @param $spot_id int - id of spot to be moved
     * @param $direction string - 'moveDown' or 'moveLeft'
     * @return bool
     */
    public function move($spot_id, $direction): bool
    {
        $spot = & $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_id = $spot->get('parent');
        $parent = & $this->tree[$parent_id];
        if (!$parent) {
            return false;
        }
        return $parent->$direction($spot_id);
    }


    /** Moves the spot left (to the parent) or right (to first child) within
     *  the sitetree.
     * @param string $direction - 'moveLeft' or 'moveRight'
     * @return bool
     */
    public function moveLeftRight($spot_id, $direction): bool
    {
        $spot = & $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_id = $spot->get('parent');
        $parent = & $this->tree[$parent_id];
        if (!$parent) {
            return false;
        }

        $spot_type = $parent->isChoice($spot_id) ? 'choice' : 'sequence';

        $destination_parent_id = false;
        if ($direction === 'moveLeft') {
            // destination_parent - parent of our parent (where we are going to move the spot)
            $destination_parent_id = $parent->get('parent');
        } else {       // 'moveRight'
            // fing next spot in the current spot-set (positions/choices)
            $sibling_id = $spot_id;
            while (false !== ($sibling_id = $this->getNextSibling($sibling_id))) {
                if ($this->haveBranches($sibling_id)) {
                    if ($spot_type === 'choice') {
                        // if the moved spot is choice, then we just add it to choices
                        $destination_parent_id = $sibling_id;
                    } else {
                        // in case the spot is normal sequence spot, then we
                        // have to add it to first option
                        $choices = $this->get('choices', $sibling_id);
                        $destination_parent_id = ((is_array($choices) && isset($choices[0])) ? $choices[0] : false);
                    }
                    break;
                }
            }
        }

        if (false === $destination_parent_id) {  // destination_parent not found
            return false;
        }

        $destination_parent = & $this->tree[$destination_parent_id];
        if (!$destination_parent) {
            return false;
        }
        $parent->normalize();
        $destination_parent->normalize();

        if (!$parent->removeSpot($spot_id)) {
            return false;
        }

        if ($spot_type === 'choice') {
            $this->_addChoice($spot, $destination_parent_id);
        } else {
            $this->_addInSequence($spot, $destination_parent_id);
        }
        return true;
    }

    /** Returns id of next sibling - the next spot in the set
     *  (positions or choices) of the given $spot_id
     */
    public function getNextSibling($spot_id)
    {
        $spot = & $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_id = $spot->get('parent');
        $parent = & $this->tree[$parent_id];
        if (!$parent) {
            return false;
        }

        // get id of the destination spot for Right movement (tree admin)
        $spot_set = $this->isOption($spot_id) ? $parent->get("choices") : $parent->get("positions");
        if (!$spot_set) {
            echo 'something is wrong - no "positions" or "choices" for parent';
            return false;
        }
        // now we are looking for the spot which is on the same level under
        $found = false;
        foreach ($spot_set as $poskey => $pos) {
            if (!$pos) {     // There was a bug that introduced empty
                continue;    // positions - this is to skip them.
            }
            if (!$found && $pos == $spot_id) {
                $found = true;
                continue;
            }
            if ($found) {
                return $pos;
            }
        }
        if (!$found) {
            echo 'something is wrong - spot is not found in the spot-set of its parent';
        }
        return false;
    }

    public function addVariable($where, $var): bool
    {
        //get real parent
        $where_spot = & $this->tree[$where];
        if (!$where_spot) {
            return false;
        }
        $where_spot->addVariable($var);
        return true;
    }

    public function removeVariable($where, $var): bool
    {
        //get real parent
        $where_spot = & $this->tree[$where];
        if (!$where_spot) {
            return false;
        }
        $where_spot->removeVariable($var);
        return true;
    }

    public function addCondition($where, $var, $cond, $op): bool
    {
        //get real parent
        if (!$this->isChoice($where)) {
            return false;
        }
        $where_spot = & $this->tree[$where];
        $where_spot->addCondition($var, new AA_Site_Spot_Match($op, $cond));
        return true;
    }

    public function removeCondition($where, $var): bool
    {
        //get real parent
        $where_spot = & $this->tree[$where];
        if (!$where_spot) {
            return false;
        }
        $where_spot->removeCondition($var);
        return true;
    }

    public function setFlag($spot_id, $flag): void
    {
        $current_flag = $this->get('flag', $spot_id);

        // set "structural" flag - stored in structure (not in site_spot table)
        $current_flag |= $flag;
        $this->set('flag', $spot_id, $current_flag); // wite the state also to the structure
    }

    public function clearFlag($spot_id, $flag): void
    {
        $current_flag = $this->get('flag', $spot_id);

        // set "structural" flag - stored in structure (not in site_spot table)
        $current_flag &= ~$flag;
        $this->set('flag', $spot_id, $current_flag); // wite the state also to the structure
    }

    public function isFlag($spot_id, $flag): int
    {
        return $this->get('flag', $spot_id) & $flag;
    }

    public function isChoice($spot_id)
    {
        $spot = & $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_spot_id = $spot->get('parent');
        if (!$parent_spot_id || !($vars = $this->get('variables', $parent_spot_id))) {
            return false;
        }
        return $vars;
    }

    public function isOption($spot_id)
    {
        $spot = & $this->tree[$spot_id];
        if (!$spot) {
            return false;
        }
        $parent_spot_id = $spot->get('parent');
        if (!$parent_spot_id || !($choices = $this->get('choices', $parent_spot_id))) {
            return false;
        }
        if (is_array($choices) && in_array($spot_id, $choices, true)) {
            return $this->get('variables', $parent_spot_id);
        }
        return false;
    }

    // Find the spot from the tree, and then do a get on the spot.
    public function get($what, $id)
    {
        $s = & $this->tree[$id];
        return $s ? $s->get($what) : false;
    }

    public function set($what, $id, $value): void
    {
        $s = & $this->tree[$id];
        $s?->set($what, $value);
    }

    public function getName($id)
    {
        return $this->get('name', $id);
    }
    public function exist($id): bool
    {
        return isset($this->tree[$id]);
    }

    public function haveBranches($id): bool
    {
        return (bool) $this->get('choices', $id);
    }

    public function isSequenceStart($id): bool
    {
        return (bool) $this->get('positions', $id);
    }

    public function isLeaf($id): bool
    {
        $s = & $this->tree[$id];
        return $s->isLeaf();
    }

    public function conditionMatches($id, $state): bool
    {
        $s = & $this->tree[$id];
        return $s ? $s->conditionMatches($state) : false;
    }


    /** get all spot ids in the tree
     *  @return array
     */
    public function getAllSpotIds(): array
    {
        $foo_apc  = [];
        $spot_ids = [];
        $this->walkTree($foo_apc, 1, function ($spot_id) use (&$spot_ids) {
            $spot_ids[] = $spot_id;
        }, 'all');
        return $spot_ids;
    }

    /** Walk the tree, starting at $id, calling $functions for each spot
     *
     *
     */
    public function walkTree(&$state, $id, $functions, $method = 'cond', $depth = 0): void
    {
        // $functions could be array which defines the callback functions,
        // or nonarray - just main - spot -  function
        if (!is_array($functions)) {
            // functions are array now
            $functions = ['spot' => $functions];
        }
        $current = & $this->tree[$id];
        $positions = $current->get("positions");
        if (!$positions) {
            echo 'something is wrong - no "positions" for parent';
            exit;
        }
        foreach ($positions as $poskey => $pos) {
            if ($pos) {
                // There is a bug that introduced empty positions
                // this is to skip them.
                if (($method === 'all') || ($method === 'collapsed') || (($method === 'cond') && !$this->isFlag($pos, AA_Module_Site::FLAG_DISABLE))) {
                    call_user_func_array($functions['spot'], [$pos, $depth]);
                }

                // if this position is collapsed, then print only first position
                // and skip the others as well as all the choices
                if ($method === 'collapsed' && $this->isFlag($pos, AA_Module_Site::FLAG_COLLAPSE)) {  // AA_Module_Site::COLLAPSE - collapsed branches.
                    break;
                }

                if ($this->haveBranches($pos)) {
                    $chcurrent = & $this->tree[$pos];
                    $choices = $chcurrent->get("choices");
                    if (!$choices) {
                        echo "something is wrong - haveBranches but it has not choices[]";
                        exit;
                    }
                    ksort($choices); // might not be in key order
                    $choices_count = count($choices);
                    $choices_index = 0;
                    foreach ($choices as $k => $cho) {
                        if ($cho) { // skip buggy empty choices
                            if (($method === 'all') || ($method === 'collapsed') || ($this->conditionMatches($cho, $state) && !$this->isFlag($cho, AA_Module_Site::FLAG_DISABLE))) {

                                // sometimes it is useful to call a function before the choice
                                if ($functions['before_choice']) {
                                    call_user_func_array($functions['before_choice'], [$cho, $depth, $choices_index, $choices_count]);
                                }

                                $this->walkTree($state, $cho, $functions, $method, $depth + 1);

                                // and sometimes it is useful to call a function after the choice
                                if ($functions['after_choice']) {
                                    call_user_func_array($functions['after_choice'], [$cho, $depth, $choices_index, $choices_count]);
                                }
                                $choices_index++;

                                if ($method === 'cond') {
                                    break;                 // one matching spot is enough
                                }
                            }
                        } else {
                            //                            if ($GLOBALS["sitefix"]) {
                            //                                unset($chcurrent->ch[$k]); // fix position($pos)=",$chcurrent);
                            //                            } else {
                            huhe("Warning: skipping Empty choice in position=$pos, run with &amp;sitefix=1 to fix; tree=", $this);
                            //                            }
                        }
                    } // each choice
                } // haveBranches
            } else {  // Empty $pos
                //                if ($GLOBALS["sitefix"]) {
                //                    // unset($current->po[$poskey]); //  fix: poskey=$poskey val=",$current->po,"cur=", $current);
                //                } else {
                huhe("Warning: skipping Empty position in id=$id key $poskey of tree=", $this->tree[$id]);
                //                }
            }
        } // each position
    } // function


    public function printSpotName_Start($spot_id, $depth): void
    {
        // print the spot itself
        $this->printSpotHtml($spot_id, $this->getName($spot_id), $spot_id == $this->current_id, ($this->get('flag', $spot_id) & AA_Module_Site::FLAG_DISABLE));

        // variables defined? - print it
        if (!$this->isFlag($spot_id, AA_Module_Site::FLAG_COLLAPSE) && ($vars = $this->get('variables', $spot_id))) {
            echo "\n  <div class=\"variables\">(" . safe(implode(',', $vars)) . ')</div>';
        }
    }

    public function printChoice_Start($spot_id, $depth, $choices_index, $choices_count): void
    {
        // begin of the choice (open <div>)

        // last choice in the list is special
        $last  = ($choices_index == $choices_count - 1);    // just shortcut variable
        $class = $last ? 'lastchoice' : 'choice';

        if ($this->isLeaf($spot_id)) {
            $colaps = GetModuleImage('site', $last ? 'l.gif' : 't.gif', '', 21, 13);
        } elseif ($this->get('flag', $spot_id) & AA_Module_Site::FLAG_COLLAPSE) {
            $colaps = a_href(self::siteAdminPageUrl($spot_id, ['akce' => 'm']), GetModuleImage('site', 'plus.gif', '', 21, 13));
        } else {
            $colaps = a_href(self::siteAdminPageUrl($spot_id, ['akce' => 'p']), GetModuleImage('site', 'minus.gif', '', 21, 13));
        }
        echo "\n<div class=\"$class\">$colaps";

        // print conditions
        $conditions = '';
        if ($conds = $this->get('conditions', $spot_id)) {
            $delim = ' ';
            foreach ($conds as $k => $v) {
                $match = AA_Site_Spot_Match::factoryByString($v, $k);
                $conditions .= $delim . safe($k) . ': <strong>' . safe($match->val) . '</strong> <small>(' . safe($match->op) . ')</small>';
                $delim = ', ';
            }
        }
        if ($conditions == '') {
            $conditions = '&nbsp;';
        }
        echo "\n  <div class=\"conditions\">" . a_href(self::siteAdminPageUrl($spot_id, ['go_sid' => $spot_id]), $conditions) . "</div>";
    }

    public function printChoice_End($spot_id, $depth, $choices_index, $choices_count): void
    {
        // close previously opened <div class="choice">
        echo "\n</div>";
    }

    public function printVariables($spot_id, $vars): void
    {
        echo "<tr><th>" . _m("Spot&nbsp;variables") . "</th><td>";
        if (isset($vars) && is_array($vars)) {
            foreach ($vars as $k => $v) {
                echo safe($v) . "<span>" . a_href(self::siteAdminPageUrl($spot_id, ["delvar" => $k]), _m("Delete"), ['class' => "aa-button-delete"]) . "</span><br>";
            }
        }
        echo "<form name=fvar action=\"" . safe($_SERVER['PHP_SELF']) . "\"><input type='text' name='addvar' value='' size='50'><span><a href='javascript:document.fvar.submit()' class=\"aa-button-new\">" . _m("Add") . "</a></span>";
        echo $this->getCurrentSpotId();
        echo StateHidden();
        echo "</form></td></tr>";
    }

    public function printConditions($spot_id, $conds, $vars): void
    {
        echo "<tr><th>" . _m("Spot&nbsp;conditions") . "</th><td>";
        if (isset($vars) && is_array($vars)) {
            $i = 0;
            foreach ($vars as $k => $v) {
                $safe_v = safe($v);
                $safe_k = safe($k);

                if ($conds[$v]) {
                    $match = AA_Site_Spot_Match::factoryByString($conds[$v], $v);
                    $warning = (trim($match->val) == $match->val) ? '' : '<div style="color:red;"><small>' . _m('Warning: the condition starts or ends with whitespace character. Please check, if it is OK in your expression.') . '</small></div>';
                    echo "$safe_v (" . safe($match->op) . ") <strong>" . safe($match->val) . "</strong> <span>" . a_href(self::siteAdminPageUrl($spot_id, ['delcond' => $v]), _m("Delete"), ['class' => "aa-button-delete"]) . "</span>$warning<br>";
                } else {
                    echo "<form name=fcond$i action=\"" . safe($_SERVER['PHP_SELF']) . "\">
                         $safe_k
                         <select name=addcondop>
                           <option>=</option>
                           <option value=contains>" . _m('contains') . "</option>
                           <option value=REGEXP>" . _m('Regular Expression') . "</option>
                         </select>
                         <input type='text' name='addcond' value='' size='50'>
                     <input type='hidden' name='addcondvar' value='$safe_v'>
                     <span><a href='javascript:document.fcond$i.submit()' class=\"aa-button-new\">" . _m("Add") . "</a></span>";
                    echo StateHidden();
                    echo $this->getCurrentSpotId();
                    echo "</form>";
                }
                $i++;
            }
        }
        echo "</td></tr>";
    }

    public function printSpotHtml($spot_id, $spot_name, $selected, $disabled): void
    {
        // get class for this spot div
        $class = ($selected) ? ' class="selected"' : '';

        // get actions (eye for disable/enable, at this moment
        $action = $disabled
            ? a_href(self::siteAdminPageUrl($spot_id, ['akce' => 'e']), GetModuleImage('site', 'disabled.gif', '', 16, 16), ['class' => 'eye'])
            : a_href(self::siteAdminPageUrl($spot_id, ['akce' => 'h']), GetModuleImage('site', 'enabled.gif', '', 16, 16), ['class' => 'eye']);

        // print the spot <div>
        echo "<div$class title=\"spot ID: $spot_id\">{$action}" . a_href(self::siteAdminPageUrl($spot_id, ["go_sid" => $spot_id]), strlen((string) $spot_name) ? safe($spot_name) : '&nbsp;', ['class' => "spot"]) . "</div>";
    }

    public function getCurrentSpotId(): string
    {
        return '<input type="hidden" name="spot_id" value="' . $this->current_id . '">';
    }

    public static function siteAdminPageUrl($spot_id, array $add = []): string
    {
        return get_url($_SERVER['PHP_SELF'], ['spot_id' => $spot_id] + $add, true);
    }

    public static function siteActionLink($spot_id, $action, $text, $img, $link = null, $add = []): string
    {
        $link ??= self::siteAdminPageUrl($spot_id, ['akce' => $action]);
        $image  = $img ? GetModuleImage('site', $img, '', 16, 16) . ' ' : '';
        return a_href($link, "$image$text", $add + ['class' => 'aa-button']);
    }
}
