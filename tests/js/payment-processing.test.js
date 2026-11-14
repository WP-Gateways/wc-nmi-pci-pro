/**
 * Tests for usePaymentProcessing() in src/payment-processing.js: the token request for each payment attempt.
 */
import { resetMockSettings, setMockSetting } from './__mocks__/wc-settings';

setMockSetting( 'nmi_data', {
	timeout_error: 'Timed out',
	card_disallowed_error: 'Card not allowed',
	allowed_card_types: [ 'visa', 'mastercard' ],
} );
const { TOKEN_REQUEST_TIMEOUT } = require( '../../src/utils' );
const { usePaymentProcessing } = require( '../../src/payment-processing' );
const { renderHook } = require( '@testing-library/react' );

const emitResponse = {
	responseTypes: { ERROR: 'error', SUCCESS: 'success' },
	noticeContexts: { PAYMENTS: 'payments' },
};
const visa = ( token ) => ( { token, card: { type: 'visa' } } );

describe( 'usePaymentProcessing', () => {
	let submit, pendingTokenRef, unhandled;
	const onUnhandled = ( reason ) => unhandled.push( reason );

	const setup = () => {
		pendingTokenRef = { current: null };
		const onPaymentSetup = jest.fn( ( callback ) => {
			submit = callback;
			return () => {};
		} );
		const onCheckoutFail = jest.fn( () => () => {} );
		return renderHook( () =>
			usePaymentProcessing(
				{ billingAddress: { first_name: 'A' } },
				emitResponse,
				onPaymentSetup,
				onCheckoutFail,
				pendingTokenRef
			)
		);
	};

	beforeEach( () => {
		jest.useFakeTimers();
		unhandled = [];
		process.on( 'unhandledRejection', onUnhandled );
		window.CollectJS = { startPaymentRequest: jest.fn() };
	} );

	afterEach( () => {
		process.off( 'unhandledRejection', onUnhandled );
		jest.useRealTimers();
		delete window.CollectJS;
	} );

	afterAll( () => resetMockSettings() );

	it( 'returns the token when Collect.js calls back', async () => {
		setup();
		const result = submit();
		pendingTokenRef.current.resolve( visa( 'one' ) );
		const response = await result;
		expect( response.type ).toBe( 'success' );
		expect( JSON.parse( response.meta.paymentMethodData.nmi_js_response ).token ).toBe( 'one' );
		expect( pendingTokenRef.current ).toBeNull();
	} );

	it( 'fails on timeout and the next attempt can succeed', async () => {
		setup();
		const first = submit();
		jest.advanceTimersByTime( TOKEN_REQUEST_TIMEOUT );
		expect( await first ).toEqual( { type: 'error', message: 'Timed out' } );
		expect( pendingTokenRef.current ).toBeNull();

		const second = submit();
		pendingTokenRef.current.resolve( visa( 'two' ) );
		const response = await second;
		expect( response.type ).toBe( 'success' );
		expect( JSON.parse( response.meta.paymentMethodData.nmi_js_response ).token ).toBe( 'two' );
		expect( window.CollectJS.startPaymentRequest ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'fails on a field error and the next attempt can succeed', async () => {
		setup();
		const first = submit();
		pendingTokenRef.current.reject( new Error( 'Card number is invalid' ) );
		expect( await first ).toEqual( { type: 'error', message: 'Card number is invalid' } );

		const second = submit();
		pendingTokenRef.current.resolve( visa( 'two' ) );
		expect( ( await second ).type ).toBe( 'success' );
	} );

	it( 'ignores a late callback from the first attempt during the second attempt', async () => {
		setup();
		const first = submit();
		const firstRequest = pendingTokenRef.current;
		jest.advanceTimersByTime( TOKEN_REQUEST_TIMEOUT );
		await first;

		const second = submit();
		const secondRequest = pendingTokenRef.current;
		expect( secondRequest ).not.toBe( firstRequest );

		// The first attempt's token arrives late: it must not settle the second attempt or throw.
		expect( () => firstRequest.resolve( visa( 'late' ) ) ).not.toThrow();
		expect( pendingTokenRef.current ).toBe( secondRequest );

		secondRequest.resolve( visa( 'two' ) );
		const response = await second;
		expect( JSON.parse( response.meta.paymentMethodData.nmi_js_response ).token ).toBe( 'two' );
	} );

	it( 'does not clear the second attempt when the first settles late', async () => {
		setup();
		const first = submit();
		const firstRequest = pendingTokenRef.current;
		const second = submit();
		const secondRequest = pendingTokenRef.current;

		firstRequest.reject( new Error( 'first failed' ) );
		await first;
		expect( pendingTokenRef.current ).toBe( secondRequest );

		secondRequest.resolve( visa( 'two' ) );
		expect( ( await second ).type ).toBe( 'success' );
	} );

	it( 'settles the request when starting it throws, leaving no timer to reject later', async () => {
		setup();
		window.CollectJS.startPaymentRequest.mockImplementation( () => {
			throw new Error( 'Collect.js not ready' );
		} );
		expect( await submit() ).toEqual( { type: 'error', message: 'Collect.js not ready' } );
		expect( pendingTokenRef.current ).toBeNull();
		expect( jest.getTimerCount() ).toBe( 0 );

		jest.advanceTimersByTime( TOKEN_REQUEST_TIMEOUT * 2 );
		await Promise.resolve();
		expect( unhandled ).toEqual( [] );
	} );
} );
