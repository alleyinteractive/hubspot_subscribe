<?php

if ( ! class_exists( 'Hubspot_Config' ) ) :

class Hubspot_Config {

	private static $instance;

	public function setup() {
		if ( function_exists( 'fm_register_submenu_page' ) ) {
			fm_register_submenu_page( 'hubspot_subscribe_settings', 'options-general.php', __( 'Hubspot Settings', 'hubspot_subscribe' ) );
			add_action( 'fm_submenu_hubspot_subscribe_settings', array( $this, 'hubspot_subscribe_settings' ) );

			add_action( 'hubspot_contacts_initialize', array( $this, 'initialize' ) );
		}
	}

	public function hubspot_subscribe_settings() {
		$fm = new Fieldmanager_Group( array(
			'name' => 'hubspot_subscribe_settings',
			'children' => array(
				'api_key' => new Fieldmanager_Textfield( __( 'API Key', 'hubspot_subscribe' ) ),
				'portal_id' => new Fieldmanager_Textfield ( __( 'Portal ID', 'hubspot_subscribe' ) ),
				'encryption_key' => new Fieldmanager_Textfield( __( 'Encryption Key', 'hubspot_subscribe' ) ),
			),
		) );
		$fm->activate_submenu_page();	
	}

	public function initialize() {
		$settings = get_option( 'hubspot_subscribe_settings' );

		Hubspot_Contacts()->api_key = $settings['api_key'];
		Hubspot_Contacts()->portal_id = $settings['portal_id'];
		Hubspot_Contacts()->encryption_key = $settings['encryption_key'];


	}

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Hubspot_Config;
			self::$instance->setup();
		}
		return self::$instance;
	}
}

endif;

function Hubspot_Config() {
	return Hubspot_Config::instance();
}