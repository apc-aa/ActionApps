<?php
// Open this file two or more times in a browser and look at output.txt.

require "../../include/file_lock.php3";

$lock = new FileLock ("file_lock");    
for ($i=1; $i < 1000; $i++) {
    $lock->lock();
    $fd = fopen ("output.txt", "a");
    fputs ($fd, $i."\n");
    fclose ($fd);
    $lock->unlock();
    // to produce some pause
    for ($j=1; $j < 100000; $j++);
}
?>
