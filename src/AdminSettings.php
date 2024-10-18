<?php
namespace Shazzad\GithubPlugin;

/**
 * WordPress Plugin Updater Admin Settings
 */
class AdminSettings {

	/**
	 * @var object Updater object.
	 */
	private $updater;

	/**
	 * Construct updater.
	 */
	public function __construct( Updater $updater ) {
		$this->updater = $updater;

		$this->initialize_settings();
	}

	/**
	 * Initialize settings option for private repo plugin.
	 */
	private function initialize_settings() {
		// Delete access token error after option is updated.
		add_action( 'update_option_' . $this->updater->prefixed_option( 'github_access_token' ), array( $this, 'after_access_token_updated' ) );
		add_action( 'admin_init', array( $this, 'register_setting_field' ) );
		add_action( 'admin_notices', array( $this, 'access_token_admin_notices' ) );
	}

	/**
	 * After access token is updated, clear stored error & latest release transient data.
	 */
	public function after_access_token_updated() {
		$this->updater->delete_access_token_error();
		$this->updater->delete_latest_release_cache();
		$this->updater->latest_release->set_data( array() );
	}

	/**
	 * Register settings field for access token.
	 */
	public function register_setting_field() {
		register_setting(
			'general',
			$this->updater->prefixed_option( 'github_access_token' ),
			[ 'sanitize_callback' => 'esc_attr' ]
		);

		add_settings_field(
			$this->updater->prefixed_option( 'github_access_token_id' ),
			'<label for="' . $this->updater->prefixed_option( 'github_access_token_id' ) . '">' . sprintf( __( '%s\'s Github Access Token' ), $this->updater->owner_name ) . '</label>',
			array( $this, 'fields_html' ),
			'general'
		);

		global $pagenow;

		// If user is on general settings page and access token is available.
		if ( 'options-general.php' === $pagenow && ! empty( $this->updater->access_token ) ) {
			static $access_token_checked;
			if ( ! isset( $access_token_checked ) ) {
				$access_token_checked = array();
			}

			if ( is_array( $access_token_checked ) && in_array( $this->updater->owner, $access_token_checked ) ) {
				return;
			}

			$access_token_checked[] = $this->updater->owner;

			$this->updater->fetch_latest_release();
		}
	}

	/**
	 * HTML for extra settings
	 */
	public function fields_html() {
		printf(
			'<input class="regular-text" type="text" id="%s" name="%s" value="%s" />',
			$this->updater->prefixed_option( 'github_access_token_id' ),
			$this->updater->prefixed_option( 'github_access_token' ),
			esc_attr( $this->updater->access_token )
		);

		echo '<p class="description">' . sprintf(
			__( 'This token will be used to fetch update for %s\'s plugins from github' ),
			$this->updater->owner_name
		) . '</p>';
	}

	/**
	 * Display admin notice related to access token.
	 */
	public function access_token_admin_notices() {
		if ( ! $this->updater->private_repo || ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		static $notice_shown;
		if ( ! isset( $notice_shown ) ) {
			$notice_shown = array();
		}

		if ( is_array( $notice_shown ) && in_array( $this->updater->owner, $notice_shown ) ) {
			return;
		}

		if ( empty( $this->updater->access_token ) ) {
			echo "<div class='notice notice-error is-dismissible'> \n";
			echo "<p><strong>";
			printf(
				__( '%s\'s <a href="%s#%s">github access token</a> is required to receive automatic plugin updates.' ),
				$this->updater->owner_name,
				admin_url( 'options-general.php' ),
				$this->updater->prefixed_option( 'github_access_token_id' )
			);
			echo "</strong></p>";
			echo "</div> \n";

			$notice_shown[] = $this->updater->owner;
		}

		if ( $this->updater->get_access_token_error() ) {
			echo "<div id='" . $this->updater->prefixed_option( 'github_access_token' ) . "' class='notice notice-error is-dismissible'> \n";
			echo "<p>" . sprintf(
				__( '<strong>%s\'s github access token error:</strong> %s <a href="%s#%s">update here</a>' ),
				$this->updater->owner_name,
				$this->updater->get_access_token_error(),
				admin_url( 'options-general.php' ),
				$this->updater->prefixed_option( 'github_access_token_id' )
			) . "</p>";
			echo "</div> \n";

			$notice_shown[] = $this->updater->owner;
		}
	}
}