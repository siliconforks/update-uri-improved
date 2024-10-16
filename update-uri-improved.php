<?php
/**
 * Plugin Name: Update URI Improved
 * Description: Improves the behavior of the "Update URI" plugin header so that plugin information is no longer sent to wordpress.org during update checks.
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Update URI: false
 */

/*
Copyright (C) 2024  siliconforks

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/

namespace siliconforks\update_uri_improved;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * See WP_CLI\Utils\get_plugin_name()
 */
function get_plugin_slug( $basename ) {
	if ( false === strpos( $basename, '/' ) ) {
		$name = basename( $basename, '.php' );
	} else {
		$name = dirname( $basename );
	}

	return $name;
}

function skip_updates( $update_uri_header ) {
	if ( ! $update_uri_header ) {
		return false;
	}

	if ( preg_match( '#^(?:https?://)?w(?:ordpress)?\.org/plugins/#', $update_uri_header ) ) {
		return false;
	}

	return true;
}

function skip_updates_for_plugin( $plugin_slug ) {
	static $plugins = null;
	static $slug_to_file_map = null;

	if ( $plugins === null ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		$slug_to_file_map = [];

		foreach ( $plugins as $plugin_file => $plugin_headers ) {
			$key = get_plugin_slug( $plugin_file );
			$slug_to_file_map[ $key ] = $plugin_file;
		}
	}

	if ( isset( $slug_to_file_map[ $plugin_slug ] ) ) {
		$plugin_file = $slug_to_file_map[ $plugin_slug ];
		if ( isset( $plugins[ $plugin_file ] ) ) {
			$plugin_headers = $plugins[ $plugin_file ];
			if ( is_array( $plugin_headers ) && isset( $plugin_headers['UpdateURI'] ) && skip_updates( $plugin_headers['UpdateURI'] ) ) {
				return true;
			} else {
				return false;
			}
		}
	}

	return false;
}

function http_request_args( $parsed_args, $url ) {
	$parsed_url = wp_parse_url( $url );
	if ( ! is_array( $parsed_url ) ) {
		return $parsed_args;
	}

	if ( ! isset( $parsed_url['host'] ) ) {
		return $parsed_args;
	}

	$host = $parsed_url['host'];
	$host = strtolower( $host );
	if ( $host !== 'api.wordpress.org' ) {
		return $parsed_args;
	}

	if ( ! isset( $parsed_url['path'] ) ) {
		return $parsed_args;
	}

	/*
	TODO: What if the URL path is something like /plugins/update-check/1.2/ ?
	*/
	$path = $parsed_url['path'];
	if ( $path !== '/plugins/update-check/1.1/' ) {
		return $parsed_args;
	}

	if ( ! isset( $parsed_args['method'] ) ) {
		return $parsed_args;
	}

	$method = $parsed_args['method'];
	if ( $method !== 'POST' ) {
		return $parsed_args;
	}

	if ( ! isset( $parsed_args['body'] ) ) {
		return $parsed_args;
	}

	$body = $parsed_args['body'];
	if ( is_array( $body ) && isset( $body['plugins'] ) ) {
		$json = $body['plugins'];
		$json = json_decode( $json, true );
		if ( is_array( $json ) && isset( $json['plugins'], $json['active'] ) ) {
			$plugins = $json['plugins'];
			$active = $json['active'];

			$new_plugins = [];
			foreach ( $plugins as $plugin_file => $plugin_headers ) {
				if ( is_array( $plugin_headers ) && isset( $plugin_headers['UpdateURI'] ) && skip_updates( $plugin_headers['UpdateURI'] ) ) {
					continue;
				}

				$new_plugins[ $plugin_file ] = $plugin_headers;
			}

			$new_active = [];
			foreach ( $active as $plugin_file ) {
				if ( isset( $plugins[ $plugin_file ] ) ) {
					$plugin_headers = $plugins[ $plugin_file ];
					if ( is_array( $plugin_headers ) && isset( $plugin_headers['UpdateURI'] ) && skip_updates( $plugin_headers['UpdateURI'] ) ) {
						continue;
					}
				}

				$new_active[] = $plugin_file;
			}

			$to_send = [
				'plugins' => $new_plugins,
				'active' => $new_active,
			];
			$parsed_args['body']['plugins'] = wp_json_encode( $to_send );
		}
	}

	return $parsed_args;
}

add_filter( 'http_request_args', __NAMESPACE__ . '\http_request_args', 10, 2 );

function plugins_api( $result, $action, $args ) {
	if ( $action === 'plugin_information' && is_object( $args ) && property_exists( $args, 'slug' ) && skip_updates_for_plugin( $args->slug ) ) {
		$result = new WP_Error( 'plugins_api_failed', 'Request for plugin with Update URI blocked.' );
	}
	return $result;
}

add_filter( 'plugins_api', __NAMESPACE__ . '\plugins_api', 10, 3 );
