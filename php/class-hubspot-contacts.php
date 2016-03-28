<?php

/**
 * A class for interacting with the HubSpot API by Alley Interactive
 *
 * @link https://developers.hubspot.com/docs/endpoints
 * @author Noah Schoenholtz
 */

if ( ! class_exists( 'Hubspot_Contacts' ) ) :

class Hubspot_Contacts {
	const STATUS_ERROR = 'error';
	const STATUS_SUCCESS = 'success';
	const STATUS_SIGNUP = 'signup';
	const STATUS_SETTINGS = 'settings';

	const WORKFLOW_SIGNUP = 'signup';
	const WORKFLOW_UPDATE = 'update';

	const AJAX_NONCE_NAME = 'hubspot-ajax-validation-nonce';
	const AJAX_NONCE_PARAM = 'hubpost_ajax_security';

	/**
	 * Associative array of messages, can be overriden with custom messages in the configuration
	 *
	 * @var array $messages
	 */
	// public $messages = array(
	// 	'error' => __( 'Sorry, an error occurred, please try again.', 'hubspot_subscribe' ),
	// 	'signed_up' => __( 'You\'re already signed up! Please check your inbox for a confirmation message with a link to change your settings.', 'hubspot_subscribe' ),
	// 	'update_error' => __( 'Failed to update your data, please try again.', 'hubspot_subscribe' ),
	// 	'signup_error' => __( 'Failed to create your subscription, please try again.', 'hubspot_subscribe' ),
	// 	'settings_saved' => __( 'Your settings have been saved. <a href="%s">Edit Settings</a>', 'hubspot_subscribe' ),
	// 	'not_found' => __( 'Your settings could not be found.', 'hubspot_subscribe' ),
	// 	'opt_out' => __( 'You have opted out of all email subscriptions. To opt back in, use the link in one of the emails you\'ve already received. If you\'ve recently opted back in, please allow 5-10 minutes.', 'hubspot_subscribe' ),
	// 	'opt_out_error' => __( 'Sorry, an error occurred. Please try again and if the problem persists, contact us.', 'hubspot_subscribe' ),
	// );

	/**
	 * Hubspot API key @link https://app.hubspot.com/keys/get, not the same as Oauth keys.
	 *
	 * @var string $api_key hubspot "hapikey"
	 */
	public $api_key;

	/**
	 * HubSpot portal id, required by the opt out handling API
	 *
	 * @var int $portal_id
	 */
	public $portal_id;

	/**
	 * Hubspot workflows, if null any request will show the settings page.
	 *
	 * If a value is set with key WORKFLOW_SIGNUP, existing contacts will not see the settings
	 * page and instead will be added to a workflow in HubSpot that should send them a link to
	 * view and edit their settings. New contacts will be sent this email after submitting the
	 * settings page and having their contact created in HubSpot.
	 *
	 * If a value is set with key WORKFLOW_UPDATE, existing contacts will be added to that workflow
	 * after they update their settings. This can be used to send a confirmation email.
	 *
	 * @var array $workflows associative array of hubspot workflow ids keyed by type
	 */
	public $workflows = array();

	/**
	 * A string indicating the current state of the user/session currently interacting with HubSpot
	 *
	 * This status will be matched against the constants STATUS_ERROR, STATUS_SUCCESS, etc
	 *
	 * @var string $status
	 */
	public $status;

	/**
	 * The current message to be displayed to user, so that message can be decoupled from status
	 * @var string $message
	 */
	public $message;

	/**
	 * The configurable key used encrypt and decrypt emails for token generation @see secret_key
	 * @var string $encryption_key
	 */
	public $encryption_key;

	/**
	 * The secret key generated from the encryption key for use encrypting and decrypting email tokens
	 *
	 * @see initialize
	 *
	 * @var string $secret_key
	 */
	private $secret_key;

	/**
	 * Hubspot contact property configuration.
	 *
	 * @var array $properties by default, this contains a subset of the default properties created by Hubspot
	 */
	public $properties = array(
		'email' => array( 'label' => 'Email', 'type' => 'email', 'required' => true, 'autofocus' => true ),
	);

