import { __ } from '@wordpress/i18n';

/**
 * @description Static field used in the editor previews.
 */
const PreviewField = ({ className, label, placeholder }) => (
	<div className={`wc-block-gateway-container ${className}`}>
		<div className="wc-block-gateway-input wc-block-nmi-preview-input">
			{placeholder}
		</div>
		<label>{label}</label>
	</div>
);

/**
 * @description Editor preview of the card fields. Collect.js isn't loaded in the editor,
 * so this shows the layout without mounting the real payment fields.
 */
export const CreditCardPreview = () => (
	<div className="wc-block-card-elements wc-block-nmi-gateway-container">
		<PreviewField
			className="wc-card-number-element"
			label={__('Card Number', 'wc-nmi')}
			placeholder="•••• •••• •••• ••••"
		/>
		<PreviewField
			className="wc-card-expiry-element"
			label={__('Expiry Date', 'wc-nmi')}
			placeholder={__('MM / YY', 'wc-nmi')}
		/>
		<PreviewField
			className="wc-card-cvc-element"
			label={__('Card Code (CVC)', 'wc-nmi')}
			placeholder={__('CVC', 'wc-nmi')}
		/>
	</div>
);
