/**
 * Tests for the block checkout helpers in src/utils.js (ported from the Enterprise plugin, card only).
 */
import { resetMockSettings, setMockSetting } from './__mocks__/wc-settings';

const setNmiSettings = ( settings ) => setMockSetting( 'nmi_data', settings );

// utils.js reads the settings when it loads (field placeholders), as on the page, where WooCommerce
// prints them before the checkout scripts run. So they're set before the module is loaded.
setNmiSettings( {} );
const {
	PAYMENT_TIMEOUT_DURATION,
	TOKEN_REQUEST_TIMEOUT,
	createDeferred,
	debug,
	getCreditCardIcons,
	getFieldErrorMessage,
	getResponseCard,
	useCollectJS,
} = require( '../../src/utils' );
const { renderHook } = require( '@testing-library/react' );

describe( 'useCollectJS', () => {
	let configured;

	beforeEach( () => {
		configured = null;
		window.CollectJS = {
			configure: jest.fn( ( options ) => {
				configured = options;
			} ),
		};
	} );

	afterEach( () => {
		delete window.CollectJS;
	} );

	const configure = ( handlers ) =>
		renderHook( () =>
			useCollectJS(
				'card',
				jest.fn(),
				handlers.handleValidationErrors ?? jest.fn(),
				handlers.handleSubmission ?? jest.fn(),
				handlers.handleTimeout ?? jest.fn()
			)
		);

	it( 'passes Collect.js timeouts on, so the attempt ends when its request does', () => {
		const handleTimeout = jest.fn();
		configure( { handleTimeout } );

		configured.timeoutCallback();

		expect( handleTimeout ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'outlasts Collect.js with its own backstop timer', () => {
		// A late Collect.js callback could otherwise reach the next attempt.
		expect( TOKEN_REQUEST_TIMEOUT ).toBeGreaterThan( PAYMENT_TIMEOUT_DURATION );
		configure( {} );
		expect( configured.timeoutDuration ).toBe( PAYMENT_TIMEOUT_DURATION );
	} );
} );

beforeEach( () => {
	resetMockSettings();
} );

describe( 'getFieldErrorMessage', () => {
	beforeEach( () => {
		setNmiSettings( {
			card_number_error: 'Invalid card number.',
			error_ref: '(Ref: [ref])',
		} );
	} );

	it( 'adds the Collect.js message as a reference', () => {
		expect( getFieldErrorMessage( 'ccnumber', 'Field is empty' ) ).toBe(
			'Invalid card number. (Ref: Field is empty)'
		);
	} );

	it( 'shows only the field error when Collect.js gives no message', () => {
		expect( getFieldErrorMessage( 'ccnumber', '' ) ).toBe( 'Invalid card number.' );
	} );

	it( 'falls back to the Collect.js message for an unknown field', () => {
		expect( getFieldErrorMessage( 'unknown', 'Something is wrong' ) ).toBe(
			'Something is wrong'
		);
	} );

	it( 'throws when the NMI settings are missing', () => {
		resetMockSettings();
		expect( () => getFieldErrorMessage( 'ccnumber', 'x' ) ).toThrow(
			'nmi initialization data is not available'
		);
	} );
} );

describe( 'createDeferred', () => {
	beforeEach( () => jest.useFakeTimers() );
	afterEach( () => jest.useRealTimers() );

	it( 'resolves with the value it is given', async () => {
		const deferred = createDeferred( 1000, 'Timed out' );
		deferred.resolve( { token: 'abc' } );

		await expect( deferred.promise ).resolves.toEqual( { token: 'abc' } );
	} );

	it( 'rejects with the timeout message when nothing settles it', async () => {
		const deferred = createDeferred( 1000, 'The tokenization did not respond' );
		jest.advanceTimersByTime( 1000 );

		await expect( deferred.promise ).rejects.toThrow( 'The tokenization did not respond' );
	} );

	it( 'does not time out after it has been resolved', async () => {
		const deferred = createDeferred( 1000, 'Timed out' );
		deferred.resolve( 'done' );
		await deferred.promise;

		// The timer is cleared, so nothing is left to fire.
		expect( jest.getTimerCount() ).toBe( 0 );
	} );
} );

describe( 'debug', () => {
	it( 'logs nothing unless Gateway Debug is on', () => {
		// Responses can include card details, so they must never reach the console by default.
		setNmiSettings( { debug: false } );
		debug( 'response', { card: '4111' } );

		expect( console ).not.toHaveLogged();
	} );

	it( 'logs with an [NMI] prefix when Gateway Debug is on', () => {
		setNmiSettings( { debug: true } );
		debug( 'response' );

		expect( console ).toHaveLoggedWith( '[NMI]', 'response' );
	} );
} );


describe( 'getCreditCardIcons', () => {
	it( 'lists the configured card icons', () => {
		setNmiSettings( {
			icons: { visa: { src: 'visa.svg', alt: 'Visa' } },
		} );

		expect( getCreditCardIcons() ).toEqual( [
			{ id: 'visa', src: 'visa.svg', alt: 'Visa' },
		] );
	} );

	it( 'is empty without icons', () => {
		setNmiSettings( {} );

		expect( getCreditCardIcons() ).toEqual( [] );
	} );
} );

describe( 'getResponseCard', () => {
	it( 'uses the card of the token response', () => {
		expect( getResponseCard( { card: { type: 'visa' }, lookupData: { card: { type: 'amex' } } } ) ).toEqual( { type: 'visa' } );
	} );

	it( 'falls back to lookupData when the response card has no type', () => {
		expect( getResponseCard( { card: { number: '' }, lookupData: { card: { type: 'amex' } } } ) ).toEqual( { type: 'amex' } );
	} );

	it( 'is null when neither has a card type', () => {
		expect( getResponseCard( { token: 'x' } ) ).toBeNull();
		expect( getResponseCard( null ) ).toBeNull();
	} );
} );
