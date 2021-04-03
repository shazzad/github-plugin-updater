<?php
namespace Shazzad\GithubPlugin;

use WP_Error;

/**
 * WordPress Plugin Updater From Github Repo
 */
class Updater {

	/**
	 * @var string Plugin base file.
	 */
	private $file;

	/**
	 * @var array WordPress plugin data.
	 */
	private $plugin;

	/**
	 * @var string plugin basename.
	 */
	private $basename;

	/**
	 * @var boolean Is plugin active.
	 */
	private $active = false;

	/**
	 * @var boolean Is private repo.
	 */
	private $private_repo = false;

	/**
	 * @var string Github api access token.
	 */
	private $access_token;

	/**
	 * @var string Github repo path.
	 */
	private $repo_path;

	/**
	 * @var number Cache period in seconds
	 */
	private $cache_period = 60;

	/**
	 * @var string Repo owner/organization name.
	 */
	private $owner;

	/**
	 * @var string Repo owner/organization human name, used for access key settings.
	 */
	private $owner_name;

	/**
	 * @var string Option prefix to use while storing data on wp options table.
	 */
	private $option_prefix;

	/**
	 * @var object Github latest release.
	 */
	private $latest_release;

	/**
	 * @var object Github Api.
	 */
	private $api;

	/**
	 * Bootstrap updater.
	 */
	public function __construct( $config = array() ) {
		if ( ! isset( $config['file'], $config['owner'], $config['repo'] ) ) {
			return;
		}

		// Required
		$this->file      = $config['file'];
		$this->owner     = $config['owner'];
		$this->repo      = $config['repo'];
		$this->repo_path = $this->owner . '/' . $this->repo;

		// Is private
		if ( array_key_exists( 'private_repo', $config ) ) {
			$this->private_repo = (bool) $config['private_repo'];
		}

		// Parse additional settings for private repo
		if ( $this->private_repo ) {
			if ( ! empty( $config['owner_name'] ) ) {
				$this->owner_name = $config['owner_name'];
			} else {
				$this->owner_name = strtoupper( $this->owner );
			}
	
			if ( ! empty( $config['option_prefix'] ) ) {
				$this->option_prefix = trim( $config['option_prefix'] );
			} else {
				$this->option_prefix = $this->owner . '_';
			}

			$this->access_token = get_option( $this->prefixed_option( 'github_access_token' ) );

			// Initialize settings panel for private repo.
			$this->initialize_settings();
		}

		$this->api = new Api();
		$this->api->set_repo_path( $this->repo_path );

		if ( $this->access_token ) {
			$this->api->set_access_token( $this->access_token );
		}

		$this->latest_release = new Release();

		// Initialize updater.
		if ( $this->is_ready_to_use_updater() ) {
			$this->initialize_updater();
		}

		// Test.
		// $this->get_readme();
	}

