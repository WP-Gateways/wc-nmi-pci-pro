jQuery( function( $ ) {
	'use strict';

    let nmi_error = {},
        card_allowed;

	/**
	 * Log to the browser console, only when Gateway Debug is enabled (wc_nmi_params.debug).
	 * Responses can include card details, so they're never logged otherwise.
	 */
	const debug = function() {
		if ( wc_nmi_params.debug ) {
			console.log( '[NMI]', ...arguments );
		}
	};

	/**
	 * Object to handle NMI payment forms.
	 */
	let wc_nmi_form = {

        /**
		 * Creates all NMI elements that will be used to enter cards or IBANs.
		 */
		createElements: function() {

			const customCss = ! ( $('#cfw-payment-method').length || $('.woolentor-step--payment').length || $('.avada-checkout').length || $('.ro-checkout-process').length || $('button.wfacp_next_page_button').length ) ? { "pointer-events": "auto"} : {
				"height": "30px"
            }

			this.form.find( '.nmi-source-errors' ).html( '' );

			if (window.CollectJS !== undefined) {

				// If Collect.js never mounts the fields, don't leave the payment section blocked without explanation.
				clearTimeout( wc_nmi_form.fields_timeout );
				wc_nmi_form.fields_timeout = setTimeout( function() {
					$( document ).trigger( 'nmiError', wc_nmi_params.collect_js_error );
				}, 15000 );

				try {
					CollectJS.configure({
						//"paymentSelector" : "#place_order",
						"variant": "inline",
						"styleSniffer": "true",
						"customCss": customCss,
						//"googleFont": "Montserrat:400",
						"fields": {
							"ccnumber": {
								"selector": "#nmi-card-number-element",
								"placeholder": "•••• •••• •••• ••••"
							},
							"ccexp": {
								"selector": "#nmi-card-expiry-element",
								"placeholder": wc_nmi_params.placeholder_expiry
							},
							"cvv": {
								"display": "show",
								"selector": "#nmi-card-cvc-element",
								"placeholder": wc_nmi_params.placeholder_cvc
							}
						},
						'validationCallback': function (field, status, message) {
							nmi_error[field] = status ? '' : message;
							debug( field + ( status ? ' is OK: ' : ' is invalid: ' ) + message );
						},
						"timeoutDuration": 20000,
						"timeoutCallback": function () {
							$(document).trigger('nmiError', wc_nmi_params.timeout_error);
						},
						"fieldsAvailableCallback": function () {
							clearTimeout( wc_nmi_form.fields_timeout );
							wc_nmi_form.mounting = false;
							wc_nmi_form.mounted = true;
							wc_nmi_form.unblock();
							wc_nmi_form.unblockPayment();
							debug( 'Collect.js loaded the fields onto the form' );
						},
						'callback': function (response) {
							wc_nmi_form.onNMIResponse(response);
						}
					});
				} catch ( error ) {
					debug( 'Collect.js configure error', error );
					$(document).trigger('nmiError', wc_nmi_params.collect_js_error);
				}
			} else {
				$(document).trigger('nmiError', wc_nmi_params.collect_js_error);
				$('#wc-nmi-cc-form label, #wc-nmi-cc-form .wc-nmi-elements-field').hide();
			}

        },

		/**
		 * Initialize event handlers and UI state.
		 */
		init: function() {
			// checkout page
			if ( $( 'form.woocommerce-checkout' ).length ) {
				this.form = $( 'form.woocommerce-checkout' );
			}

			$( 'form.woocommerce-checkout' )
				.on(
					'checkout_place_order_nmi',
					this.onSubmit
				);

			// pay order page
			if ( $( 'form#order_review' ).length ) {
				this.form = $( 'form#order_review' );
			}

			$( 'form#order_review' )
				.on(
					'submit',
					this.onSubmit
				);

			$( document )
				.on(
					'change',
					'#wc-nmi-cc-form :input',
					this.onCCFormChange
				)
				.on(
					'nmiError',
					this.onError
				)
				.on(
					'checkout_error',
					this.clearToken
				);

			// Theme high multi step checkout
			$(document.body)
				.on('step_jumped', function() {
					if ( $('.wc_payment_methods').height() > 0 ) {
						wc_nmi_form.mountFields();
					}
				});

			wc_nmi_form.mountFields();

		    // CheckoutWC and woolentor, La Forat theme, Avada multi step checkout
			$( 'body' ).on( 'click', '.custom_checkout_page a[href="#next"], a[href="#cfw-payment-method"], a[data-tab="#cfw-payment-method"], a[data-step="step--payment"], a.ro-tab-2, a.ro-btn-2, button.wfacp_next_page_button, .woocommerce-checkout-nav a,.continue-checkout', function() {
				wc_nmi_form.mountFields();
			} );

            /**
			 * Only in checkout page we need to delay the mounting of the
			 * card as some AJAX process needs to happen before we do.
			 */
			if ( 'yes' === wc_nmi_params.is_checkout ) {
				$( document.body ).on( 'updated_checkout', function() {
					// The payment section is re-rendered, so always re-mount.
					wc_nmi_form.mountFields( true );
				} );
			}

			$( document.body ).on( 'payment_method_selected', function() {
				wc_nmi_form.mountFields();
			} );

			// Not every page fires payment_method_selected (e.g. the pay page), so also react to the
			// payment method radios directly.
			$( document ).on( 'change', 'input[name="payment_method"]', function() {
				wc_nmi_form.mountFields();
			} );

			wc_nmi_form.watchForPaymentFields();
		},

		/**
		 * Whether the Collect.js fields are already mounted, or being mounted.
		 *
		 * @return {boolean}
		 */
		isMounted: function() {
			return wc_nmi_form.mounting || ( wc_nmi_form.mounted && 0 < $( '#nmi-card-number-element' ).children().length );
		},

		/**
		 * Mount the card fields if NMI is selected, unless they're already mounted.
		 * Every page and event that can show the NMI fields goes through here.
		 *
		 * @param {boolean} force Re-mount even if already mounted, e.g. after the checkout is re-rendered.
		 */
		mountFields: function( force ) {
			if ( ! wc_nmi_form.isNMIChosen() || ( ! force && wc_nmi_form.isMounted() ) ) {
				return;
			}

			wc_nmi_form.mounting = true;
			wc_nmi_form.mounted = false;
			wc_nmi_form.mountTarget = $( '#nmi-card-number-element' )[ 0 ];
			wc_nmi_form.blockPayment();
			wc_nmi_form.createElements();
		},

		/**
		 * Safety net for themes and checkout plugins that render the payment section late:
		 * mount once the NMI field containers appear, then stop watching.
		 * It only reacts to field containers it hasn't tried to mount yet, so a failed mount (which only
		 * changes the error markup) doesn't trigger another attempt.
		 */
		watchForPaymentFields: function() {
			if ( ! window.MutationObserver || ! document.body ) {
				return;
			}

			let scheduled = false;
			const observer = new MutationObserver( function() {
				if ( scheduled ) {
					return;
				}
				scheduled = true;
				window.requestAnimationFrame( function() {
					scheduled = false;
					if ( wc_nmi_form.isNMIChosen() && wc_nmi_form.mounted && ! wc_nmi_form.mounting && wc_nmi_form.isMounted() ) {
						observer.disconnect();
						return;
					}
					if ( $( '#nmi-card-number-element' )[ 0 ] === wc_nmi_form.mountTarget ) {
						return;
					}
					wc_nmi_form.mountFields();
				} );
			} );

			observer.observe( document.body, { childList: true, subtree: true } );

			// Don't keep watching the page indefinitely.
			setTimeout( function() {
				observer.disconnect();
			}, 30000 );
		},

		/**
		 * Whether NMI is selected and its card fields are on the page.
		 *
		 * @return {boolean}
		 */
		isNMIChosen: function() {
			return 0 < $( '#nmi-card-number-element' ).length && $( '#payment_method_nmi' ).is( ':checked' );
		},

		hasToken: function() {
			return 0 < $( 'input.nmi_js_response' ).length;
		},

		block: function() {
			wc_nmi_form.form.block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},

		unblock: function() {
			wc_nmi_form.form.unblock();
		},

		blockPayment: function() {
			wc_nmi_form.form.find('#payment, .wfacp_payment').block( {
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			} );
		},

		unblockPayment: function() {
			wc_nmi_form.form.find('#payment, .wfacp_payment').unblock();
		},

        getSelectedPaymentElement: function() {
			return $( '.payment_methods input[name="payment_method"]:checked' );
		},

		onError: function( e, result ) {
			clearTimeout( wc_nmi_form.fields_timeout );
			wc_nmi_form.mounting = false;
			wc_nmi_form.onCCFormChange();
			$( '.woocommerce-NoticeGroup-checkout' ).remove();
			debug( 'Error', result );

			// Display the error next to the CC fields.
			const errorContainer = $( '#wc-nmi-cc-form .nmi-source-errors' );
			errorContainer.html( $( '<ul />' ).addClass( 'woocommerce_error woocommerce-error wc-nmi-error' ).append( $( '<li />' ).text( result ) ) ); // text() prevents XSS

			if ( $( '.wc-nmi-error' ).length ) {
				$( 'html, body' ).animate({
					scrollTop: ( $( '.wc-nmi-error' ).offset().top - 200 )
				}, 200 );
			}
			wc_nmi_form.unblock();
			wc_nmi_form.unblockPayment();
		},

		onSubmit: function( e ) {
			if ( wc_nmi_form.isNMIChosen() && ! wc_nmi_form.hasToken() ) {
				e.preventDefault();

				// Collect.js didn't load, so the card details can't be tokenized.
				if ( window.CollectJS === undefined ) {
					$( document.body ).trigger( 'nmiError', wc_nmi_params.collect_js_error );
					return false;
				}

				wc_nmi_form.block();
				debug( nmi_error );

				// Show the first invalid field's error, with Collect.js's reason when there is one.
				const invalid = [
					[ '#nmi-card-number-element', 'card_number_error', 'ccnumber' ],
					[ '#nmi-card-expiry-element', 'card_expiry_error', 'ccexp' ],
					[ '#nmi-card-cvc-element', 'card_cvc_error', 'cvv' ]
				].find( function( field ) {
					return null === document.querySelector( field[0] + ' .CollectJSValid' );
				} );

				if ( invalid ) {
					const reason = nmi_error[ invalid[2] ];
					$( document.body ).trigger( 'nmiError', wc_nmi_params[ invalid[1] ] + ( reason ? ' ' + wc_nmi_params.error_ref.replace( '[ref]', reason ) : '' ) );
					return false;
				}

				try {
					CollectJS.startPaymentRequest();
				} catch ( error ) {
					debug( 'Collect.js error', error );
					$( document.body ).trigger( 'nmiError', wc_nmi_params.collect_js_error );
				}

				// Prevent form submitting
				return false;
			}
		},

		onCCFormChange: function() {
			$( '.wc-nmi-error, .nmi_js_response' ).remove();
		},

		onNMIResponse: function( response ) {
			debug( 'Collect.js response', response );

			if( response.card && response.card.type != null ) {
				// Reset for each token, so an earlier allowed card doesn't let a disallowed one through.
				card_allowed = false;
				wc_nmi_params.allowed_card_types.forEach(function (card_type) {
					if( response.card.type == card_type.replace( 'diners-club', 'diners' ) ) {
						card_allowed = true;
					}
				});

				if( ! card_allowed ) {
					$( document.body ).trigger( 'nmiError', wc_nmi_params.card_disallowed_error );
					return false;
				}
			}

			$('<input>').attr({
				type: 'hidden',
				class: 'nmi_js_response',
				name: 'nmi_js_response',
				value: JSON.stringify(response)
			}).appendTo(wc_nmi_form.form);
			wc_nmi_form.form.submit();
		},

		clearToken: function() {
			$( '.nmi_js_response' ).remove();
		}
	};

	wc_nmi_form.init();
} );
