<?php

if (! \function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return \sys_get_temp_dir().($path ? DIRECTORY_SEPARATOR.$path : '');
    }
}
