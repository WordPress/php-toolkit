<?php
/**
 * Plugin Name: Remote Plugin Installer
 * Description: Installs a plugin from a given URL and allows updates from a user-defined URL.
 * Requires Plugins: data-liberation
 */

require_once __DIR__ . '/update_plugin.php';

require_once __DIR__ . '/update_plugin.php';

/**
 * UNSAFE! DO NOT USE IN PRODUCTION!
 *
 * Disables request filtering for easy testing and development.
 */
add_filter( 'block_local_requests', '__return_false' );

add_filter(
	'http_request_args',
	function ( $args, $url ) {
		// Disable certificate verification
		$args['sslverify'] = false;
		// Allow local IP addresses or unsafe URLs
		$args['reject_unsafe_urls'] = false;
		return $args;
	},
	9999,
	2
);

add_action(
	'admin_menu',
	function () {
		add_menu_page( 'Remote Installer', 'Remote Installer', 'manage_options', 'remote-installer', 'rpi_render_admin_page' );
	}
);

function rpi_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// If we're updating, show a form to change the URL or handle the update
	if ( isset( $_GET['rpi_update_plugin'] ) ) {
		$plugin_file = sanitize_text_field( $_GET['rpi_update_plugin'] );
		$stored_url  = rpi_get_stored_plugin_url( $plugin_file );

		// Direct update without changing URL
		if ( isset( $_GET['direct_update'] ) && $_GET['direct_update'] === 'true' ) {
			$installed_plugin_file = rpi_install_plugin_from_url( $stored_url, true, $plugin_file );
			if ( is_wp_error( $installed_plugin_file ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $installed_plugin_file->get_error_message() ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>Plugin updated successfully.</p></div>';
			}
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=remote-installer' ) ) . '" class="button">Back to Plugin List</a>';
			return;
		}

		// If the user just submitted the new URL, do the update
		if ( isset( $_POST['rpi_new_url'] ) ) {
			$new_url               = sanitize_text_field( $_POST['rpi_new_url'] );
			$installed_plugin_file = rpi_install_plugin_from_url( $new_url, true, $plugin_file );
			if ( is_wp_error( $installed_plugin_file ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $installed_plugin_file->get_error_message() ) . '</p></div>';
			} else {
				rpi_store_plugin_url( $installed_plugin_file, $new_url );
				echo '<div class="notice notice-success"><p>Plugin updated successfully.</p></div>';
			}
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=remote-installer' ) ) . '" class="button">Back to Plugin List</a>';
			return;
		}

		// Check if the plugin exists
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) || ! $stored_url ) {
			echo '<div class="notice notice-error"><p>Plugin not found.</p></div>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=remote-installer' ) ) . '" class="button">Back to Plugin List</a>';
			return;
		}
		// Show the form to change the URL
		echo '<div class="wrap">';
		echo '<h1>Update Plugin</h1>';
		echo '<p>You can change the URL here before updating.</p>';
		echo '<form method="POST">';
		echo '<input type="text" name="rpi_new_url" value="' . esc_attr( $stored_url ) . '" style="width: 50%;" />';
		echo '<br><br><input type="submit" value="Update Plugin" class="button button-primary" />';
		echo '</form>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=remote-installer' ) ) . '" class="button">Back to Plugin List</a>';
		echo '</div>';
		return;
	}

	// If we're installing a new plugin
	if ( isset( $_POST['rpi_plugin_url'] ) ) {
		$plugin_url            = sanitize_text_field( $_POST['rpi_plugin_url'] );
		$installed_plugin_file = rpi_install_plugin_from_url( $plugin_url );
		if ( is_wp_error( $installed_plugin_file ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $installed_plugin_file->get_error_message() ) . '</p></div>';
		} else {
			rpi_store_plugin_url( $installed_plugin_file, $plugin_url );
			echo '<div class="notice notice-success"><p>Plugin installed and activated successfully.</p></div>';
		}
	}

	// Default interface
	echo '<div class="wrap">';
	echo '<h1>Remote Plugin Installer</h1>';
	echo '<form method="POST" action="admin.php?page=remote-installer">';
	echo '<p>Install a new plugin from a given URL.</p>';
	echo '<input type="text" name="rpi_plugin_url" placeholder="Plugin ZIP URL" style="width: 50%;" />';
	echo '<br><br><input type="submit" value="Install Plugin" class="button button-primary" />';
	echo '</form>';
	echo '</div>';

	// List installed plugins
	$installed_plugins = get_option( 'rpi_installed_plugins', array() );
	if ( ! empty( $installed_plugins ) ) {
		echo '<h2>Installed Plugins</h2>';
		echo '<table class="widefat fixed" cellspacing="0">';
		echo '<thead><tr><th>Plugin</th><th>Last Update</th><th>URL</th><th>Actions</th></tr></thead>';
		echo '<tbody>';
		foreach ( $installed_plugins as $plugin_file => $plugin_url ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
			$last_update = get_option( 'rpi_last_update_' . $plugin_file, 'Never' );
			$update_url  = admin_url( 'admin.php?page=remote-installer&rpi_update_plugin=' . urlencode( $plugin_file ) . '&direct_update=true' );
			$change_url  = admin_url( 'admin.php?page=remote-installer&rpi_update_plugin=' . urlencode( $plugin_file ) );
			echo '<tr>';
			echo '<td>' . esc_html( $plugin_data['Name'] ) . '</td>';
			echo '<td>' . esc_html( $last_update ) . '</td>';
			echo '<td>' . esc_url( $plugin_url ) . '</td>';
			echo '<td><a href="' . esc_url( $update_url ) . '" class="button">Update</a> <a href="' . esc_url( $change_url ) . '" class="button">Change URL</a></td>';
			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';
	}
}

