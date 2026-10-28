<?php
// Writes the plugin's own nginx snippet where tests/docker/nginx.conf includes it.
file_put_contents( '/etc/nginx/erdo/webp.conf', ERDO_WebP_Server::get_nginx_config() . "\n" ); // phpcs:ignore
