<?php
namespace Shazzad\GithubPlugin;

/**
 * WordPress Plugin Updater From Github Repo
 */
class Release {

	/**
	 * @var string Data
	 */
	private $data = array();

	public function __construct( $data = array() ) {
		$this->set_data( $data );
	}

	public function set_data( $data ) {
		$this->data = $data;
	}

	public function get_data() {
		return $this->data;
	}

	public function available() {
		return ! empty( $this->data );
	}

	public function get_version() {
		return $this->data['tag_name'];
	}
	
	public function get_date() {
		return $this->data['published_at'];
	}

	public function get_changelog() {
		return str_replace( "\r\n", '<br />', $this->data['body'] );
	}

	public function get_download_url() {
		return $this->data['assets'][0]['url'];
	}

	public function get_download_count() {
		return $this->data['assets'][0]['download_count'];
	}

	public function get_requires() {
		if ( preg_match( '/Requires:\s([\d\.]+)/i', $this->data['body'], $m ) ) {
			return $m['1'];
		}

		return '5.0';
	}

	public function get_tested() {
		if ( preg_match( '/Tested up to:\s([\d\.]+)/i', $this->data['body'], $m ) ) {
			return $m['1'];
		} elseif ( preg_match( '/Tested:\s([\d\.]+)/i', $this->data['body'], $m ) ) {
			return $m['1'];
		}

		return '5.0';
	}

	public function get_requires_php() {
		if ( preg_match( '/Requires Php:\s([\d\.]+)/i', $this->data['body'], $m ) ) {
			return $m['1'];
		}

		return '5.6';
	}
}