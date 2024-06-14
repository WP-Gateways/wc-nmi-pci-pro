import { useCallback, useEffect, useState } from '@wordpress/element';
import { getBlocksConfiguration, PAYMENT_METHOD_NAME, PAYMENT_TIMEOUT_DURATION } from './utils';

/**
 *  @description Custom hook to manage the payment proccessing for NMI gateway.
 */
export const usePaymentProcessing = (
	billing,
	emitResponse,
	onPaymentSetup,
	onCheckoutFail,
	collectResponse
) => {
	const [error, setError] = useState('');

	const onNMIError = useCallback((message) => {
		console.log('onNMIError :', message);
		setError(message);
		return message ? message : false;
	}, []);

	// hook into and register callbacks for events
	useEffect(() => {
		const waitForCollectJsToken = async () => {
			const tokenCheckInterval = 2000;
			while (
				!window.collectResponse ||
				window.collectResponse.length < 5
			) {
				window.effectTriggerTimes = window.effectTriggerTimes + 1;
				if (window.effectTriggerTimes > (PAYMENT_TIMEOUT_DURATION/tokenCheckInterval)) {
					throw new Error(getBlocksConfiguration()?.timeout_error);
				}
				await new Promise((res) => setTimeout(res, tokenCheckInterval));
			}
		};

		const onSubmit = async () => {
			try {
				// if there's an error return that.
				if (error) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: error,
					};
				}
				window.CollectJS.startPaymentRequest();
				await waitForCollectJsToken().catch((err) => {
					console.error('waitForCollectJsToken error: ', err);
				});

				if (
					window.collectResponse &&
					window.collectResponse.card.type != null
				) {
					if (
						getBlocksConfiguration()?.allowed_card_types.indexOf(
							window.collectResponse.card.type.replace(
								'diners',
								'diners-club'
							)
						) === -1
					) {
						return {
							type: emitResponse.responseTypes.ERROR,
							message:
								getBlocksConfiguration()?.card_disallowed_error,
						};
					}
				} else {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: getBlocksConfiguration()?.timeout_error,
					};
				}
				const billingAddress = billing.billingAddress;

				const ownerInfo = {
					address: {
						line1: billingAddress.address_1,
						line2: billingAddress.address_2,
						city: billingAddress.city,
						state: billingAddress.state,
						postal_code: billingAddress.postcode,
						country: billingAddress.country,
					},
				};
				if (billingAddress.phone) {
					ownerInfo.phone = billingAddress.phone;
				}
				if (billingAddress.email) {
					ownerInfo.email = billingAddress.email;
				}
				if (billingAddress.first_name || billingAddress.last_name) {
					ownerInfo.name = `${billingAddress.first_name} ${billingAddress.last_name}`;
				}

				const nmiArgs = {
					nmi_token: window.collectResponse?.token,
					nmi_js_response: JSON.stringify(window.collectResponse),
				};

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							...nmiArgs,
							billing_email: ownerInfo.email,
							billing_first_name:
								billingAddress?.first_name ?? '',
							billing_last_name: billingAddress?.last_name ?? '',
							paymentMethod: PAYMENT_METHOD_NAME,
							paymentRequestType: 'cc',
						},
						billingAddress,
					},
				};
			} catch (e) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: e,
				};
			}
		};

		const unsubscribeProcessing = onPaymentSetup(onSubmit);
		return () => {
			unsubscribeProcessing();
		};
	}, [
		onPaymentSetup,
		billing.billingAddress,
		onNMIError,
		error,
		emitResponse.noticeContexts.PAYMENTS,
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		collectResponse,
	]);

	// hook into and register callbacks for events.
	useEffect(() => {
		const onError = ({ processingResponse }) => {
			if (processingResponse?.paymentDetails?.errorMessage) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: processingResponse.paymentDetails.errorMessage,
					messageContext: emitResponse.noticeContexts.PAYMENTS,
				};
			}
			// so we don't break the observers.
			return true;
		};
		const unsubscribeAfterProcessing = onCheckoutFail(onError);
		return () => {
			unsubscribeAfterProcessing();
		};
	}, [
		onCheckoutFail,
		emitResponse.noticeContexts.PAYMENTS,
		emitResponse.responseTypes.ERROR,
	]);
	return onNMIError;
};
