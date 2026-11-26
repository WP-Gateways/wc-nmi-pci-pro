import { render, screen } from '@testing-library/react';
import { setMockSetting } from '@woocommerce/settings';
import { registeredPaymentMethods } from '@woocommerce/blocks-registry';


/**
 * The card block shows the gateway description above the card fields, as classic checkout does, so the
 * sandbox mode notice and the test card instructions show on the block checkout too.
 */
describe( 'card block description', () => {
	const name = 'nmi';

	const settings = ( description ) => {
		setMockSetting( 'nmi_data', { title: 'Card', supports: [ 'products' ], description } );
	};

	beforeAll( () => {
		settings( '' );
		// Each test file has its own module registry, so the script registers on first require.
		require( '../../src/index' );
	} );

	const renderBlock = () => {
		const Content = registeredPaymentMethods.find( ( m ) => m.name === name ).content.type;

		return render(
			<Content
				billing={ { billingAddress: {}, cartTotal: { value: 1000 }, currency: { code: 'USD', minorUnit: 2 } } }
				emitResponse={ { responseTypes: { SUCCESS: 'success', ERROR: 'error' }, noticeContexts: { PAYMENTS: 'wc/checkout/payments' } } }
				eventRegistration={ { onPaymentSetup: () => () => {}, onCheckoutFail: () => () => {} } }
				components={ {
					LoadingMask: ( { children } ) => <div>{ children }</div>,
					PaymentMethodIcons: () => null,
					ValidationInputError: () => null,
				} }
			/>
		);
	};

	it( 'shows the description with the sandbox notice above the card fields', () => {
		settings( '<p><strong>SANDBOX MODE ENABLED</strong> Use the test details.</p>' );
		const { container } = renderBlock();

		expect( screen.getByText( 'SANDBOX MODE ENABLED' ).tagName ).toBe( 'STRONG' );
		expect( container.firstChild ).toHaveTextContent( 'SANDBOX MODE ENABLED' );
	} );

	it( 'shows no description when there is none', () => {
		settings( '' );
		const { container } = renderBlock();

		expect( container.querySelector( '.wc-nmi-description' ) ).toBeNull();
	} );
} );
