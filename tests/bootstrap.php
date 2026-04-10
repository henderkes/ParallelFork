<?php

require dirname(__DIR__).'/vendor/autoload.php';

if (! extension_loaded('parallel')) {
    throw new \RuntimeException('ext-parallel is required to run tests. Use php-zts.');
}

// Flag so tests can distinguish between ext-parallel and fork paths.
define('USING_EXT_PARALLEL', true);
