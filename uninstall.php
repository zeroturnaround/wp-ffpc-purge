<?php

if ( !defined( 'WP_UNINSTALL_PLUGIN' ) )  {
    exit();
}

require_once('wp-ffpc-purge.php');

WP_FFPC_Purge::actionUninstall();
