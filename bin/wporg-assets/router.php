<?php
$p = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( $p !== '/' && file_exists( getenv('WP_DIR') . $p ) && ! is_dir( getenv('WP_DIR') . $p ) ) { return false; }
if ( is_dir( getenv('WP_DIR') . $p ) && file_exists( getenv('WP_DIR') . rtrim( $p, '/' ) . '/index.php' ) ) { chdir( getenv('WP_DIR') . $p ); require getenv('WP_DIR') . rtrim( $p, '/' ) . '/index.php'; return; }
if ( preg_match( '#\.php$#', $p ) && file_exists( getenv('WP_DIR') . $p ) ) { return false; }
require __DIR__ . '/../wp/index.php';
