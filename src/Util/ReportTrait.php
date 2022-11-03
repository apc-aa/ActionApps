<?php

namespace AA\Util;

trait ReportTrait {
    protected $messages = [];

    /** Message function
     *  Records Error/Information message
     * @param string|array $row
     */
    protected function message($row) {
        if (is_array($row)) {
            $this->messages[] = $row;
        } elseif (is_object($row)) {
            $this->messages[] = ['txt' => is_callable([$row, "__toString"]) ? $row : print_r($row, true)];
        } else {
            $this->messages[] = ['txt' => $row];
        }
    }

    /** Report function
     *  Print Error/Information messages
     * @param string $type html | plain
     * @return string - messages in table
     */
    public function report(string $type='html'): string {
        $ret = '';
        if ($this->messages) {
            $caption =  is_callable([$this, "objName"]) ? $this->objName() : '';
            if ($type=='html') {
                $ret = GetHtmlTable($this->messages, 'key', $caption);
            } elseif ($type=='plain') {
                $msgs = $caption ? array_merge([['name'=>$caption]], $this->messages) : $this->messages;
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