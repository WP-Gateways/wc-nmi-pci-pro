import { getSetting } from '@woocommerce/settings';

export const PAYMENT_METHOD_NAME = 'nmi';
export const TEXT_DOMAIN = 'wc-nmi';
export const PAYMENT_TIMEOUT_DURATION = 20000;

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

/**
 * @description Label for Payment Methods
 * @returns
 */
export const Label = (props) => {
	const { PaymentMethodLabel } = props.components;

	const labelText =
		getBlocksConfiguration()?.title ??
		__('Credit / Debit Card', TEXT_DOMAIN);

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
