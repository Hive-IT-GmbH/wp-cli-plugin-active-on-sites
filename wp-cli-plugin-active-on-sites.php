<?php

/*
Plugin Name: WP-CLI - Plugin Active on Sites
Plugin URI:  https://github.com/iandunn/wp-cli-plugin-active-on-sites
Description: A WP-CLI command to list all sites in a Multisite network that have activated a given plugin
Version:     0.1
Author:      Ian Dunn, Jan Thiel
Author URI:  http://iandunn.name
License:     GPLv2
*/


namespace WP_CLI\Plugin\Active_On_Sites;
use WP_CLI;

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

WP_CLI::add_command( 'plugin active-on-sites', __NAMESPACE__ . '\invoke' );

/**
 * List all sites in a Multisite network that have activated a given plugin.
 *
 * ## OPTIONS
 *
 * <plugin_slug>
 * : The plugin to locate
 *
 * [--field=<field>]
 * : Prints the value of a single field for each site.
 *
 * [--fields=<fields>]
 * : Limit the output to specific object fields.
 *
 * [--format=<format>]
 * : Render output in a particular format.
 * ---
 * default: table
 * options:
 *   - table
 *   - csv
 *   - ids
 *   - json
 *   - count
 *   - yaml
 * ---
 * ## AVAILABLE FIELDS
 *
 * These fields will be displayed by default for each blog:
 *
 * * blog_id
 * * url
 *
 * ## EXAMPLES
 *
 * wp plugin active-on-sites buddypress
 *
 * @param array $args
 * @param array $assoc_args
 */
function invoke( $args, $assoc_args ) {
	reset_display_errors();

	list( $target_plugin ) = $args;

	pre_flight_checks( $target_plugin );
	$found_sites = find_sites_with_plugin( $target_plugin );

	display_results( $target_plugin, $found_sites, $assoc_args );
}

/**
 * Re-set `display_errors` after WP-CLI overrides it
 *
 * Normally WP-CLI disables `display_errors`, regardless of `WP_DEBUG`. This makes it so that `WP_DEBUG` is
 * respected again, so that errors are caught more easily during development.
 *
 * Note that any errors/notices/warnings that PHP throws before this function is called will not be shown, so
 * you should still examine the error log every once in awhile.
 *
 * @see https://github.com/wp-cli/wp-cli/issues/706#issuecomment-203610437
 */
function reset_display_errors() {
	add_filter( 'enable_wp_debug_mode_checks', '__return_true' );
	wp_debug_mode();
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
		WP_CLI::halt(0);
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
	$sites       = get_sites( array( 'number' => 10000 ) );
	$found_sites = array();

	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );

		$active_plugins = get_option( 'active_plugins', array() );
		if ( is_array( $active_plugins ) ) {
			$active_plugins = array_map( 'dirname', $active_plugins );
			if ( in_array( $target_plugin, $active_plugins, true ) ) {
				$found_sites[] = $site;
			}
		}

		restore_current_blog();
	}

	return $found_sites;
}

/**
 * Display a list of sites where the plugin is active
 *
 * @param string $target_plugin
 * @param array  $found_sites
 * @param array $assoc_args
 */
function display_results( $target_plugin, $found_sites, $assoc_args ) {
	if ( ! $found_sites ) {
		WP_CLI::line( "$target_plugin is not active on any sites." );
		return;
	}

	if ( isset( $assoc_args['fields'] ) ) {
		$assoc_args['fields'] = preg_split( '/,[ \t]*/', $assoc_args['fields'] );
	}

	$defaults   = [
		'format' => 'table',
		'fields' => [ 'blog_id', 'url' ],
	];
	$assoc_args = array_merge( $defaults, $assoc_args );
	$site_cols = [ 'blog_id', 'last_updated', 'registered', 'site_id', 'domain', 'path', 'public', 'archived', 'mature', 'spam', 'deleted', 'lang_id' ];
	foreach ( $site_cols as $col ) {
		if ( isset( $assoc_args[ $col ] ) ) {
			$where[ $col ] = $assoc_args[ $col ];
		}
	}

	if ( ! empty( $assoc_args['format'] ) && 'ids' === $assoc_args['format'] ) {
		$ids       = wp_list_pluck( $found_sites, 'blog_id' );
		$formatter = new Formatter( $assoc_args, null, 'site' );
		$formatter->display_items( $ids );
	} else {
		$formatter = new Formatter( $assoc_args, null, 'site' );
		$formatter->display_items( $found_sites );
	}

}
