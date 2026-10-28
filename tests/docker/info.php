<?php
// One line about the container, printed by matrix.ps1.
echo PHP_VERSION, ' WP ', get_bloginfo( 'version' ), ' ', $_SERVER['SERVER_SOFTWARE'] ?? 'cli', ' ',
    extension_loaded( 'imagick' ) ? 'imagick ' : '', function_exists( 'imageavif' ) ? 'gd-avif' : 'gd',
    is_multisite() ? ' multisite' : '', "\n";
