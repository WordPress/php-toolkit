<?php

add_action(
	'admin_menu',
	function () {
		add_menu_page( 'Static Files', 'Static Files', 'edit_posts', 'static_files_editor', 'msf_render_editor', 'dashicons-portfolio', 4 );
		add_submenu_page( 'static_files_editor', 'Data Source', 'Data Source', 'edit_posts', 'static_files_editor-data-source', 'msf_render_data_source' );
		add_submenu_page( 'static_files_editor', 'Posts listing', 'Posts listing', 'edit_posts', 'edit.php?post_type=local_file', null );
	}
);

add_action(
	'admin_init',
	function () {
		register_setting( 'msf_settings_group', 'msf_git_repository' );
		register_setting( 'msf_settings_group', 'msf_selected_branch' );
		register_setting( 'msf_settings_group', 'msf_data_source_type' );
		register_setting( 'msf_settings_group', 'msf_local_directory' );
	}
);

function msf_render_data_source() {
	if ( isset( $_POST['force_pull'] ) ) {
		$success = WP_Static_Files_Editor_Plugin::get_data_source()->sync();
		wp_redirect( admin_url( 'admin.php?page=static-files-data-source&success=' . ( $success ? 'true' : 'false' ) ) );
		exit;
	}
	$config           = get_option( 'static_files_editor_settings' ) ?: array();
	$git_repo         = $config['gitRepo'] ?? '';
	$data_source_type = $config['dataSourceType'] ?? 'local_directory';
	$local_directory  = $config['localDirectory'] ?? WP_CONTENT_DIR . '/uploads/notes';
	if ( $git_repo ) {
		$branches = WP_Static_Files_Editor_Plugin::get_git_branches( $git_repo )['refs'];
	} else {
		$branches = array();
	}
	$notices = array();
	if ( isset( $_GET['error_code'] ) && $_GET['error_code'] === 'no_data_source' ) {
		$notices[] = array(
			'type' => 'notice-error notice is-dismissible',
			'message' => 'You need to configure a data source before using the local files editor. Please enter a Git repository URL and select a branch below.',
		);
	}

	wp_interactivity_state(
		'staticFiles',
		array_merge(
			array(
				'branches' => $branches,
				'subdirectory' => '/',
				'dataSourceType' => $data_source_type,
				'localDirectory' => $local_directory,
			),
			$config,
			array(
				'notices' => $notices,
				'isLocalDirectory' => $data_source_type === 'local_directory',
				'isGitRepo' => $data_source_type === 'git_repo',
			)
		)
	);
	ob_start();
	?>
	<div class="wrap" data-wp-interactive="staticFiles">
		<h1>Data Source</h1>
		<div id="msf-data-source-app">
			<div id="msf-notice-container">
				<template data-wp-each--notice="state.notices">
					<div data-wp-bind--class="context.notice.type">
						<p data-wp-text="context.notice.message"></p>
					</div>
				</template>
			</div>
			<form data-wp-on--keydown="actions.onFormEnter">
				<table class="form-table">
					<tr>
						<th scope="row">Data Source</th>
						<td>
							<select
								data-wp-bind--value="state.dataSourceType"
								data-wp-on--change="actions.updateDataSourceType"
							>
								<option value="local_directory">Local Directory</option>
								<option value="git_repo">Git Repo</option>
							</select>
						</td>
					</tr>
				</table>
				<table class="form-table" data-wp-class--hidden="!state.isLocalDirectory">
					<tr>
						<th scope="row">Local Directory</th>
						<td>
							<input
								type="text"
								class="regular-text"
								data-wp-bind--value="state.localDirectory"
								data-wp-on--input="actions.updateLocalDirectory"
							/>
						</td>
					</tr>
				</table>

				<table class="form-table" data-wp-class--hidden="!state.isGitRepo">
					<tr>
						<th scope="row">Git Repository</th>
						<td>
							<input
								type="text"
								class="regular-text"
								data-wp-bind--value="state.gitRepo"
								data-wp-on--input="actions.updateGitRepo"
								data-wp-on--keydown="actions.onGitRepoInputEnter"
							/>
							<button
								type="button"
								class="button"
								data-wp-on--click="actions.fetchBranches"
							>
								Fetch Branches
							</button>
						</td>
					</tr>

					<tr>
						<th scope="row">Branch</th>
						<td>
							<select
								data-wp-watch="callbacks.bindSelectedBranch"
								data-wp-on--change="actions.updateSelectedBranch"
							>
								<option value="">Select branch</option>
								<template data-wp-each--branch="state.branches">
									<option
										data-wp-text="context.branch.niceName"
										data-wp-bind--value="context.branch.fullName"
									></option>
								</template>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">Path to synchronize</th>
						<td>
							<input type="text" class="regular-text" data-wp-bind--value="state.subdirectory" data-wp-on--input="actions.updateSubdirectory" />
						</td>
					</tr>
				</table>

				<p class="submit">
					<button
						type="button"
						class="button button-primary"
						data-wp-on--click="actions.saveSettings"
					>
						Save Changes
					</button>
					<button
						type="button"
						class="button"
						data-wp-on--click="actions.forcePull"
					>
						Force Pull
					</button>
				</p>
			</form>
		</div>
	</div>
	<?php
	$html = ob_get_clean();
	echo wp_interactivity_process_directives( $html );
}

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		wp_enqueue_script( 'wp-interactivity' );
		wp_enqueue_script_module(
			'@static-files-editor/data-source-page',
			plugin_dir_url( __FILE__ ) . 'data-source-page.js',
			array( '@wordpress/interactivity', '@wordpress/interactivity-router', 'wp-api-fetch', 'wp-data', 'wp-components' )
		);
		wp_enqueue_script( 'wp-api-fetch' );
		wp_enqueue_script( 'wp-data' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-notices' );
		wp_enqueue_script_module(
			'@static-files-editor/data-source-page',
			plugin_dir_url( __FILE__ ) . 'data-source-page.js',
			array( '@wordpress/interactivity', '@wordpress/interactivity-router', 'wp-api-fetch', 'wp-data', 'wp-components', 'wp-notices' )
		);
	}
);

function msf_render_editor() {
	echo '<h1>Editor Page</h1>';
}
