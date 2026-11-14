/**
 * The Jest config's own regular expressions: a lost backslash turns "\." into "any character".
 */
const config = require( '../../jest.config' );

describe( 'jest.config.js', () => {
	const stylePattern = Object.keys( config.moduleNameMapper ).find( ( key ) =>
		key.includes( 'scss' )
	);

	it( 'maps stylesheet imports to the style mock', () => {
		expect( new RegExp( stylePattern ).test( './style.scss' ) ).toBe( true );
		expect( new RegExp( stylePattern ).test( './editor.css' ) ).toBe( true );
	} );

	it( 'leaves modules whose names only end in "css" alone', () => {
		expect( new RegExp( stylePattern ).test( 'postcss' ) ).toBe( false );
	} );

	it( 'only transforms JavaScript files', () => {
		const [ transformPattern ] = Object.keys( config.transform );

		expect( new RegExp( transformPattern ).test( 'src/index.js' ) ).toBe( true );
		expect( new RegExp( transformPattern ).test( 'src/foo_jsx' ) ).toBe( false );
	} );
} );