	/**
	 * Private repo will need stored access token
	 */
	private function is_ready_to_use_updater() {
		if ( $this->private_repo && empty( $this->access_token ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Prefix option with owner/option_prefix
	 */
	private function prefixed_option( $option ) {
		return $this->option_prefix . $option;
	}

	/**
	 * Prefix option name with current plugin slug
	 */
	private function prefixed_plugin_option( $option ) {
		return $this->prefixed_option( "{$option}_" . str_replace( '/', '_', $this->repo_path ) );
	}

	/**
	 * Initialize settings option for private repo plugin.
	 */
	private function initialize_settings() {
		// Delete access token error after option is updated.
		add_action( 'update_option_' . $this->prefixed_option( 'github_access_token' ), array( $this, 'after_access_token_updated' ) );
		add_action( 'admin_init', array( $this , 'register_setting_field' ) );
		add_action( 'admin_notices', array( $this, 'access_token_admin_notices' ) );
	}

	/**
	 * After access token is updated, clear stored error & latest release transient data.
	 */
	public function after_access_token_updated() {
		$this->delete_access_token_error();
		$this->delete_latest_release_cache();
		$this->latest_release->set_data( array() );
	}

	/**
	 * Display admin notice related to access token.
	 */
	public function access_token_admin_notices() {
		if ( ! $this->private_repo || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		static $notice_shown;
		if ( ! isset( $notice_shown ) ) {
			$notice_shown = array();
		}

		if ( is_array( $notice_shown ) && in_array( $this->owner, $notice_shown ) ) {
			return;
		}

		if ( empty( $this->access_token ) ) {
			echo "<div class='notice notice-error is-dismissible'> \n";
			echo "<p><strong>";
			printf( 
				__( '%s\'s <a href="%s#%s">github access token</a> is required to receive automatic plugin updates.' ), 
				$this->owner_name,
				admin_url( 'options-general.php' ),
				$this->prefixed_option( 'github_access_token_id' )
			);
			echo "</strong></p>";
			echo "</div> \n";

			$notice_shown[] = $this->owner;
		}

		if ( $this->get_access_token_error() ) {
			echo "<div id='". $this->prefixed_option( 'github_access_token' ) ."' class='notice notice-error is-dismissible'> \n";
			echo "<p>" . sprintf( 
				__( '<strong>%s\'s github access token error:</strong> %s <a href="%s#%s">update here</a>' ), 
				$this->owner_name,
				$this->get_access_token_error(),
				admin_url( 'options-general.php' ),
				$this->prefixed_option( 'github_access_token_id' )
			) . "</p>";
			echo "</div> \n";

			$notice_shown[] = $this->owner;
		}
	}

	private function get_access_token_error() {
		return get_option( $this->prefixed_option( 'github_access_token_error' ) );
	}

	private function save_access_token_error( $error ) {
		update_option( $this->prefixed_option( 'github_access_token_error' ), $error );
	}

	private function delete_access_token_error() {
		delete_option( $this->prefixed_option( 'github_access_token_error' ) );
	}

	private function get_latest_release_cache() {
		return get_transient( $this->prefixed_plugin_option( 'latest_release' ) );
	}

	private function save_latest_release_cache( $data ) {
		set_transient( $this->prefixed_plugin_option( 'latest_release' ), $data, $this->cache_period );
	}

	private function delete_latest_release_cache() {
		delete_transient( $this->prefixed_plugin_option( 'latest_release' ) );
	}

	/**
	 * Register settings field for access token.
	 */
	public function register_setting_field() {
		register_setting( 
			'general',
			$this->prefixed_option( 'github_access_token' ),
			'esc_attr'
		);

		add_settings_field(
			$this->prefixed_option( 'github_access_token_id' ),
			'<label for="'. $this->prefixed_option( 'github_access_token_id' ) .'">' . sprintf( __( '%s\'s Plugin Github Access Token' ), $this->owner_name ) . '</label>',
			array( $this, 'fields_html' ),
			'general'
		);

		global $pagenow;
		if ( 'options-general.php' === $pagenow && ! empty( $this->access_token ) ) {
			static $access_token_checked;
			if ( ! isset( $access_token_checked ) ) {
				$access_token_checked = array();
			}

			if ( is_array( $access_token_checked ) && in_array( $this->owner, $access_token_checked ) ) {
				return;
			}

			$access_token_checked[] = $this->owner;
			$this->fetch_latest_release();
		}
	}

	/**
	 * HTML for extra settings
	 */
	public function fields_html() {
		$value = get_option( $this->prefixed_option( 'github_access_token' ), '' );

		printf( 
			'<input class="regular-text" type="text" id="%s" name="%s" value="%s" />',
			$this->prefixed_option( 'github_access_token_id' ),
			$this->prefixed_option( 'github_access_token' ),
			esc_attr( $value )
		);

		echo '<p class="description">' . sprintf( 
			__( 'This token will be used to fetch update for %s\'s plugins from github' ),
			$this->owner
		) . '</p>';
	}

	private function initialize_updater() {
		add_action( 'admin_init', array( $this, 'set_plugin_properties' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'transient_update_plugins' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_data' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'upgrader_pre_download' ) );
	}

	public function upgrader_pre_download( $reply ) {
		// Add accept header to http request
		add_filter( 'http_request_args', array( $this, 'download_package' ), 15, 2 );

		return $reply;
	}

	public function set_plugin_properties() {
		$this->plugin	= get_plugin_data( $this->file );
		$this->basename = plugin_basename( $this->file );
		$this->active	= is_plugin_active( $this->basename );

		// echo '<pre>';
		// $this->fetch_latest_release();
		// print_r( $this->latest_release );
		// exit;
		// echo '<pre>';
		// print_r( $this->plugin );
		// exit;
	}

	private function fetch_latest_release() {
		if ( false !== $this->get_latest_release_cache() ) {
			$this->latest_release->set_data( $this->get_latest_release_cache() );
			return;
		}

		if ( ! $this->latest_release->available() ) {
	        $release_data = $this->api->get_latest_release();

			if ( is_wp_error( $release_data ) ) {
				$this->save_access_token_error(
					sprintf(
						'%s, code: %s.',
						$release_data->get_error_message(),
						$release_data->get_error_code()
					)
				);

			} else {

				$this->latest_release = new Release( $release_data );
				$this->save_latest_release_cache( $release_data );
				$this->delete_access_token_error();
			}
	    }
	}

	public function get_latest_version_download_url() {
		if ( $this->access_token ) {
			return add_query_arg( 'access_token', $this->access_token, $this->latest_release->get_download_url() );
		}

		return $this->latest_release->get_download_url();
	}


	public function transient_update_plugins( $transient ) {
		if ( property_exists( $transient, 'checked') && ! empty( $transient->checked ) ) {
			$this->fetch_latest_release();

			if ( ! $this->latest_release->available() ) {
				return $transient;
			}

			if ( version_compare( $this->latest_release->get_version(), $this->plugin['Version'], 'gt' ) ) {
				$transient->response[ $this->basename ] = (object) $this->plugin_update_available_response_data();

			} else {
				// No update response is important to whitelist this plugin for automatic update.
				$transient->no_update[ $this->basename ] = (object) $this->plugin_no_update_response_data();
			}
		}

		return $transient;
	}

	public function plugins_api_data( $result, $action, $args ) {
		if ( ! empty( $args->slug ) ) {

			if ( $args->slug == current( explode( '/' , $this->basename ) ) ) {

				$this->fetch_latest_release();

				if ( ! $this->latest_release->available() ) {
					return $result;
				}

				return (object) $this->plugin_api_data();
			}
		}

		return $result;
	}

	private function plugin_no_update_response_data() {
		return array(
			'url'         => $this->plugin["PluginURI"],
			'slug' 	      => current( explode('/', $this->basename ) ),
			'package'     => $this->get_latest_version_download_url(),
			'new_version' => $this->plugin["Version"]
		);
	}

	private function plugin_update_available_response_data() {
		return array(
			'url'          => $this->plugin["PluginURI"],
			'slug' 	       => current( explode('/', $this->basename ) ),
			'package'      => $this->get_latest_version_download_url(),
			'new_version'  => $this->latest_release->get_version(),
			'requires'	   => $this->latest_release->get_requires(),
			'tested'	   => $this->latest_release->get_tested(),
			'requires_php' => $this->latest_release->get_requires_php()
		);
	}

	private function plugin_api_data() {
		$description = $this->api->get_readme( true );
		if ( is_wp_error( $description ) || empty( $description ) ) {
			$description = $this->plugin["Description"];
		}

		return array(
			'name'				=> $this->plugin["Name"],
			'slug'				=> $this->basename,
			'requires'			=> $this->latest_release->get_requires(),
			'tested'			=> $this->latest_release->get_tested(),
			'requires_php'		=> $this->latest_release->get_requires_php(),
			'rating'			=> '',
			'num_ratings'		=> '',
			'downloaded'		=> $this->latest_release->get_download_count(),
			'version'			=> $this->latest_release->get_version(),
			'author'			=> sprintf( '<a href="%s">%s</a>', $this->plugin["AuthorURI"], $this->plugin["AuthorName"] ),
			'last_updated'		=> $this->latest_release->get_date(),
			'homepage'			=> $this->plugin["PluginURI"],
			'short_description' => $this->plugin["Description"],
			'sections'			=> array(
				'Description'	=> $description,
				'changelog'		=> $this->latest_release->get_changelog(),
			),
			'download_link'		=> $this->get_latest_version_download_url()
		);
	}

	public function download_package( $args, $url ) {
		if ( null !== $args['filename'] ) {
			$args = array_merge( $args, array( "headers" => array( "Accept" => "application/octet-stream" ) ) );
		}

		remove_filter( 'http_request_args', array( $this, 'download_package' ), 15, 2 );

		return $args;
	}

	/**
	 * Move plugin files to desired location, and activate if it was active earlier.
	 */
	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		$install_directory = plugin_dir_path( $this->file );
		$wp_filesystem->move( $result['destination'], $install_directory );
		$result['destination'] = $install_directory;

		if ( $this->active ) {
			activate_plugin( $this->basename );
		}

		return $result;
	}
}