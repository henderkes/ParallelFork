<?php

require __DIR__.'/Error.php';
require __DIR__.'/Runtime.php';
require __DIR__.'/Future.php';
require __DIR__.'/Channel.php';
require __DIR__.'/Events.php';
require __DIR__.'/functions.php';

if (extension_loaded('shmop') && extension_loaded('sysvsem')) {
    require __DIR__.'/Sync.php';
}
