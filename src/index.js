import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { __ } from '@wordpress/i18n';
import { CreditCardComponent } from './nmi-credit-card';
import { CreditCardPreview } from './previews';
import './style.scss';
import {
	Label,
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
	edit: <CreditCardPreview />,
	canMakePayment: () => true,
	ariaLabel: __('NMI payment method', 'wc-nmi'),
	supports: {
		showSavedCards: getBlocksConfiguration()?.showSavedCards ?? false,
		showSaveOption: getBlocksConfiguration()?.showSaveOption ?? false,
		features: getBlocksConfiguration()?.supports ?? [],
	},
};

registerPaymentMethod(nmiCCPaymentMethod);
