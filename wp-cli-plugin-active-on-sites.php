<?php

/*
Plugin Name: WP-CLI Plugin Active on Sites
Plugin URI:  https://github.com/iandunn/wp-cli-plugin-active-on-sites
Description: A WP-CLI command to list all sites in a Multisite network that have activated a given plugin
Version:     0.1
Author:      Ian Dunn
Author URI:  http://iandunn.name
License:     GPLv2
*/

/*
 * TODO
 *
 * Write unit tests
 *
 */

namespace WP_CLI\Plugin\Active_On_Sites;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

// Re-set `display_errors` after WP-CLI overrides it, see https://github.com/wp-cli/wp-cli/issues/706#issuecomment-203610437
add_filter( 'enable_wp_debug_mode_checks', '__return_true' );
wp_debug_mode();

WP_CLI::add_command( 'plugin active-on-sites', __NAMESPACE__ . '\invoke' );

/**
 * List all sites in a Multisite network that have activated a given plugin.
 *
 * ## OPTIONS
 *
 * <plugin_slug>
 * : The plugin to locate
 *
 * ## EXAMPLES
 *
 * wp plugin active-on-sites buddypress
 *
 * @param array $args
 * @param array $assoc_args
 */
function invoke( $args, $assoc_args ) {
	list( $target_plugin ) = $args;

	WP_CLI::line();
	pre_flight_checks( $target_plugin );
	$found_sites = find_sites_with_plugin( $target_plugin );

	WP_CLI::line();
	display_results( $target_plugin, $found_sites );
}

/**
 * Check for errors, unmet requirements, etc
 *
 * @param string $target_plugin
 */
function pre_flight_checks( $target_plugin ) {
	if ( ! is_multisite() ) {
		WP_CLI::error( "This only works on Multisite installations. Use `wp plugin list` on regular installations." );
	}

	$installed_plugins = array_map( 'dirname', array_keys( get_plugins() ) );

	if ( ! in_array( $target_plugin, $installed_plugins, true ) ) {
		WP_CLI::error( "$target_plugin is not installed." );
	}

	$network_activated_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
	$network_activated_plugins = array_map( 'dirname', $network_activated_plugins );

	if ( in_array( $target_plugin, $network_activated_plugins, true ) ) {
		WP_CLI::warning( "$target_plugin is network-activated." );
		exit( 0 );
	}
}

/**
 * Find the sites that have the plugin activated
 *
 * @param string $target_plugin
 *
 * @return array
 */
function find_sites_with_plugin( $target_plugin ) {
	$sites       = wp_get_sites( array( 'limit' => false ) );
	$found_sites = array();
	$notify      = new \cli\progress\Bar( 'Checking sites', count( $sites ) );

	foreach ( $sites as $site ) {
		switch_to_blog( $site['blog_id'] );

		$active_plugins = array_map( 'dirname', get_option( 'active_plugins', array() ) );

		if ( in_array( $target_plugin, $active_plugins, true ) ) {
			$found_sites[] = array( $site['blog_id'], $site['domain'] . $site['path'] );
		}

		restore_current_blog();
		$notify->tick();
	}
	$notify->finish();

	return $found_sites;
}

/**
 * Display a list of sites where the plugin is active
 *
 * @param string $target_plugin
 * @param array  $found_sites
 */
function display_results( $target_plugin, $found_sites ) {
	if ( ! $found_sites ) {
		WP_CLI::line( "$target_plugin is not active on any sites." );
		return;
	}

	WP_CLI::line( "Sites where $target_plugin is active:" );

	$table = new \cli\Table();
	$table->setHeaders( array( 'Site ID', 'Site URL' ) );
	$table->setRows( $found_sites );
	$table->display();
}
