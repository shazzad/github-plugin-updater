<?php
namespace Shazzad\GithubPlugin;

use WP_Error;
use Parsedown;

/**
 * WordPress Plugin Updater From Github Repo
 */
class Api {

	const GITHUB_API_URL = 'https://api.github.com';

	/**
	 * @var string Github api access token.
	 */
	private $access_token;

	/**
	 * @var string Github repo path.
	 */
	private $repo_path;


	public function set_repo_path( $repo_path ) {
		$this->repo_path = $repo_path;
	}

	public function set_access_token( $access_token ) {
		$this->access_token = $access_token;
	}

	public function get_latest_release() {
		$request_uri = self::GITHUB_API_URL . '/repos/' . $this->repo_path .'/releases?per_page=5';

		$args = array();
		if ( $this->access_token ) {
			$args['headers']['Authorization'] = "token {$this->access_token}";
		}

		$response      = wp_remote_get( $request_uri, $args );
		$body          = wp_remote_retrieve_body( $response );
		$data          = json_decode( $body, true );
		$response_code = wp_remote_retrieve_response_code( $response );
		$error         = '';

		if ( is_wp_error( $body ) ) {
			$error = $body;

		} elseif ( ! in_array( $response_code, array( 200, 201 ) ) ) {
			$error_code = 'api_error';

			if ( 401 === $response_code ) {
				$error_code = 'invalid_authentication';
				$error_message = __( 'Authentication error' );
			} elseif ( isset( $data['message'] ) ) {
				$error_message = $data['message'];
			} else {
				$error_message = sprintf( __( 'Response code received: %d' ), $response_code );
			}

			$error = new WP_Error( $error_code, $error_message, array( 'code' => $response_code ) );
		}

		if ( ! empty( $error ) ) {
			return $error;

		} else {

			foreach ( $data as $release ) {
				if ( ! empty( $release['assets'] ) && count( $release['assets'] ) > 0 ) {
					return $this->sanitize_release_data( $release );
				}
			}
		}
	}

	public function get_readme( $html = false ) {
		$request_uri = self::GITHUB_API_URL . '/repos/' . $this->repo_path .'/readme';

		$args = array(
			'headers' => array(
				'Accept' => 'application/vnd.github.v3.raw'
			)
		);

		if ( $this->access_token ) {
			$args['headers']['Authorization'] = "token {$this->access_token}";
		}

		$response      = wp_remote_get( $request_uri, $args );
		$body          = wp_remote_retrieve_body( $response );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $response_code ) {
			return new WP_Error( 'readme_not_found', __( 'Readme not available' ) );
		}

		if ( $html ) {
			$parsedown = new Parsedown();
			$body = $parsedown->text( $body );
		}

		return $body;

		// $parsedown = new Parsedown();
		// echo '<pre>';
		// print_r( $parsedown->text( $body ) );
		// echo '</pre>';
		// exit;
	}

	private function sanitize_release_data( $release ) {
		unset( $release['author'] );
		foreach ( $release['assets'] as &$asset ) {
			unset( $asset['uploader'] );
		}

		return $release;
	}
}