<?php

if ( ! class_exists( 'FIN_CLI' ) ) {
	return;
}

$fincli_import_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $fincli_import_autoloader ) ) {
	require_once $fincli_import_autoloader;
}

FIN_CLI::add_command( 'import', 'Import_Command' );
