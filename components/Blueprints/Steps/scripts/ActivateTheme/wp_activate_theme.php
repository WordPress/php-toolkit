<?php

define( 'WP_ADMIN', true );
require_once getenv( 'DOCROOT' ) . '/wp-load.php';

// Set current user to admin
set_current_user( get_users( array( 'role' => 'Administrator' ) )[0] );
switch_theme( getenv( 'THEME_FOLDER_NAME' ) );
