<?php

namespace AA\Util;

trait ReportTrait {
    protected $messages = [];

    /** Message function
     *  Records Error/Information message
     * @param string|array $row
     * @param int          $verbosity
     */
    protected function message($row, int $verbosity = 1) {
        if (is_array($row)) {
            $m = $row;
        } elseif (is_object($row)) {
            $m = ['txt' => is_callable([$row, "__toString"]) ? $row : '<pre>'.safe(print_r($row, true)).'</pre>'];
        } else {
            $m = ['txt' => $row];
        }
        $this->messages[] = [$verbosity, $m];
    }

    /** Report function
     *  Print Error/Information messages
     * @param int    $verbosity
     * @param string $type html | plain
     * @return string - messages in table
     */
    public function report(int $verbosity = 1, string $type='html'): string {
        $ret = '';
        $rows = [];
        foreach ($this->messages as $m) {
            if ($m[0] <= $verbosity) {
                $rows[] = $m[1];
            }
        }

        if ($rows) {
            $caption =  is_callable([$this, "objName"]) ? $this->objName() : '';
            if ($type=='html') {
                $ret = GetHtmlTable($rows, 'key', $caption);
            } elseif ($type=='plain') {
                $msgs = $caption ? array_merge([['name'=>$caption]], $rows) : $rows;
                $ret = json_encode($msgs);
            }
        }
        return $ret;
    }

    /** Clear report function
     * unsets all current messages
     */
    public function clear_report() {
        unset($this->messages);
        $this->messages = [];
    }
}