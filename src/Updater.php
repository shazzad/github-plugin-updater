<?php
namespace Shazzad\GithubPlugin;

use WP_Error;
use Parsedown;

/**
 * WordPress Plugin Updater From Github Repo
 */
class Updater {

	/**
	 * @var string Plugin base file.
	 */
	public $file;

	/**
	 * @var array WordPress plugin data.
	 */
	public $plugin = null;

	/**
	 * @var string plugin basename.
	 */
	public $basename = null;

	/**
	 * @var string plugin basename.
	 */
	public $slug = null;

	/**
	 * @var boolean Is plugin active.
	 */
	public $active = false;

	/**
	 * @var boolean Is public repo.
	 */
	public $private_repo = false;

	/**
	 * @var string Github api access token.
	 */
	public $access_token;

	/**
	 * @var string Github repo path.
	 */
	public $repo_path;

	/**
	 * @var number Cache period in seconds
	 */
	public $cache_period = 60;

	/**
	 * @var string Repo owner/organization name.
	 */
	public $owner;

	/**
	 * @var string Repo name.
	 */
	public $repo;

	/**
	 * @var string Repo owner/organization human name, used for access key settings.
	 */
	public $owner_name;

	/**
	 * @var string Option prefix to use while storing data on wp options table.
	 */
	public $option_prefix;

	/**
	 * @var object Github latest release.
	 */
	public $latest_release;

	/**
	 * @var object Github Api.
	 */
	public $api;

