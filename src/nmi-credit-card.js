import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { usePaymentProcessing } from './payment-processing';
import './style.scss';
import {
	debug,
	getBlocksConfiguration,
	getCreditCardIcons,
	getFieldErrorMessage,
	getPublicKey,
	useCollectJS,
} from './utils';

/**
 * @description Credit Card Component for NMI payment gateway integration in woo commerece
 */
export const CreditCardComponent = ({
	billing,
	eventRegistration,
	emitResponse,
	components,
}) => {
	const cardIcons = getCreditCardIcons();
	const { onPaymentSetup, onCheckoutFail } = eventRegistration;
	const { PaymentMethodIcons, ValidationInputError, LoadingMask } =
		components;

	const [collectJSLoading, setCollectJSLoading] = useState(false);

	// These come from the server-side settings, which don't change after the page loads.
	const publicKey = getPublicKey();
	const paymentMethod = publicKey ? 'card' : undefined;

	//#region  On Submit
	// Pending token request for the current payment attempt, resolved by the Collect.js callback.
	const pendingTokenRef = useRef(null);
	const handleSubmission = useCallback((response) => {
		debug('Collect.js response', response);
		pendingTokenRef.current?.resolve(response);
		pendingTokenRef.current = null;
	}, []);
	// Collect.js gave up on the token request: end the attempt now, while it's still the current one.
	const handleTimeout = useCallback(() => {
		pendingTokenRef.current?.reject(new Error(getBlocksConfiguration()?.timeout_error));
		pendingTokenRef.current = null;
	}, []);

	//#endregion

	//#region Validator
	const [ccnumber, setccnumber] = useState('');
	const [ccexp, setccexp] = useState('');
	const [cccvv, setcccvv] = useState('');

	const handleValidationErrors = (response) => {
		const { status, message, field } = response;
		setCollectJSLoading(false);

		// Collect.js reports invalid fields here instead of calling back with a token,
		// so end the pending payment attempt now rather than waiting for the timeout.
		if (!status && pendingTokenRef.current) {
			pendingTokenRef.current.reject(new Error(getFieldErrorMessage(field, message)));
			pendingTokenRef.current = null;
		}

		if (field === 'ccnumber') {
			setccnumber(status ? '' : message);
		}
		if (field === 'ccexp') {
			setccexp(status ? '' : message);
		}
		if (field === 'cvv') {
			setcccvv(status ? '' : message);
		}
	};

	useEffect(() => {
		if (ccnumber) {
			onNMIError(ccnumber);
		} else if (cccvv) {
			onNMIError(cccvv)
		} else if (ccexp) {
			onNMIError(ccexp)
		} else {
			onNMIError('');
		}
	}, [ccnumber, cccvv, ccexp]);

	//#endregion

	//#region Errors

	useCollectJS(
		paymentMethod,
		setCollectJSLoading,
		handleValidationErrors,
		handleSubmission,
		handleTimeout
	);

	const onNMIError = usePaymentProcessing(
		billing,
		emitResponse,
		onPaymentSetup,
		onCheckoutFail,
		pendingTokenRef
	);
	//#endregion

	//#region Field Render

	const CollectJSElements = () => (
		<div className="wc-block-card-elements wc-block-nmi-gateway-container">
			<div className="wc-block-gateway-container wc-card-number-element">
				<div
					id="nmi-card-number-element"
					className="wc-block-gateway-input"
				>
					{/* a NMI Element will be inserted here. */}
				</div>
				<label htmlFor="nmi-card-number-element">
					{__('Card Number', 'wc-nmi')}
				</label>
				{ccnumber && <ValidationInputError errorMessage={ccnumber}/>}
			</div>
			<div className="wc-block-gateway-container wc-card-expiry-element">
				<div
					id="nmi-card-expiry-element"
					className="wc-block-gateway-input"
				>
					{/* a NMI Element will be inserted here. */}
				</div>
				<label htmlFor="nmi-card-expiry-element">
					{__('Expiry Date', 'wc-nmi')}
				</label>
				{ccexp && <ValidationInputError errorMessage={ccexp}/>}
			</div>
			<div className="wc-block-gateway-container wc-card-cvc-element">
				<div
					id="nmi-card-cvc-element"
					className="wc-block-gateway-input"
				>
					{/* a NMI Element will be inserted here. */}
				</div>
				<label htmlFor="nmi-card-cvc-element">
					{__('Card Code (CVC)', 'wc-nmi')}
				</label>
				{cccvv && <ValidationInputError errorMessage={cccvv}/>}
			</div>
		</div>
);

	//#endregion

	return (
		<LoadingMask
			isLoading={collectJSLoading}
			showSpinner='true'
			screenReaderLabel={__('Loading payment fields…', 'wc-nmi')}
		>
			{publicKey ? CollectJSElements() : 'public key not available yet'}

			{PaymentMethodIcons && cardIcons.length > 0 && (
				<PaymentMethodIcons icons={cardIcons} align="left" />
			)}
		</LoadingMask>
	);
};
