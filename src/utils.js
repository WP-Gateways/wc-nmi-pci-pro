import { getSetting } from '@woocommerce/settings';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export const PAYMENT_METHOD_NAME = 'nmi';
export const PAYMENT_TIMEOUT_DURATION = 20000;
// Backstop for a token request: Collect.js's own timeout (timeoutCallback) normally ends the attempt first, so a
// late callback can't reach the next attempt.
export const TOKEN_REQUEST_TIMEOUT = PAYMENT_TIMEOUT_DURATION + 5000;

/**
 * @description Log to the browser console, only when Gateway Debug is enabled in the NMI settings.
 * Responses can include card details, so they're never logged otherwise.
 */
export const debug = (...args) => {
	if (getSetting(`${PAYMENT_METHOD_NAME}_data`, null)?.debug) {
		console.log('[NMI]', ...args);
	}
};

/**
 * @description Create a promise that can be settled from outside, and rejects after a timeout.
 * Used to hand the Collect.js callback result to the payment setup handler.
 * @param {number} timeoutMs
 * @param {string} timeoutMessage
 * @returns {{promise: Promise, resolve: Function, reject: Function}}
 */
export const createDeferred = (timeoutMs, timeoutMessage) => {
	let resolve, reject;
	const promise = new Promise((res, rej) => {
		resolve = res;
		reject = rej;
	});
	const timer = setTimeout(() => reject(new Error(timeoutMessage)), timeoutMs);
	return {
		promise: promise.finally(() => clearTimeout(timer)),
		resolve,
		reject,
	};
};

/**
 * @description NMI Settings
 * @returns
 */
export const getBlocksConfiguration = () => {
	const nmiServerData = getSetting(`${PAYMENT_METHOD_NAME}_data`, null);

	if (!nmiServerData) {
		throw new Error(
			`${PAYMENT_METHOD_NAME} initialization data is not available`
		);
	}

	return nmiServerData;
};

/**
 * ui elements
 * field_title attribute added to reference in other parts of the code
 */
export const PAYMENT_UI_FIELDS = {
	ccnumber: {
		selector: `#${PAYMENT_METHOD_NAME}-card-number-element`,
		placeholder: '•••• •••• •••• ••••',
	},
	ccexp: {
		selector: `#${PAYMENT_METHOD_NAME}-card-expiry-element`,
		placeholder: getBlocksConfiguration()?.placeholder_expiry,
	},
	cvv: {
		display: 'show',
		selector: `#${PAYMENT_METHOD_NAME}-card-cvc-element`,
		placeholder: getBlocksConfiguration()?.placeholder_cvc,
	},
};

// Gateway messages for each Collect.js field, from the card gateway's JS params.
const FIELD_ERROR_KEYS = {
	ccnumber: 'card_number_error',
	ccexp: 'card_expiry_error',
	cvv: 'card_cvc_error',
};

/**
 * @description Build the checkout error for an invalid Collect.js field, e.g. "Invalid card number. (Ref: Field is empty)".
 * @param {string} field   Collect.js field name.
 * @param {string} message Collect.js validation message.
 * @returns {string}
 */
export const getFieldErrorMessage = (field, message) => {
	const config = getBlocksConfiguration();
	const fieldError = config?.[FIELD_ERROR_KEYS[field]];

	if (!fieldError) {
		return message;
	}
	return message && config?.error_ref
		? fieldError + ' ' + config.error_ref.replace('[ref]', message)
		: fieldError;
};

export const useCollectJS = (
	paymentMethod,
	setCollectJSLoading,
	handleValidationErrors,
	handleSubmission,
	handleTimeout
) => {
	useEffect(() => {
		try {
			if (window.CollectJS !== undefined) {
				if (paymentMethod) {
					// Show the loading mask until Collect.js has mounted the fields.
					setCollectJSLoading(true);
					// Fallback in case fieldsAvailableCallback never fires.
					setTimeout(function () {
						setCollectJSLoading(false);
					}, 10000);
					window.CollectJS.configure({
						variant: 'inline',
						customCss: {
							height: '20px',
							'line-height': '20px',
							padding: '0px',
							'border-width': '0px',
							'pointer-events': 'auto',
						},
						styleSniffer: 'true',
						fields: PAYMENT_UI_FIELDS,
						validationCallback: function (field, status, message) {
							setCollectJSLoading(false);
							handleValidationErrors({
								field,
								status,
								message,
							});
						},
						timeoutDuration: PAYMENT_TIMEOUT_DURATION,
						timeoutCallback: function () {
							handleTimeout?.();
						},
						fieldsAvailableCallback: function () {
							setCollectJSLoading(false);
						},
						callback: handleSubmission,
					});
				}
			} else {
				throw new Error(getBlocksConfiguration()?.collect_js_error);
			}
		} catch (error) {
			document
				.querySelectorAll(
					'.wc-block-nmi-gateway-container .wc-block-gateway-input, .wc-block-nmi-gateway-container label'
				)
				.forEach((el) => {
					el.style.display = 'none';
				});
			handleValidationErrors({
				field: 'ccnumber',
				status: false,
				message: error.message,
			});
		}
	}, [paymentMethod]);
};

/**
 * @description Label for Payment Methods
 * @returns
 */
export const Label = (props) => {
	const { PaymentMethodLabel } = props.components;

	const labelText =
		getBlocksConfiguration()?.title ??
		__('Credit / Debit Card', 'wc-nmi');

	return <PaymentMethodLabel text={labelText} />;
};

export const getPublicKey = () => {
	return getBlocksConfiguration()?.public_key;
};

/**
 * @description Payment options icons
 * @returns
 */
export const getCreditCardIcons = () => {
	return Object.entries(getBlocksConfiguration()?.icons ?? {}).map(
		([id, { src, alt }]) => {
			return {
				id,
				src,
				alt,
			};
		}
	);
};

//#endregion