	/**
	 * Construct updater.
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

		// Is public
		if ( array_key_exists( 'private_repo', $config ) ) {
			$this->private_repo = (bool) $config['private_repo'];
		}

		// Parse additional settings for public repo
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
			new AdminSettings( $this );
		}

		// Github api
		$this->api = new Api();
		$this->api->set_repo_path( $this->repo_path );
		if ( $this->access_token ) {
			$this->api->set_access_token( $this->access_token );
		}

		// Latest release
		$this->latest_release = new Release();

		// Initialize updater.
		if ( $this->is_ready_to_use_updater() ) {
			$this->initialize_updater();
		}
	}

	/**
	 * Register updater hooks/features
	 */
	private function initialize_updater() {
		add_action( 'admin_init', array( $this, 'set_plugin_properties' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'transient_update_plugins' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_data' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'upgrader_pre_download' ) );
	}

	/**
	 * Filter http request args to add accept and authorization header.
	 */
	public function upgrader_pre_download( $reply ) {
		// Add Accept & Authorization header to http request.
		// Github won't prompt zip file download without proper Accept header.

		if ( ! has_filter( 'http_request_args', array( $this, 'http_request_args' ) ) ) {

			$this->set_plugin_properties();
			$this->fetch_latest_release();

			add_filter( 'http_request_args', array( $this, 'http_request_args' ), 12, 2 );
		}

		return $reply;
	}

	/**
	 * Filter download request.
	 */
	public function http_request_args( $args, $url ) {
		if ( null !== $args['filename'] && $url === $this->latest_release->download_url ) {
			// Remove GithubUpdater plugin hooks.
			global $wp_filter;
			if ( isset( $wp_filter['http_request_args'], $wp_filter['http_request_args']->callbacks, $wp_filter['http_request_args']->callbacks[15] ) ) {
				unset( $wp_filter['http_request_args']->callbacks[15] );
			}

			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			$args['headers']['Accept'] = 'application/octet-stream';

			if ( $this->access_token ) {
				$args['headers']['Authorization'] = "token {$this->access_token}";
			}
		}

		return $args;
	}

	/**
	 * Setup plugin information
	 */
	public function set_plugin_properties() {
		if ( is_null( $this->plugin ) ) {
			$this->plugin   = get_plugin_data( $this->file );
			$this->basename = plugin_basename( $this->file );
			$this->slug     = current( explode( '/', $this->basename ) );
			$this->active   = is_plugin_active( $this->basename );
		}
	}

	/**
	 * Load latest release data from cache/api
	 */
	public function fetch_latest_release() {
		if ( $this->latest_release->available() ) {
			return;
		}

		if ( false !== $this->get_latest_release_cache() ) {
			$this->latest_release->set_data( $this->get_latest_release_cache() );
			return;
		}

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

			$this->latest_release->parse_data( $release_data );

			if ( ! $this->latest_release->requires
				|| ! $this->latest_release->requires_php
				|| ! $this->latest_release->tested ) {
				$content = $this->api->get_readme();

				if ( ! is_wp_error( $content ) && ! empty( $content ) ) {
					$meta = $this->parse_requirements_from_markdown( $content );

					foreach ( array( 'tested', 'requires', 'requires_php' ) as $field ) {
						if ( ! empty( $meta[ $field ] ) && empty( $this->latest_release->{$field} ) ) {
							$this->latest_release->{$field} = $meta[ $field ];
						}
					}
				}
			}

			$this->save_latest_release_cache( $this->latest_release->get_data() );
			$this->delete_access_token_error();
		}
	}

	public function plugins_api_data( $result, $action, $args ) {
		if ( ! empty( $args->slug ) ) {
			if ( $args->slug == $this->slug ) {

				$this->fetch_latest_release();

				if ( $this->latest_release->available() ) {
					$this->set_plugin_properties();

					return $this->plugin_api_data();
				}
			}
		}

		return $result;
	}

	public function transient_update_plugins( $transient ) {
		if ( property_exists( $transient, 'checked' ) && ! empty( $transient->checked ) ) {

			$this->fetch_latest_release();

			if ( $this->latest_release->available() ) {
				$this->set_plugin_properties();

				if ( version_compare( $this->latest_release->get_version(), $this->plugin['Version'], 'gt' ) ) {
					$transient->response[ $this->basename ] = $this->plugin_update_available_response_data();
				} else {
					// No update response is important to enable automatic update.
					$transient->no_update[ $this->basename ] = $this->plugin_no_update_response_data();
				}
			}
		}

		return $transient;
	}

	private function plugin_no_update_response_data() {
		return (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $this->plugin["Version"],
			'url'         => $this->plugin["PluginURI"],
			'package'     => $this->latest_release->download_url,
		);
	}

	private function plugin_update_available_response_data() {
		return (object) array(
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $this->latest_release->version,
			'url'          => $this->plugin["PluginURI"],
			'package'      => $this->latest_release->download_url,
			'tested'       => $this->latest_release->tested,
			'requires_php' => $this->latest_release->requires_php,
			'requires'     => $this->latest_release->requires,
		);
	}

	private function plugin_api_data() {
		return (object) array(
			'name'              => $this->plugin["Name"],
			'slug'              => $this->slug,
			'tested'            => $this->latest_release->tested,
			'requires'          => $this->latest_release->requires,
			'requires_php'      => $this->latest_release->requires_php,
			'rating'            => '',
			'num_ratings'       => '',
			'downloaded'        => $this->latest_release->download_count,
			'version'           => $this->latest_release->version,
			'author'            => sprintf(
				'<a href="%s">%s</a>',
				$this->plugin["AuthorURI"],
				$this->plugin["AuthorName"]
			),
			'last_updated'      => $this->latest_release->get_date(),
			'homepage'          => $this->plugin["PluginURI"],
			'short_description' => $this->plugin["Description"],
			'sections'          => array(
				'description' => $this->get_description(),
				'changelog'   => $this->get_changelog(),
			),
			'download_link'     => $this->latest_release->download_url
		);
	}

	private function parse_requirements_from_markdown( $content ) {
		$lines = explode( "\n", $content );

		$meta       = array();
		$meta_found = false;

		foreach ( $lines as $line ) {

			if ( '# Requirements' === $line || '## Requirements' === $line || '### Requirements' === $line ) {
				$meta_found = true;
				continue;
			}

			if ( ! $meta_found ) {
				continue;
			}

			if ( 0 === strpos( $line, '#' ) ) {
				break;
			}

			if ( false !== strpos( $line, ':' ) ) {
				$parts = explode( ":", $line );
				if ( count( $parts ) === 2 ) {
					$meta[ $this->sanitize_meta_name( $parts[0] ) ] = $this->sanitize_meta_value( $parts[1] );
				}
			}
		}

		return $meta;
	}

	private function sanitize_meta_name( $name ) {
		$name = ltrim( $name, '*' );
		$name = strtolower( $name );
		$name = trim( $name );
		$name = preg_replace( '/[^a-z0-9-.]/', '_', $name );

		if ( 'wordpress' === $name ) {
			$name = 'requires';
		} elseif ( 'php' === $name ) {
			$name = 'requires_php';
		}

		return $name;
	}

	private function sanitize_meta_value( $value ) {
		return trim( $value );
	}

	private function get_changelog() {
		$content = $this->api->get_changelog();

		if ( is_wp_error( $content ) || empty( $content ) ) {
			if ( ! empty( $this->latest_release->body ) ) {
				$content = $this->latest_release->body;
			} else {
				$content = __( 'Minor Updates' );
			}

		} else {
			$parsedown = new Parsedown();
			$content   = $parsedown->text( $content );
		}

		return $content;
	}

	private function get_description() {
		$content = $this->api->get_readme();

		if ( is_wp_error( $content ) || empty( $content ) ) {
			$content = $this->plugin["Description"];

		} else {
			$parsedown = new Parsedown();
			$content   = $parsedown->text( $content );

			// Replace all h1, h2 with h4
			$content = preg_replace( '/<(h1|h2|h3)>/', '<h4>', $content );
			$content = preg_replace( '/<\/(h1|h2|h3)>/', '</h4>', $content );
		}

		return $content;
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
	public function prefixed_option( $option ) {
		return $this->option_prefix . $option;
	}

	/**
	 * Prefix option name with plugin slug
	 */
	public function prefixed_plugin_option( $option ) {
		return $this->prefixed_option( "{$option}_{$this->owner}_{$this->repo}" );
	}

	public function get_access_token_error() {
		return get_option( $this->prefixed_option( 'github_access_token_error' ) );
	}

	public function save_access_token_error( $error ) {
		update_option( $this->prefixed_option( 'github_access_token_error' ), $error );
	}

	public function delete_access_token_error() {
		delete_option( $this->prefixed_option( 'github_access_token_error' ) );
	}

	public function get_latest_release_cache() {
		return get_transient( $this->prefixed_plugin_option( 'latest_release' ) );
	}

	public function save_latest_release_cache( $data ) {
		set_transient( $this->prefixed_plugin_option( 'latest_release' ), $data, $this->cache_period );
	}

	public function delete_latest_release_cache() {
		delete_transient( $this->prefixed_plugin_option( 'latest_release' ) );
	}
}