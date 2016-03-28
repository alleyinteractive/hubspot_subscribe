( function( $, HubspotContacts ) {
	$.extend( HubspotContacts, {
		formValidator: function() { return true; },
		showMessage: function( msg, className ) {
			var $box = $( '.hubspot-contacts .messages' );

			$box.hide().find('.message').remove();
			$box.append( $( '<div>' ).addClass( 'message' ).addClass( className ).html( msg ) ).fadeIn();

		    $( window ).scrollTop( $box.offset().top );
		},
		showAlert: function( msg, className ) {
			var obj = this;

			if ( !this.$msgModal ) {
				var $wrapper = $( '<div>' ).css( { display: 'none', width: '100%', position: 'fixed', top: 200, textAlign: 'center' } );
				this.$msgModal = $( '<span>' ).css( { background: '#d0d0d0', fontSize: '2em', color: 'black', padding: '15px 20px', border: '1px solid #a0a0a0', display: 'inline-block', maxWidth: '90%' } );
				$(document.body).append( $wrapper.append( this.$msgModal ) );
			}

			this.$msgModal.text( msg ).attr( 'class', className ).parent().fadeIn();

			setTimeout( function() {
				obj.$msgModal.parent().fadeOut( 'slow' );
			}, 5000 );
		},
		initCheckAll: function( checkbox, selector ) {
			var $checkbox = $( checkbox );
			var $targets = $checkbox.parents( 'form' ).find( selector );

			$checkbox.on( 'change', function() {
				$targets.prop( 'checked', $checkbox.prop('checked') );
				$targets.trigger( 'change' );
			} );

			$targets.on( 'change', function() {
				$checkbox.prop( 'checked', ($targets.size() === $targets.filter( ':checked' ).size()) );
			} );

			$targets.trigger( 'change' );
		},
		handleAjaxRequest: function( $form, params ) {
			var obj = this;

			params.push( { name: obj.ajax_security_param, value: obj.ajax_security_value } );

			$.ajax( {
				url: this.ajaxurl,
				data: params,
				xhrFields: { withCredentials: true },
				type: 'post',
				dataType: 'json',
				success: function( data ) {
					if ( data.status === obj.STATUS_SUCCESS ) {
						$( '.hubspot-contacts form' ).fadeOut();
						obj.showMessage( data.message, data.status );
					} else if ( data.status === obj.STATUS_SIGNUP ) {
						obj.showAlert( data.message, data.status );
					} else if ( data.status === obj.STATUS_SETTINGS ) {
						var $settings = $( 'form[data-hubspot-ajax-action="hubspot_contacts_update"]' );

						for ( i in data.contact_data ) {
							var $property = $settings.find( '[name="' + HubspotContacts.form_container + '[' + i + ']"]' );

							if ( $property.is( '[type=checkbox]' ) ) {
								$property.prop( 'checked', data.contact_data[i] === 'true' );
								$property.trigger( 'change' );
							} else {
								$property.val( data.contact_data[i] )
							}
						}

						$form.fadeOut();
						$settings.fadeIn();

						if ( parseInt( data.contact_data['vid'] ) > 0 ) {
							var $opt_out = $( 'form[data-hubspot-ajax-action="hubspot_contacts_opt_out"]' );
							$opt_out.find( '[name="' + HubspotContacts.form_container + '[email]"]' ).val( data.contact_data['email'] );
							$opt_out.fadeIn();
						}
					}
				},
				error: function( jqXHR ) {
					if ( jqXHR.responseText ) {
						obj.showAlert( jqXHR.responseText );
					} else {
						// unknown error, may be lack of CORS so try http post
						$form.trigger( 'submit', true );
					}
				}
			} );
		},
		initForm: function( form ) {
			var obj = this;
			var $form = $( form );

			$form.on( 'submit', function( e, disableAjax ) {
				if ( !obj.formValidator( $form ) ) {
					return false;
				}

				if ( ! disableAjax && $form.is( '[data-hubspot-ajax-action]' ) ) {
					e.preventDefault();

					var params = $form.serializeArray();
					params.push( {name: 'action', value: $form.data('hubspot-ajax-action')} );

					obj.handleAjaxRequest( $form, params );
				}
			} );
		},
		init: function() {
			var obj = this;

			$( '[data-hubspot-check-all]' ).each( function() {
				obj.initCheckAll( this, $( this ).data( 'hubspot-check-all' ) + ':not([data-hubspot-check-all])' );
			} );

			$( '.hubspot-contacts form' ).each( function() {
				obj.initForm( this );
			} );
		}
	} );
	$( function() {
		HubspotContacts.init();
	} );
} )( jQuery, HubspotContacts );