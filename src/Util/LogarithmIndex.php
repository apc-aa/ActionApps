<?php

namespace AA\Util;

class LogarithmIndex
{
    protected int $maxIndex;

    protected float $a;  // constants to logarithmic function
    protected float $b;  // constants to logarithmic function
    protected float $step;    // ratio of neighbouring indexes
    protected float $middle;  // middle of a bucket, relative to its bottom

    public function __construct(float $start, float $end, int $maxIndex)
    {
        $this->maxIndex = $maxIndex;
        $this->b        = exp((log($end) - $maxIndex * log($start)) / ($maxIndex - 1));
        $this->a        = 1 / log($start * $this->b);
        $this->step     = exp(1 / $this->a);
        $this->middle   = (1 + $this->step) / 2;
    }

    /** get index for the value */
    public function getIndex(float $value): int
    {
        return $this->clamp(floor($this->exactIndex($value)));
    }

    /** get boundary value for the index */
    public function index2float($index): float
    {
        return exp($index / $this->a) / $this->b;
    }

    /**
     * The value an index stands for when values are summed or averaged. getIndex()
     * floors, so index2float() is the bottom of the bucket and runs about 8 % low.
     */
    public function index2middle(int|float $index): float
    {
        return $this->index2float($index) * $this->middle;
    }

    /** index2float() backwards, without rounding: the fractional index of the value */
    public function exactIndex(float $value): float
    {
        return $this->a * log($this->b * $value);
    }

    /** The index whose index2middle() is closest to the value on the log scale - for storing an average. */
    public function nearestIndex(float $value): int
    {
        return $value > 0 ? $this->clamp(round($this->exactIndex($value / $this->middle))) : 0;
    }

    /**
     * SQL expression of index2middle(). $index_sql is put in as it is, so it must be
     * trusted SQL (a column expression), never a value. The constants are written as
     * floating point: a plain decimal literal would make the database compute every
     * row in DECIMAL, three times slower.
     */
    public function sqlMiddle(string $index_sql): string
    {
        $per_index = sprintf('%.15E', 1 / $this->a);
        $scale     = sprintf('%.15E', $this->middle / $this->b);
        return "(EXP(($index_sql) * $per_index) * $scale)";
    }

    /** Ratio of neighbouring indexes: 1.1864 for (0.002, 80, 63), so one index is 18.6 % more than the one below */
    public function calculateStep(): float
    {
        return $this->step;
    }

    private function clamp(float $index): int
    {
        return (int) max(0, min($this->maxIndex, $index));
    }

    /** generate table with index boundaries
     * @return string
     */
    public function getTable(): string
    {
        $table = "<table><tr><th>index<th>value";
        for ($i = 0; $i <= $this->maxIndex; $i++) {
            $table .= "<tr><th>{$i}<td>" . $this->index2float($i);
        }
        $table .= "</table>";
        return $table;
    }
}
