<?php

// Manual concurrency smoke test: open this file two or more times in a
// browser and look at output.txt — writes must not interleave.

require_once __DIR__ . "/../../vendor/autoload.php";

$lock = new \AA\Util\FileLock("file_lock");
for ($i = 1; $i < 1000; $i++) {
    $lock->Lock();
    $fd = fopen("output.txt", 'ab');
    fwrite($fd, $i . "\n");
    fclose($fd);
    $lock->Unlock();
    // small busy pause to encourage interleaving on contended runs
    for ($j = 1; $j < 100000; $j++) {
        ;
    }
}
