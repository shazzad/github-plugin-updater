<?php
namespace Shazzad\GithubPlugin;

/**
 * WordPress Plugin Updater From Github Repo
 */
class Release {

	/**
	 * @var string Data
	 */
	private $data = array(
		// Required fields
		'version'        => null,
		'date'           => null,
		'download_url'   => null,
		'tested'         => null,
		'requires'       => null,
		'requires_php'   => null,

		// Mandatory fields
		'download_count' => 0,
		'body'           => ''
	);

	/**
	 * Fill data.
	 */
	public function set_data( $data ) {
		foreach ( $data as $name => $value ) {
			if ( array_key_exists( $name, $this->data ) ) {
				$this->data[ $name ] = $value;
			}
		}
	}

	/**
	 * Parse data from github release
	 */
	public function parse_data( $data ) {
		if ( ! empty( $data['tag_name'] ) ) {
			$this->data['version'] = $data['tag_name'];
		}

		if ( ! empty( $data['published_at'] ) ) {
			$this->data['date'] = $data['published_at'];
		}

		if ( ! empty( $data['assets'][0]['url'] ) ) {
			$this->data['download_url'] = $data['assets'][0]['url'];
		}

		if ( ! empty( $data['assets'][0]['download_count'] ) ) {
			$this->data['download_count'] = $data['assets'][0]['download_count'];
		}

		if ( ! empty( $data['body'] ) ) {
			$this->data['body'] = $data['body'];

			if ( preg_match( '/Tested up to:\s([\d\.]+)/i', $data['body'], $m ) ) {
				$this->data['tested'] = $m['1'];
			} elseif ( preg_match( '/Tested:\s([\d\.]+)/i', $data['body'], $m ) ) {
				$this->data['tested'] = $m['1'];
			}

			if ( preg_match( '/Requires:\s([\d\.]+)/i', $data['body'], $m ) ) {
				$this->data['requires'] = $m['1'];
			} elseif ( preg_match( '/WordPress:\s([\d\.]+)/i', $data['body'], $m ) ) {
				$this->data['requires'] = $m['1'];
			}

			if ( preg_match( '/Requires Php:\s([\d\.]+)/i', $data['body'], $m ) ) {
				$this->data['requires_php'] = $m['1'];
			} elseif ( preg_match( '/PHP:\s([\d\.]+)/i', $data['body'], $m ) ) {
				$this->data['requires_php'] = $m['1'];
			}
		}
	}

	/**
	 * Get all properties
	 */
	public function get_data() {
		return $this->data;
	}

	/**
	 * Check if release data is available.
	 * 
	 * There's total 6 requied fields, all of
	 * them must be available.
	 */
	public function available() {
		return count( array_filter( $this->data ) ) > 5;
	}

	/**
	 * Allow get_ & set_ methods. Ie: get_version()
	 */
	public function __call( $name, $arguments ) {
		if ( 'get_' === substr( $name, 0, 4 ) ) {
			$name = substr( $name, 4 );
			return array_key_exists( $name, $this->data ) ? $this->data[ $name ] : null;
		} elseif ( 'set_' === substr( $name, 0, 4 ) ) {
			$name = substr( $name, 4 );
			if ( array_key_exists( $name, $this->data ) ) {
				$this->data[ $name ] = $arguments[0];
			}
		}
	}

	/**
	 * Allow direct property getter.
	 */
	public function __get( $name ) {
		return array_key_exists( $name, $this->data ) ? $this->data[ $name ] : null;
	}

	/**
	 * Allow direct property setter.
	 */
	public function __set( $name, $value ) {
		if ( array_key_exists( $name, $this->data ) ) {
			$this->data[ $name ] = $value;
		}
	}

	public function get_version() {
		return $this->data['version'];
	}
}