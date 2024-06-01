import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { usePaymentProcessing } from './payment-processing';
import './style.scss';
import {
	TEXT_DOMAIN,
	PAYMENT_TIMEOUT_DURATION,
	PAYMENT_UI_FIELDS,
	getBlocksConfiguration,
	getCreditCardIcons,
	getPublicKey,
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

	const [isWindowAvaialble, setIsWindowAvailable] = useState(false);
	const [collectJSLoading, setCollectJSLoading] = useState(false);

	const [publicKey, setPublicKey] = useState();

	useEffect(() => {
		if (typeof window != undefined) {
			setIsWindowAvailable(true);
		}

		if (!publicKey) {
			const k = getPublicKey();
			setPublicKey(k ? k : null);
		}
	});

	const configureCollectJs = () => {
		try {
			if (window.CollectJS !== undefined) {
				setCollectJSLoading(true);
				window.CollectJS.configure({
					variant: 'inline',
					customCss: {
						height: '20px',
						'line-height': '20px',
						padding: '0px',
						'pointer-events': 'auto',
					},
					styleSniffer: 'true',
					fields: { ...PAYMENT_UI_FIELDS },
					validationCallback: function (field, status, message) {
						setCollectJSLoading(false);
						handleValidationErrors({
							field,
							status,
							message,
						});
					},
					timeoutDuration: PAYMENT_TIMEOUT_DURATION,
					timeoutCallback: function () {},
					fieldsAvailableCallback: function () {
						setCollectJSLoading(false);
						window.collectResponse = '';
						window.effectTriggerTimes = 0;
					},
					callback: handleSubmission,
				});
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
			var field = 'ccnumber',
				status = false,
				message = error.message;
			handleValidationErrors({
				field,
				status,
				message,
			});
		}
	};

	useEffect(() => {
		if (publicKey && publicKey.length > 0 && isWindowAvaialble) {
			configureCollectJs();
		}
	}, [publicKey]);

	//#region  On Submit
	const [collectResponse, setCollectResponse] = useState('');
	const handleSubmission = (response) => {
		window.collectResponse = response;
		setCollectResponse(response.token);
	};

	//#endregion

	//#region Errors

	const onNMIError = usePaymentProcessing(
		billing,
		emitResponse,
		onPaymentSetup,
		onCheckoutFail,
		collectResponse
	);
	//#endregion

	//#region Validator
	const [ccnumber, setccnumber] = useState('');
	const [ccexp, setccexp] = useState('');
	const [cccvv, setcccvv] = useState('');

	const handleValidationErrors = (response) => {
		const { status, message, field } = response;
		setCollectJSLoading(false);

		if (field === 'ccnumber') {
			setccnumber(status ? '' : message);
		}
		if (field === 'ccexp') {
			setccexp(status ? '' : message);
		}
		if (field === 'cvv') {
			setcccvv(status ? '' : message);
		}

		if (status === false) {
			onNMIError(message);
		} else {
			if (ccnumber === '' && cccvv === '' && ccexp === '') {
				onNMIError('');
			}
		}
	};

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
					{__('Card Number', TEXT_DOMAIN)}
				</label>
				{ccnumber && <ValidationInputError errorMessage={ccnumber} />}
			</div>
			<div className="wc-block-gateway-container wc-card-expiry-element">
				<div
					id="nmi-card-expiry-element"
					className="wc-block-gateway-input"
				>
					{/* a NMI Element will be inserted here. */}
				</div>
				<label htmlFor="nmi-card-expiry-element">
					{__('Expiry Date', TEXT_DOMAIN)}
				</label>
				{ccexp && <ValidationInputError errorMessage={ccexp} />}
			</div>
			<div className="wc-block-gateway-container wc-card-cvc-element">
				<div
					id="nmi-card-cvc-element"
					className="wc-block-gateway-input"
				>
					{/* a NMI Element will be inserted here. */}
				</div>
				<label htmlFor="nmi-card-cvc-element">
					{__('Card Code (CVC)', TEXT_DOMAIN)}
				</label>
				{cccvv && <ValidationInputError errorMessage={cccvv} />}
			</div>
		</div>
	);

	//#endregion

	return (
		<LoadingMask
			isLoading={collectJSLoading}
			screenReaderLabel={__('Loading payment fields…', TEXT_DOMAIN)}
		>
			{publicKey ? CollectJSElements() : 'public key not available yet'}

			{PaymentMethodIcons && cardIcons.length && (
				<PaymentMethodIcons icons={cardIcons} align="left" />
			)}
		</LoadingMask>
	);
};
