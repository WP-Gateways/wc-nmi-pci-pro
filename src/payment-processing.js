import { useCallback, useEffect, useState } from '@wordpress/element';
import {
	createDeferred,
	getBlocksConfiguration,
	getResponseCard,
	TOKEN_REQUEST_TIMEOUT,
} from './utils';

/**
 *  @description Custom hook to manage the payment proccessing for NMI gateway.
 */
export const usePaymentProcessing = (
	billing,
	emitResponse,
	onPaymentSetup,
	onCheckoutFail,
	pendingTokenRef
) => {
	const [error, setError] = useState('');

	const onNMIError = useCallback((message) => {
		setError(message);
		return message ? message : false;
	}, []);

	// hook into and register callbacks for events
	useEffect(() => {
		const onSubmit = async () => {
			let deferred;
			try {
				// if there's an error return that.
				if (error) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: error,
					};
				}

				// Each attempt waits on its own promise, so a late token from an earlier attempt is ignored.
				deferred = createDeferred(
					TOKEN_REQUEST_TIMEOUT,
					getBlocksConfiguration()?.timeout_error
				);
				pendingTokenRef.current = deferred;
				try {
					window.CollectJS.startPaymentRequest();
				} catch (startError) {
					// Settle the attempt so its timer can't reject unhandled later.
					deferred.promise.catch(() => {});
					deferred.reject(startError);
					throw startError;
				}

				const response = await deferred.promise;

				if (!response?.token) {
					throw new Error(getBlocksConfiguration()?.timeout_error);
				}

				// Without a card type the server checks the card NMI charged.
				const card = getResponseCard(response);
				if (
					card &&
					getBlocksConfiguration()?.allowed_card_types.indexOf(
						card.type.replace('diners', 'diners-club')
					) === -1
				) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: getBlocksConfiguration()?.card_disallowed_error,
					};
				}

				const billingAddress = billing.billingAddress;

				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							nmi_js_response: JSON.stringify(response),
						},
						billingAddress,
					},
				};
			} catch (e) {
				return {
					type: emitResponse.responseTypes.ERROR,
					message: e?.message || String(e),
				};
			} finally {
				// Only clear our own request, not one a newer attempt has already registered.
				if (pendingTokenRef.current === deferred) {
					pendingTokenRef.current = null;
				}
			}
		};

		const unsubscribeProcessing = onPaymentSetup(onSubmit);
		return () => {
			unsubscribeProcessing();
		};
	}, [
		onPaymentSetup,
		billing,
		error,
		pendingTokenRef,
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
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