	/**
	 * Property configuration options that are used in special ways instead of created as HTML attributes
	 * @var array $reserved_attributes
	 */
	private $reserved_attributes = array( 'type', 'label', 'options' );

	private $basic_inputs = array( 'text', 'hidden', 'tel', 'number', 'email' );

	private $api_path = 'https://api.hubapi.com/contacts/v1';

	private $workflow_path = 'https://api.hubapi.com/automation/v2/workflows/%s/enrollments/contacts/%s?hapikey=%s';

	// &unsubscribeFromAll=true doesn't seem to work so we're basing the implementation on single-list accounts right now
	private $opt_out_path = 'https://api.hubapi.com/email/public/v1/subscriptions/%s?portalId=%s&hapikey=%s';

	/**
	 * @var string $form_container wrapper for post parameters
	 */
	private $form_container = 'hubspot_contact';

	private $nonce_name = 'hubspot_contacts_nonce';

	private $contact_data;

	private $is_initialized;

	private static $instance;

	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	public function __clone() { wp_die( esc_html__( "Please don't __clone Hubspot_Contacts" ) ); }

	public function __wakeup() { wp_die( esc_html__( "Please don't __wakeup Hubspot_Contacts" ) ); }

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Hubspot_Contacts;
			self::$instance->setup();
		}
		return self::$instance;
	}

	/**
	 * Register WP hooks and other one-time setup
	 *
	 * This function registers ajax hooks, the shortcode, and the signup email query variable
	 */
	public function setup() {
		global $wp;

		$this->is_initialized = false;

		$wp->add_query_var( 'subscription-id' );
		$wp->add_query_var( 'subscription-token' );

		add_shortcode( 'hubspot_contacts', array( $this, 'handle_shortcode' ) );

		add_action( 'wp_ajax_hubspot_contacts_signup', array( $this, 'ajax_handler' ) );
		add_action( 'wp_ajax_nopriv_hubspot_contacts_signup', array( $this, 'ajax_handler' ) );
		add_action( 'wp_ajax_hubspot_contacts_update', array( $this, 'ajax_handler' ) );
		add_action( 'wp_ajax_nopriv_hubspot_contacts_update', array( $this, 'ajax_handler' ) );
		add_action( 'wp_ajax_hubspot_contacts_opt_out', array( $this, 'ajax_handler' ) );
		add_action( 'wp_ajax_nopriv_hubspot_contacts_opt_out', array( $this, 'ajax_handler' ) );
	}

	/**
	 * Initialize the plugin when requested for usage
	 *
	 * This function will only execute its logic once even when called multiple times. It enqueues the javascript for the plugin
	 * and executes the "hubspot_contacts_initialize" action hook which can be bound to set configuration options. Then, it checks
	 * for posted data or request variables and if found, handles the request. This can result in @see create_contact or
	 * @see update_contact execution (or neither). Nonces for the relevant forms will be verified here and if invalid, will not
	 * delegate to the form handlers.
	 *
	 * @see get_input_value
	 * @throws Exception if the API key has not been set @see $api_key
	 */
	public function initialize() {
		// defer initialization and form handling until required by a method
		if ( ! $this->is_initialized ) {
			$this->is_initialized = true;

			wp_enqueue_script( 'hubspot-contacts-ajax', HUBSPOT_SUBSCRIBE_URL . 'js/hubspot-contacts.js' , array( 'jquery' ) );
			wp_localize_script( 'hubspot-contacts-ajax', 'HubspotContacts', array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'ajax_security_param' => self::AJAX_NONCE_PARAM,
				'ajax_security_value' => wp_create_nonce( self::AJAX_NONCE_NAME ),
				'form_container' => $this->form_container,
				'STATUS_SIGNUP' => self::STATUS_SIGNUP,
				'STATUS_ERROR' => self::STATUS_ERROR,
				'STATUS_SUCCESS' => self::STATUS_SUCCESS,
				'STATUS_SETTINGS' => self::STATUS_SETTINGS,
			) );

			do_action( 'hubspot_contacts_initialize', $this );

			if ( empty( $this->api_key ) ) {
				throw new Exception( 'Error: HubSpot API Key must be set' );
			}

			if ( ! empty( $this->encryption_key ) ) {
				$this->secret_key = substr( hash( 'sha256', $this->encryption_key, true ), 0, 8 );
			}

			// check for post of data
			if ( $this->verify_nonce( 'opt-out' ) ) {
				$this->opt_out_contact();
			} elseif ( intval( $this->get_input_value( 'vid' ) ) ) {
				if ( $this->verify_nonce( 'settings' ) ) {
					$this->update_contact();
				} else {
					$this->message = $this->messages['error'];
					$this->status = self::STATUS_SIGNUP;
				}
			} elseif ( $email = sanitize_email( $this->get_input_value( 'email' ) ) ) {
				if ( $this->verify_nonce( 'settings' ) ) {
					$this->create_contact( $email );
				} elseif ( $this->verify_nonce( 'signup' ) ) {
					$this->check_contact( $email );
				} else {
					$this->message = $this->messages['error'];
					$this->status = self::STATUS_SIGNUP;
				}
			} elseif ( $id = intval( get_query_var( 'subscription-id' ) ) ) {
				$this->get_contact_by_id( $id );
			} elseif ( $token = get_query_var( 'subscription-token' ) ) {
				if ( isset( $this->secret_key ) && $email = sanitize_email( $this->decrypt( $token ) ) ) {
					$this->get_contact_by_email( $email );
				} else {
					$this->message = $this->messages['not_found'];
					$this->status = self::STATUS_SIGNUP;
				}
			}
		}
	}

	/**
	 * Check to see if a contact exists set status based on the result
	 *
	 * Status will be STATUS_SETTINGS if the contact did not exist, or STATUS_SUCCESS if it did.
	 *
	 * @param string $email
	 * @return boolean true if the query was successful
	 */
	protected function check_contact( $email ) {
		$this->message = null;

		$response = vip_safe_wp_remote_get( $this->get_api_url( "contact/email/{$email}/profile" ) );
		if ( $this->is_api_success( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! empty( $data['vid'] ) && intval( $data['vid'] ) ) {
				if ( $this->is_opted_out( $data ) ) {
					$this->message = $this->messages['opt_out'];
					$this->status = self::STATUS_SUCCESS;
					return false;
				} elseif ( isset( $this->workflows[ self::WORKFLOW_SIGNUP ] ) ) {
					if ( $this->get_hubspot_property( $data, 'token' ) ) {
						// contact exists and has token, show welcome page and trigger email
						$this->status = self::STATUS_SUCCESS;
						$this->message = $this->messages['signed_up'];
						$this->send_workflow( $email, self::WORKFLOW_SIGNUP );
					} else {
						// contact exists, but should be treated as new -- go to settings page
						$this->status = self::STATUS_SETTINGS;
						$this->contact_data = array(
							'vid' => $data['vid'],
							'email' => $this->get_hubspot_property( $data, 'email' ),
						);
					}
				} else {
					// contact exists, go to settings page
					$this->status = self::STATUS_SETTINGS;
					$this->contact_data = array( 'vid' => $data['vid'] );
					foreach ( $data['properties'] as $key => $data ) {
						$this->contact_data[ $key ] = $data['value'];
					}
				}
				return true;
			}
		} elseif ( 404 === wp_remote_retrieve_response_code( $response ) ) {
			// show settings form to allow a new contact
			$this->contact_data = array( 'email' => $email );
			$this->status = self::STATUS_SETTINGS;
			return true;
		}

		$this->message = $this->messages['signup_error'];
		$this->status = self::STATUS_ERROR;
		return false;
	}

	/**
	 * Performs a contact fetch via vid from the HubSpot API @see process_get_contact_response
	 *
	 * @param int $id
	 * @return boolean false if it did not obtain a valid contact
	 */
	protected function get_contact_by_id( $id ) {
		return $this->process_get_contact_response( vip_safe_wp_remote_get( $this->get_api_url( "contact/vid/{$id}/profile" ) ) );
	}

	/**
	 * Performs a contact fetch via email from the HubSpot API @see process_get_contact_response
	 *
	 * @param string $email
	 * @return boolean false if it did not obtain a valid contact
	 */
	protected function get_contact_by_email( $email ) {
		return $this->process_get_contact_response( vip_safe_wp_remote_get( $this->get_api_url( "contact/email/{$email}/profile" ) ) );
	}

	/**
	 * Processes a contact fetch response from the HubSpot API
	 *
	 * @param array $response a response from vip_safe_wp_remote_get
	 * @return boolean false if it did not obtain a valid contact
	 */
	protected function process_get_contact_response( $response ) {
		if ( $this->is_api_success( $response ) ) {
			// response body contains json encoded contact data
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( false !== $data ) {
				if ( $this->is_opted_out( $data ) ) {
					$this->message = $this->messages['opt_out'];
					$this->status = self::STATUS_SUCCESS;
					return false;
				} elseif ( $email = sanitize_email( $this->get_hubspot_property( $data, 'email' ) ) ) {
					$this->contact_data = array( 'vid' => $data['vid'] );
					foreach ( $data['properties'] as $key => $data ) {
						$this->contact_data[ $key ] = $data['value'];
					}

					$this->status = self::STATUS_SETTINGS;
					return true;
				} else {
					$this->contact_data = null;
				}
			}
		}

		$this->message = $this->messages['not_found'];
		$this->status = self::STATUS_SIGNUP;
		return false;
	}

	/**
	 * Posts to the Hubspot API to create a contact @see get_properties_for_post
	 *
	 * @param string $email
	 * @return boolean false if it does not successfully create a contact in the Hubspot API
	 */
	protected function create_contact( $email ) {
		$this->message = null;

		$this->contact_data = array();
		$properties = apply_filters( 'hubspot_contacts_properties', $this->get_properties_for_post(), 'create' );
		$response = $this->send_post( 'contact', array( 'properties' => $properties ) );

		if ( $this->is_api_success( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( empty( $data['status'] ) || $data['status'] !== 'error' ) {
				$this->message = sprintf( $this->messages['settings_saved'], add_query_arg( 'subscription-id', $data['vid'], '' ) );
				$this->status = self::STATUS_SUCCESS;
				$this->send_workflow( $email, self::WORKFLOW_SIGNUP );

				return true;
			}
		}

		$this->message = $this->messages['signup_error'];
		$this->status = self::STATUS_ERROR;
		return false;
	}

	/**
	 * Posts to the Hubspot API to update the properties for a contact @see get_properties_for_post
	 *
	 * @return boolean false if it does not successfully send the updates to the Hubspot API
	 */
	protected function update_contact() {
		$this->message = null;

		if ( $contact_id = intval( $this->get_input_value( 'vid' ) ) ) {
			$this->contact_data = array( 'vid' => $contact_id );
			$properties = apply_filters( 'hubspot_contacts_properties', $this->get_properties_for_post(), 'update' );
			$response = $this->send_post( "contact/vid/{$contact_id}/profile", array( 'properties' => $properties ) );
			if ( $this->is_api_success( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( empty( $data['status'] ) || $data['status'] !== 'error' ) {
					$this->message = sprintf( $this->messages['settings_saved'], add_query_arg( 'subscription-id', $contact_id, '' ) );
					$this->status = self::STATUS_SUCCESS;
					$this->send_workflow( sanitize_email( $this->get_input_value( 'email' ) ), self::WORKFLOW_UPDATE );
					return true;
				}
			}
		}

		$this->message = $this->messages['update_error'];
		$this->status = self::STATUS_ERROR;
		return false;
	}

	/**
	 * Send a request to opt out the current contact to the HubSpot API
	 *
	 * @return boolean false if the opt-out request did not succeed
	 */
	protected function opt_out_contact() {
		$this->message = null;

		if ( $email = sanitize_email( $this->get_input_value( 'email' ) ) ) {
			if ( $this->send_opt_out( $email ) ) {
				$this->message = $this->messages['opt_out'];
				$this->status = self::STATUS_SUCCESS;
				return true;
			}
		}

		$this->message = $this->messages['opt_out_error'];
		$this->status = self::STATUS_ERROR;
		return false;
	}

	/**
	 * Processes the properties sent in the request to be sent in a post to the API. All values are sanitized.
	 *
	 * This function looks for contact properties in the request based on property configuration
	 * and sanitizes and formats the values to be sent to he HubSpot API.
	 * @see get_input_value
	 * @see $properties
	 *
	 * @return array properties in hubspot api format
	 */
	protected function get_properties_for_post() {
		$properties = array();

		foreach ( $this->properties as $key => $data ) {
			$value = $this->get_input_value( $key );
			if ( $data['type'] === 'checkbox' ) {
				$properties[] = array( 'property' => $key, 'value' => ! empty( $value ) );
				$this->contact_data[ $key ] = empty( $value ) ? '' : 'true';
			} else {
				switch ( $key ) {
					case 'email':
						$value = sanitize_email( $value );
						break;
					case 'vid':
						$value = absint( $value );
						break;
					default:
						$value = sanitize_text_field( $value );
				}
				$properties[] = array( 'property' => $key, 'value' => $value );
				$this->contact_data[ $key ] = $value;
			}
		}

		if ( isset( $this->workflows[ self::WORKFLOW_SIGNUP ] ) && isset( $this->secret_key ) ) {
			$properties[] = array( 'property' => 'token', 'value' => urlencode( $this->encrypt( $this->contact_data['email'] ) ) );
		}

		return $properties;
	}

	/**
	 * AJAX callback wrapping a request
	 *
	 * Processing of requests happens during initialization, so this wrapper just calls initialize
	 * and then echoes json encoded status, message, and sometimes contact data for client-side handling if successful.
	 */
	public function ajax_handler() {
		header( 'Content-Type: application/json' );

		check_ajax_referer( self::AJAX_NONCE_NAME, self::AJAX_NONCE_PARAM );

		$this->initialize();

		if ( $this->status === self::STATUS_ERROR ) {
			status_header( 500 );
			die( json_encode( $this->message ) );
		}

		// messages are configurable static text
		$data = array(
			'status' => $this->status,
			'message' => $this->message,
			'contact_data' => $this->contact_data,
		);

		die( json_encode( $data ) );
	}

	/**
	 * Output a simple form allowing contact signup and update based on configured @see $properties
	 *
	 * @return string buffer containing form html
	 */
	public function handle_shortcode() {
		$this->initialize();

		if ( empty( $this->api_key ) ) {
			return esc_html__( 'Error: HubSpot API Key must be set', 'hubspot_subscribe' );
		}

		ob_start();
		?>
		<div class="hubspot-contacts">
			<div class="messages"<?php if ( ! $this->status || ! $this->message ): ?> style="display: none;"<?php endif; ?>>
				<?php $this->the_message_element(); ?>
			</div>
			<?php if ( $this->status !== self::STATUS_SUCCESS ): ?>
				<?php if ( ! $this->show_settings() ): ?>
					<form method="post" data-hubspot-ajax-action="hubspot_contacts_signup">
						<?php $this->the_nonce_field( 'signup' ); ?>
						<?php $this->the_form_element( 'email' ); ?>
						<button type="submit"><?php esc_html_e( 'Sign Up', 'hubspot_subscribe' ); ?></button>
					</form>
				<?php endif; ?>
				<form method="post" data-hubspot-ajax-action="hubspot_contacts_update"<?php if ( ! $this->show_settings() ): ?> style="display: none;"<?php endif; ?>>
					<?php
					$this->the_nonce_field( 'settings' );
					$this->the_form_element( 'vid', array( 'type' => 'hidden' ) );
					$this->render_all_properties();
					?>

					<button type="submit"><?php esc_html_e( 'Save', 'hubspot_subscribe' ); ?></button>
				</form>
				<form class="opt-out" method="post" data-hubspot-ajax-action="hubspot_contacts_opt_out"<?php if ( ! $this->is_signed_up() ): ?> style="display: none;"<?php endif; ?>>
					<?php
					$this->the_nonce_field( 'opt-out' );
					$this->the_form_element( 'email', array( 'type' => 'hidden' ) );
					?>
					<button type="submit"><?php esc_html_e( 'Opt Out of All Emails', 'hubspot_subscribe' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		$output = ob_get_clean();

		return apply_filters( 'hubspot_contacts_shortcode', $output, $this );
	}

	/**
	 * Echo the status message if set.
	 */
	public function the_message_element() {
		if ( isset( $this->status ) ) {
			// messages are configurable static text which may contain markup
			echo sprintf( '<div class="message %s">%s</div>', esc_attr( $this->status ), wp_kses_post( $this->message ) );
		}
	}

	/**
	 * Determine if the settings view should be shown.
	 *
	 * @return boolean true if settings should be displayed
	 */
	public function show_settings() {
		$this->initialize();

		return $this->status === self::STATUS_SETTINGS || $this->is_signed_up();
	}

	/**
	 * Does the current request contain information about a contact who has signed up?
	 *
	 * @return boolean true if the contact exists
	 */
	public function is_signed_up() {
		$this->initialize();

		return intval( $this->contact_data['vid'] ) > 1;
	}

	/**
	 * Does the current request contain a contact who's opted out of emails?
	 *
	 * @param array $data array of data returned by a call to the contact detail API
	 * @return boolean true if the contact is opted out either globally or to the configured subscription
	 */
	public function is_opted_out( $data ) {
		$this->initialize();

		return 'true' === $this->get_hubspot_property( $data, 'hs_email_optout' ) ||
			'true' === $this->get_hubspot_property( $data, "hs_email_optout_{$this->subscription_id}" );
	}

	/**
	 * Echoes a WP nonce field @see wp_nonce_field
	 *
	 * @param string $action name of action
	 */
	public function the_nonce_field( $action ) {
		wp_nonce_field( $action, $this->nonce_name );
	}

	/**
	 * Verifies a nonce in the request @see wp_verify_nonce
	 *
	 * @param string $action name of action to verify
	 * @return boolean true if the request contains a valid nonce
	 */
	public function verify_nonce( $action ) {
		return empty( $_POST[ $this->nonce_name ] ) ? null : wp_verify_nonce( sanitize_text_field( $_POST[ $this->nonce_name ] ), $action );
	}

	/**
	 * Echoes an HTML form element for a contact property
	 *
	 * This function will construct a form element for a configured property @see $properties, optionally overriding
	 * the configured settings with the $data array parameter, echo the result after applying "hubspot_contacts_property"
	 * filters.
	 *
	 * @param string $key the unique key of the hubspot contact property
	 * @param array $data optionally override the configured property options
	 */
	public function the_form_element( $key, $data = null ) {
		$this->initialize();

		if ( empty( $data ) ) {
			$data = $this->properties[ $key ];
		}

		if ( empty( $data ) ) {
			return;
		}

		if ( empty( $data['default'] ) ) {
			$data['default'] = null;
		}

		if ( ! empty( $data['required'] ) && $data['required'] === true ) {
			$data['required'] = 'required';
		}

		if ( ! empty( $data['label'] ) ) {
			// labels are configurable static text which may contain markup
			$data['label'] = wp_kses_post( $data['label'] );
		} else {
			$data['label'] = false;
		}

		$current = empty( $this->contact_data[ $key ] ) ? $data['default'] : $this->contact_data[ $key ];
		$key = esc_attr( $this->get_input_name( $key ) );

		$attributes = $this->render_attributes( $data );

		if ( $data['type'] === 'checkbox' ) {
			$selected = $current === 'true' ? ' checked' : '';
			$field = sprintf( '<input type="checkbox" value="1" name="%s"%s%s /> %s', $key, $selected, $attributes, $data['label'] );
		} elseif ( in_array( $data['type'], $this->basic_inputs ) ) {
			$selected = empty( $current ) ? '' : sprintf( ' value="%s"', esc_attr( $current ) );
			$field = sprintf( '<span>%s</span> <input type="%s" name="%s"%s%s />', $data['label'], $data['type'], $key, $attributes, $selected );
		} elseif ( $data['type'] === 'select' ) {
			$field = sprintf( '<span>%s</span> <select name="%s"%s>', $data['label'], $key, $attributes );

			// determine if array is associative
			$is_associative = false;
			$keys = array_keys( $data['options'] );
			for ( $i = 0; $i < count( $data['options'] ); $i++ ) {
				if ( $i !== $keys[ $i ] ) {
					$is_associative = true;
					break;
				}
			}
			foreach ( $data['options'] as $value => $label ) {
				// labels are configurable static text which may contain markup
				if ( ! $is_associative ) {
					$value = $label;
				}
				$selected = (string) $current === (string) $value ? ' selected' : '';
				$field .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $value ), $selected, wp_kses_post( $label ) );
			}

			$field .= '</select>';
		} else {
			$field = esc_html__( 'Unknown property type ', 'hubspot_subscribe' ) . esc_html( $data['type'] );
		}

		if ( ! empty( $data['label'] ) ) {
			$field = "<label>{$field}</label><br>";
		}

		echo apply_filters( 'hubspot_contacts_property', $field, $key, $data );
	}

	/**
	 * Translate array configuration into HTML element attributes
	 *
	 * @param array $data
	 * @return string buffer containing HTML attributes
	 */
	protected function render_attributes( $data ) {
		$attributes = '';

		foreach ( $data as $key => $val ) {
			$key = sanitize_key( $key );
			if ( ! in_array( $key, $this->reserved_attributes ) ) {
				$attributes .= sprintf( ' %s="%s"', $key, esc_attr( $val ) );
			}
		}

		return $attributes;
	}

	/**
	 * Iterate over all configured properties and render each one @see properties and @see the_form_element
	 */
	public function render_all_properties() {
		foreach ( $this->properties as $key => $data ) {
			$this->the_form_element( $key, $data );
		}
	}

	/**
	 * Takes the array of data as returned by the HubSpot API and a key identifying one property
	 * and returns the value for that property if it exists.
	 *
	 * @param array $data
	 * @param string $property
	 * @return mixed value of requested property
	 */
	public function get_hubspot_property( $data, $property ) {
		return empty( $data['properties'][ $property ] ) ? null : $data['properties'][ $property ]['value'];
	}

	/**
	 * Format the name element attribute for a property using the form container @see form_container
	 *
	 * @return string element name attribute, e.g., "hubspot_contact[property_key]"
	 */
	public function get_input_name( $property ) {
		return "{$this->form_container}[{$property}]";
	}

	/**
	 * Retrieve the value for a property from the post request using the form container @see form_container
	 * Note: always sanitized after retrieval, since contents may vary.
	 *
	 * @global array $_POST
	 * @param $property the property key
	 * @return mixed the property value sent in the post request
	 */
	public function get_input_value( $property ) {
		return empty( $_POST[ $this->form_container ][ $property ] ) ? null : $_POST[ $this->form_container ][ $property ];
	}

	/**
	 * Set the value for a property in the post request using the form container @see form_container
	 *
	 * @global array $_POST
	 * @param $property the property key
	 * @param $value the property value
	 */
	public function set_input_value( $property, $value ) {
		$_POST[ $this->form_container ][ $property ] = $value;
	}

	/**
	 * Return the HubSpot API URL with the provided endpoint and configured API key
	 *
	 * @param string $endpoint the Hubspot API endpoint path
	 * @return string full API url for the endpoint, with API key
	 */
	protected function get_api_url( $endpoint ) {
		return "{$this->api_path}/{$endpoint}?hapikey={$this->api_key}";
	}

	/**
	 * Send a post request to the Hubspot API endpoint with a provided request body
	 *
	 * Generates the URL for the endpoint using @see get_api_url, and sends the request body encoded as json
	 * as required by the Hubspot API. Uses @see wp_remote_post to send the request.
	 *
	 * @param string $endpoint the Hubspot API endpoint path
	 * @param mixed $body content to encode and pass to the API, most likely an array
	 * @return array|WP_Error @link http://codex.wordpress.org/Function_Reference/wp_remote_post
	 */
	protected function send_post( $endpoint, $body ) {
		return wp_remote_post( $this->get_api_url( $endpoint ), array( 'body' => json_encode( $body ) ) );
	}

	/**
	 * Send a post request to the Hubspot Workkflows API to enroll a contact in the configured workflow
	 *
	 * Uses @see wp_remote_post to send the request. Uses @see $workflows
	 *
	 * @param string $email the email of the contact to enroll
	 * @param string $workflow the key of the workflow to trigger
	 * @return boolean true if the request succeeded
	 */
	public function send_workflow( $email, $workflow ) {
		if ( isset( $this->workflows[ $workflow ] ) && $email = sanitize_email( $email ) ) {
			$response = wp_remote_post( sprintf( $this->workflow_path, $this->workflows[ $workflow ], $email, $this->api_key ) );
			if ( $this->is_api_success( $response ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sends an opt-out request for a specified contact email address.
	 *
	 * @param string $email
	 * @return boolean true if the contact is successfully opted out
	 */
	public function send_opt_out( $email ) {
		if ( isset( $this->portal_id ) && isset( $this->subscription_id ) && $email = sanitize_email( $email ) ) {
			$response = wp_remote_post( sprintf( $this->opt_out_path, $email, $this->portal_id, $this->api_key ), array(
				'method' => 'PUT',
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => json_encode( array(
					'subscriptionStatuses' => array( array( 'id' => $this->subscription_id, 'subscribed' => false ) )
				) )
			) );
			if ( $this->is_api_success( $response ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Wrapper to identify error responses from the HubSpot API
	 *
	 * @param array $response response from wp http api request
	 * @return boolean true if the response indicates a successful request
	 */
	protected function is_api_success( $response ) {
		return ! is_wp_error( $response ) && preg_match( '/^2\d\d$/', $response['response']['code'] );
	}

	/**
	 * Encrypt a string using DES with the configured key. Also base 64 encodes the result.
	 *
	 * @param string $str string to encrypt
	 * @return string encrypted and base64 encoded string
	 */
	function encrypt( $str ) {
		if ( empty( $this->secret_key ) ) {
			throw new Exception( 'encrypt can not be used without setting Hubspot_Contacts->encryption_key during hubspot_contacts_initialize' );
		}

		$block = mcrypt_get_block_size( 'des', 'ecb' );
		$pad = $block - ( strlen( $str ) % $block );
		$str .= str_repeat( chr( $pad ), $pad );

		return base64_encode( mcrypt_encrypt( MCRYPT_DES, $this->secret_key, $str, MCRYPT_MODE_ECB ) );
	}

	/**
	 * Decrypt a string using DES with the configured key.
	 *
	 * @param string $str encrypted and base64 encoded string
	 * @return string decrypted string
	 */
	function decrypt( $str ) {
		if ( empty( $this->secret_key ) ) {
			throw new Exception( 'decrypt can not be used without setting Hubspot_Contacts->encryption_key during hubspot_contacts_initialize' );
		}

		$str = mcrypt_decrypt( MCRYPT_DES, $this->secret_key, base64_decode( $str ), MCRYPT_MODE_ECB );

		$block = mcrypt_get_block_size( 'des', 'ecb' );
		$pad = ord( $str[ ($len = strlen( $str )) - 1 ] );
		return substr( $str, 0, strlen( $str ) - $pad );
	}
}

function Hubspot_Contacts() {
	return Hubspot_Contacts::instance();
}

endif;