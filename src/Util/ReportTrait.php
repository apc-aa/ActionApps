<?php

namespace AA\Util;

trait ReportTrait
{
    protected array $messages = [];

    /** Message function
     *  Records Error/Information message
     * @param string|array|object $row
     * @param int          $verbosity
     */
    protected function message($row, int $verbosity = 1): void
    {
        if (is_array($row)) {
            $m = $row;
        } elseif (is_object($row)) {
            $m = ['txt' => is_callable([$row, "__toString"]) ? $row : '<pre>' . safe(print_r($row, true)) . '</pre>'];
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
    public function report(int $verbosity = 1, string $type = 'html'): string
    {
        $ret = '';
        $rows = $this->reportRows($verbosity);

        if ($rows) {
            $caption =  is_callable([$this, "getId"]) ? $this->getId() : '';
            if ($type === 'html') {
                $ret = GetHtmlTable($rows, 'key', $caption);
            } elseif ($type === 'plain') {
                $msgs = $caption ? array_merge([['name' => $caption]], $rows) : $rows;
                $ret = json_encode($msgs);
            }
        }
        return $ret;
    }

    /** The recorded rows themselves - a Finding's evidence
     * @return list<array<string,mixed>>
     */
    public function reportRows(int $verbosity = 1): array
    {
        $rows = [];
        foreach ($this->messages as $m) {
            if ($m[0] <= $verbosity) {
                $rows[] = $m[1];
            }
        }
        return $rows;
    }

    /** Clear report function
     * drops all current messages
     */
    public function clear_report(): void
    {
        $this->messages = [];
    }
}
