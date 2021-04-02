<?php
/**
 * Plugin Name: Shazzad's Github Plugin Updater
 * Plugin URI: https://w4dev.com
 * Description: Helper library to implement wordpress plugin update from github
 * Version: 1.0.0
 * Requires at least: 4.4.0
 * Requires PHP: 5.5
 * Author: Shazzad Hossain Khan
 * Author URI: https://shazzad.me
 * Text Domain: shazzad-github-plugin-updater
 * Domain Path: /languages
 * 
 * @package Shazzad\GithubPluginUpdater
 */

include_once __DIR__ . '/src/Updater.php';

new \Shazzad\GithubPlugin\Updater( array(
	'file'         => __FILE__,
	'owner'        => 'shazzad',
	'repo'		   => 'github-plugin-updater',

	// Folloing only required for private repo
	'private_repo' => false,
	'owner_name'   => 'Shazzad'
) );