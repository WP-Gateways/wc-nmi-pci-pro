/**
 * The block checkout card component with its real Collect.js callbacks: how each payment attempt ends.
 */
import { resetMockSettings, setMockSetting } from './__mocks__/wc-settings';

setMockSetting( 'nmi_data', {
	public_key: 'pub',
	timeout_error: 'Timed out',
	card_disallowed_error: 'Card not allowed',
	allowed_card_types: [ 'visa', 'mastercard' ],
	icons: [],
} );
const { createElement } = require( '@wordpress/element' );
const { render, act } = require( '@testing-library/react' );
const { CreditCardComponent } = require( '../../src/nmi-credit-card' );

const emitResponse = {
	responseTypes: { ERROR: 'error', SUCCESS: 'success' },
	noticeContexts: { PAYMENTS: 'payments' },
};
const Passthrough = ( { children } ) => createElement( 'div', null, children );
const token = ( value ) => JSON.parse( value.meta.paymentMethodData.nmi_js_response ).token;

describe( 'CreditCardComponent', () => {
	let collect, submit;

	beforeEach( () => {
		jest.useFakeTimers();
		collect = null;
		window.CollectJS = {
			configure: jest.fn( ( options ) => {
				collect = options;
			} ),
			startPaymentRequest: jest.fn(),
		};
		render(
			createElement( CreditCardComponent, {
				billing: { billingAddress: {} },
				emitResponse,
				eventRegistration: {
					onPaymentSetup: ( callback ) => {
						submit = callback;
						return () => {};
					},
					onCheckoutFail: () => () => {},
				},
				components: { LoadingMask: Passthrough, ValidationInputError: () => null, PaymentMethodIcons: null },
			} )
		);
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.CollectJS;
	} );

	afterAll( () => resetMockSettings() );

	it( 'resolves the attempt with the token Collect.js calls back with', async () => {
		const result = submit();
		act( () => collect.callback( { token: 'one', card: { type: 'visa' } } ) );
		expect( token( await result ) ).toBe( 'one' );
	} );

	it( 'ends an attempt on Collect.js timeout, and the retry gets the next callback', async () => {
		const first = submit();
		act( () => collect.timeoutCallback() );
		expect( await first ).toEqual( { type: 'error', message: 'Timed out' } );

		const second = submit();
		act( () => collect.callback( { token: 'two', card: { type: 'visa' } } ) );
		expect( token( await second ) ).toBe( 'two' );
		expect( window.CollectJS.startPaymentRequest ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'ignores a callback with no attempt waiting, so it cannot settle the next one', async () => {
		const first = submit();
		act( () => collect.timeoutCallback() );
		await first;

		// Arrives after the attempt ended: nothing is waiting for it.
		expect( () => act( () => collect.callback( { token: 'late', card: { type: 'visa' } } ) ) ).not.toThrow();

		const second = submit();
		act( () => collect.callback( { token: 'two', card: { type: 'visa' } } ) );
		expect( token( await second ) ).toBe( 'two' );
	} );

	it( 'accepts a token whose card is only under lookupData', async () => {
		const result = submit();
		act( () => collect.callback( { token: 'one', lookupData: { card: { type: 'mastercard' } } } ) );
		expect( ( await result ).type ).toBe( 'success' );
	} );
} );
