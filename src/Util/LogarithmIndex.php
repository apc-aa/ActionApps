<?php

namespace AA\Util;

class LogarithmIndex
{
    protected float $start;
    protected float $end;
    protected int $maxIndex;

    protected float $a;  // constants to logarithmic function
    protected float $b;  // constants to logarithmic function

    function __construct(float $start, float $end, int $maxIndex)
    {
        $this->start    = $start;
        $this->end      = $end;
        $this->maxIndex = $maxIndex;
        $this->b        = exp((log($end) - $maxIndex * log($start)) / ($maxIndex - 1));
        $this->a        = 1 / log($start * $this->b);
    }

    /** get index for the value */
    function getIndex(float $value): int
    {
        return max(0, min($this->maxIndex, floor($this->a * log($this->b * $value))));
    }

    /** get boundary value for the index */
    function index2float($index): float
    {
        return exp($index / $this->a) / $this->b;
    }

    /** calculate step for the index
     * for $ln = new AA\Util\LogarithmIndex(0.002, 80, 63) it is 1.183 - so index 1.183 times bigger than previous (18,3 % bigger)
     */
    function calculateStep()
    {
        return pow($this->end / $this->start, 1 / $this->maxIndex);
    }

    /** generate table with index boundaries
     * @return string
     */
    function getTable(): string
    {
        $table = "<table><tr><th>index<th>value";
        for ($i = 0; $i <= $this->maxIndex; $i++) {
            $table .= "<tr><th>{$i}<td>" . $this->index2float($i);
        }
        $table .= "</table>";
        return $table;
    }
}