function rpi_install_plugin_from_url( string $package_url, bool $is_update = false, string $original_plugin_file = '' ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$cache_busting_url = add_query_arg( '_cache_buster', time(), $package_url );
	$tmp_file          = download_url( $cache_busting_url );
	if ( is_wp_error( $tmp_file ) ) {
		return new WP_Error( 'download_failed', $tmp_file->get_error_message() );
	}

	$parsed_url     = wp_parse_url( $package_url );
	$package_path   = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
	$package_extension = 'zip';
	$base_name      = $original_plugin_file ? basename( dirname( $original_plugin_file ) ) : basename( $package_path, '.' . $package_extension );

	/**
	 * $tmp_file has a random component in the filename. WordPress would
	 * install it in wp-content/plugins/$new_name, not wp-content/plugins/$base_name.
	 *
	 * Let's give our package a stable filename to make WordPress actually
	 * upgrade the existing plugin instead of installing a new one.
	 */
	$stable_tmp_path = sys_get_temp_dir() . '/' . $base_name . '.zip';
	if ( file_exists( $stable_tmp_path ) && $stable_tmp_path !== $tmp_file ) {
		unlink( $stable_tmp_path );
	}
	rename( $tmp_file, $stable_tmp_path );

	if ( $is_update ) {
		/**
		 * Use our custom upgrade function instead of the WordPress upgrader.
		 * It handles additional cases:
		 *
		 * * Rename of the zipped directory name (+activation of the plugin)
		 * * Restore from backup if the upgrade fails
		 */
		$plugin_file = rpi_upgrade_plugin( $original_plugin_file, $stable_tmp_path );
		if ( is_wp_error( $plugin_file ) ) {
			return $plugin_file;
		}
		if ( ! $plugin_file ) {
			return new WP_Error( 'upgrade_failed', 'Upgrade failed. Please check the URL and try again.' );
		}

		if ( $plugin_file !== $original_plugin_file ) {
			// remove $original_plugin_file from rpi_installed_plugins
			$plugins = get_option( 'rpi_installed_plugins', array() );
			unset( $plugins[ $original_plugin_file ] );
			update_option( 'rpi_installed_plugins', $plugins );
		}
	} else {
		$upgrader = new Plugin_Upgrader();
		$upgrader->init();
		$run_result = $upgrader->run(
			array(
				'package'           => $stable_tmp_path,
				'destination'       => WP_PLUGIN_DIR,
				'clear_destination' => false,
				'clear_working'     => true,
				'hook_extra'        => array(
					'type'   => 'plugin',
					'action' => 'install',
				),
			)
		);
		if ( is_wp_error( $run_result ) || ! $run_result ) {
			return new WP_Error( 'upgrade_failed', 'Installation failed. Please check the URL and try again.' );
		}
		$plugin_file = $upgrader->plugin_info();
		activate_plugin( $plugin_file );
	}

	// Update the last update timestamp
	update_option( 'rpi_last_update_' . $plugin_file, current_time( 'mysql' ) );

	return $plugin_file;
}

function rpi_store_plugin_url( $plugin_file, $url ) {
	$plugins                 = get_option( 'rpi_installed_plugins', array() );
	$plugins[ $plugin_file ] = $url;
	update_option( 'rpi_installed_plugins', $plugins );
}

function rpi_get_stored_plugin_url( $plugin_file ) {
	$plugins = get_option( 'rpi_installed_plugins', array() );
	return isset( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ] : false;
}

// Add "Update" and "Change URL" links to the plugin list
add_filter(
	'plugin_action_links',
	function ( $actions, $plugin_file, $plugin_data, $context ) {
		if ( is_network_admin() ) {
			return $actions;
		}

		$stored_url = rpi_get_stored_plugin_url( $plugin_file );
		if ( $stored_url ) {
			$update_url = admin_url( 'admin.php?page=remote-installer&rpi_update_plugin=' . urlencode( $plugin_file ) . '&direct_update=true' );
			$change_url = admin_url( 'admin.php?page=remote-installer&rpi_update_plugin=' . urlencode( $plugin_file ) );

			$actions['rpi_update']     = '<a href="' . esc_url( $update_url ) . '">Update</a>';
			$actions['rpi_change_url'] = '<a href="' . esc_url( $change_url ) . '">Change URL</a>';
		}
		return $actions;
	},
	10,
	4
);
