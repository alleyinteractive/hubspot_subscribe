<?php

/**
 * Hubspot Subscribe
 * @version 0.1
 */
/*
Plugin Name: Hubspot Subscribe
Plugin URI: https://github.com/alleyinteractive/hubspot_subscribe
Description: Implements Hubspot's API and provides hooks for building subscribe forms.
Author: Noah Schoenholtz, Matt Johnson
Version: 0.1
Author URI: http://www.alleyinteractive.com/
*/

/*  
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/*
Alley Interactive gratefully acknowledges the generosity of the Henry J. Kaiser Family Foundation in agreeing to release this code to the public under the terms of the GPL.
*/

/**
 * Version number.
 *
 * @var string
 */
define( 'HUBSPOT_SUBSCRIBE_VERSION', '0.1' );
/**
 * Include path.
 *
 * @var string
 */
define( 'HUBSPOT_SUBSCRIBE_PATH', plugin_dir_path( __FILE__ ) );
/**
 * Enqueue path.
 *
 * @var string
 */
define( 'HUBSPOT_SUBSCRIBE_URL', plugin_dir_url( __FILE__ ) );
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	wp_die( esc_html__( 'This file cannot be accessed directly', 'hubspot_subscribe' ) );
}
/**
 * Load classes
 */
function hubspot_subscribe_setup_files() {
	require_once( HUBSPOT_SUBSCRIBE_PATH . 'php/class-hubspot-contacts.php' );
	require_once( HUBSPOT_SUBSCRIBE_PATH . 'php/class-hubspot-config.php' );

	Hubspot_Config();
	Hubspot_Contacts();
}
add_action( 'after_setup_theme', 'hubspot_subscribe_setup_files' );