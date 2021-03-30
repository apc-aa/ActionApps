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
     * @return string - messages in table
     */
    public function report(): string {
        return GetHtmlTable($this->messages, 'key');
    }

    /** Clear report function
     * unsets all current messages
     */
    public function clear_report() {
        unset($this->messages);
        $this->messages = [];
    }
}