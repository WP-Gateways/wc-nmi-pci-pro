import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { __ } from '@wordpress/i18n';
import { CreditCardComponent } from './nmi-credit-card';
import './style.scss';
import {
	Label,
	TEXT_DOMAIN,
	PAYMENT_METHOD_NAME,
	getBlocksConfiguration,
} from './utils';

const NMICreditCard = (props) => {
	return <CreditCardComponent {...props} />;
};

const nmiCCPaymentMethod = {
	name: PAYMENT_METHOD_NAME,
	label: <Label />,
	content: <NMICreditCard />,
	edit: <NMICreditCard />,
	canMakePayment: () => true,
	ariaLabel: __('NMI payment method', TEXT_DOMAIN),
	supports: {
		showSavedCards: getBlocksConfiguration()?.showSavedCards ?? false,
		showSaveOption: getBlocksConfiguration()?.showSaveOption ?? false,
		features: getBlocksConfiguration()?.supports ?? [],
	},
};

registerPaymentMethod(nmiCCPaymentMethod);
