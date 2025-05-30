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
	$data_source_type = $config['dataSourceType'] ?? 'github_repository';
	$local_directory  = $config['localDirectory'] ?? WP_CONTENT_DIR . '/uploads/notes';
	$github_token     = get_option( 'msf_github_token', '' );
	$selected_repo    = $config['selectedRepo'] ?? '';
	
	if ( $git_repo ) {
		$branches = WP_Static_Files_Editor_Plugin::get_git_branches(
			WP_Static_Files_Editor_Plugin::get_git_remote_url( $git_repo, [
				'provider' => $data_source_type === 'github_repository' ? 'github' : 'git',
			] ),
		)[ 'refs' ];
	} else {
		$branches = array();
	}
	
	// Get GitHub repositories if we have a token
	$github_repos = array();
	if ( ! empty( $github_token ) ) {
		$github_repos = WP_Static_Files_Editor_Plugin::get_github_repos_endpoint();
		if ( is_wp_error( $github_repos ) ) {
			$github_repos = array();
		}
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
				'githubRepos' => $github_repos,
				'selectedRepo' => $selected_repo,
				'selectedBranch' => '',
			),
			$config,
			array(
				'githubToken' => ! empty( $github_token ),
				'isGitHubOAuthConfigured' => defined( 'WP_STATIC_FILES_EDITOR_GITHUB_CLIENT_ID' ) && defined( 'WP_STATIC_FILES_EDITOR_GITHUB_REDIRECT_URI' ),
				'notices' => $notices,
				'isLocalDirectory' => $data_source_type === 'local_directory',
				'isGitRepo' => $data_source_type === 'git_repo',
				'isGithubRepo' => $data_source_type === 'github_repository',
				'githubClientId' => defined( 'WP_STATIC_FILES_EDITOR_GITHUB_CLIENT_ID' ) ? WP_STATIC_FILES_EDITOR_GITHUB_CLIENT_ID : '',
				'githubRedirectUri' => defined( 'WP_STATIC_FILES_EDITOR_GITHUB_REDIRECT_URI' ) ? WP_STATIC_FILES_EDITOR_GITHUB_REDIRECT_URI : '',
			)
		)
	);

	ob_start();
	?>
	<div class="wrap" data-wp-interactive="staticFiles" data-wp-init="callbacks.onInit">
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
								<option value="github_repository">GitHub Repository</option>
								<option value="git_repo">Git Repo</option>
								<option value="local_directory">Local Directory</option>
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
								data-wp-bind--disabled="state.fetchingBranches"
							>
								<span data-wp-class--hidden="state.fetchingBranches">Fetch Branches</span>
								<span data-wp-class--hidden="!state.fetchingBranches" class="spinner is-active" style="float: none; margin: 0;"></span>
								<span data-wp-class--hidden="!state.fetchingBranches">Fetching...</span>
							</button>
						</td>
					</tr>

					<tr>
						<th scope="row">Branch</th>
						<td>
							<select
								data-wp-watch="callbacks.bindSelectedBranch"
								data-wp-on--change="actions.updateSelectedBranch"
								data-wp-bind--disabled="state.fetchingBranches"
							>
								<option value="">Select branch</option>
								<template data-wp-each--branch="state.branches">
									<option
										data-wp-text="context.branch.niceName"
										data-wp-bind--value="context.branch.fullName"
									></option>
								</template>
							</select>
							<span data-wp-class--hidden="!state.fetchingBranches" class="spinner is-active" style="float: none; margin: 0 0 0 5px;"></span>
						</td>
					</tr>

					<tr>
						<th scope="row">Path to synchronize</th>
						<td>
							<input type="text" class="regular-text" data-wp-bind--value="state.subdirectory" data-wp-on--input="actions.updateSubdirectory" />
						</td>
					</tr>
				</table>

				<table class="form-table" data-wp-class--hidden="!state.isGithubRepo">
					<tr>
						<th scope="row">GitHub Authorization</th>
						<td>
							<div data-wp-class--hidden="state.isGitHubOAuthConfigured">
								<p>GitHub OAuth is not configured. You must define <code>WP_STATIC_FILES_EDITOR_GITHUB_CLIENT_ID</code> and <code>WP_STATIC_FILES_EDITOR_GITHUB_REDIRECT_URI</code> in your <code>wp-config.php</code> file.</p>
							</div>
							<div data-wp-class--hidden="!state.isGitHubOAuthConfigured">
								<div data-wp-class--hidden="state.githubToken" class="github-auth-container">
									<button
										type="button"
										class="github-auth-button"
										data-wp-on--click="actions.authorizeWithGitHub"
									>
										<svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor">
											<path fill-rule="evenodd" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"></path>
										</svg>
										Authorize with GitHub
									</button>
									<p>Connect your GitHub account to access your repositories.</p>
								</div>
								<div data-wp-class--hidden="!state.githubToken" class="github-auth-success">
									<div>
										<p>
											<span class="dashicons dashicons-yes-alt github-auth-success-icon"></span>
											<strong>Connected to GitHub</strong>
										</p>
										<div class="github-auth-actions">
											<button
												type="button"
												class="button"
												data-wp-on--click="actions.fetchGitHubRepos"
											>
												Refresh Repositories
											</button>
											<button
												type="button"
												class="github-reauth-button"
												data-wp-on--click="actions.reauthorizeWithGitHub"
											>
												Connect different account
											</button>
										</div>
									</div>
								</div>
							</div>
						</td>
					</tr>

					<tr data-wp-class--hidden="!state.githubToken">
						<th scope="row">GitHub Repository</th>
						<td>
							<div class="repo-select-container">
								<select
									data-wp-bind--value="state.gitRepo"
									data-wp-on--change="actions.updateSelectedRepo"
									data-wp-bind--disabled="state.fetchingRepos"
								>
									<option value="">Select repository</option>
									<template data-wp-each--repo="state.githubRepos">
										<option
											data-wp-text="context.repo.full_name"
											data-wp-bind--value="context.repo.http_clone_url"
										></option>
									</template>
								</select>
								<span class="spinner" data-wp-class--is-active="state.fetchingRepos"></span>
							</div>
						</td>
					</tr>

					<tr data-wp-class--hidden="!state.githubToken">
						<th scope="row">Branch</th>
						<td>
							<div class="branch-select-container">
								<select
									data-wp-watch="callbacks.bindSelectedBranch"
									data-wp-on--change="actions.updateSelectedBranch"
									data-wp-bind--disabled="state.fetchingBranches"
								>
									<option value="">Select branch</option>
									<template data-wp-each--branch="state.branches">
										<option
											data-wp-text="context.branch.niceName"
											data-wp-bind--value="context.branch.fullName"
										></option>
									</template>
								</select>
								<span class="spinner" data-wp-class--is-active="state.fetchingBranches"></span>
							</div>
						</td>
					</tr>

					<tr data-wp-class--hidden="!state.githubConnected">
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
		wp_enqueue_style( 'dashicons' );
		
		// Add GitHub styles directly
		wp_add_inline_style( 'wp-admin', '
			.hidden {
				display: none !important;
			}

			.spinner {
				float: none;
			}

			.repo-select-container,
			.branch-select-container {
				display: flex;
				align-items: flex-start;
			}

			.github-auth-button {
				display: flex;
				align-items: center;
				background-color: #24292e;
				color: white;
				border: none;
				padding: 8px 16px;
				border-radius: 6px;
				font-weight: 600;
				transition: background-color 0.2s;
				box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
			}
			
			.github-auth-button:hover {
				background-color: #2c3136;
			}
			
			.github-auth-button:focus {
				box-shadow: 0 0 0 2px rgba(36, 41, 46, 0.3);
			}
			
			.github-auth-button svg {
				margin-right: 8px;
			}
			
			.github-auth-container {
				margin-bottom: 16px;
			}
			
			.github-auth-success {
				display: flex;
				align-items: center;
				background-color: #f6f8fa;
				border: 1px solid #e1e4e8;
				border-radius: 6px;
				padding: 12px 16px;
				margin-bottom: 16px;
			}
			
			.github-auth-success-icon {
				color: #2ea44f;
				margin-right: 8px;
			}
			
			.github-auth-actions {
				display: flex;
				align-items: center;
				margin-top: 8px;
			}
			
			.github-auth-actions button {
				margin-right: 8px;
			}
			
			.github-reauth-button {
				color: #0366d6;
				text-decoration: underline;
				background: none;
				border: none;
				padding: 0;
				font: inherit;
				cursor: pointer;
				margin-left: 8px;
			}
			
			.github-reauth-button:hover {
				color: #0056b3;
				text-decoration: underline;
			}
		');
		
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
