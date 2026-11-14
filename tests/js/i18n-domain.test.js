/**
 * WP-CLI's make-pot (and make-json) only extract strings whose text domain is a string literal, so every
 * translation call in src/ must spell out 'wc-nmi'. A constant such as TEXT_DOMAIN leaves the string out of
 * languages/wc-nmi.pot.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const SRC = path.join( __dirname, '../../src' );

function sourceFiles( dir ) {
	return fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const full = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return sourceFiles( full );
		}
		return /\.(js|jsx|ts|tsx)$/.test( entry.name ) ? [ full ] : [];
	} );
}

// The last argument of __(), _x(), _n(), _nx() and their esc variants: the text domain.
const CALL = /\b(?:__|_x|_n|_nx|esc_html__|esc_attr__)\(((?:[^()]|\([^()]*\))*)\)/g;

describe( 'translation calls in src/', () => {
	test.each( sourceFiles( SRC ).map( ( file ) => [ path.relative( SRC, file ), file ] ) )(
		'%s uses the literal wc-nmi text domain',
		( name, file ) => {
			const code = fs.readFileSync( file, 'utf8' );
			const nonLiteral = [ ...code.matchAll( CALL ) ]
				.map( ( match ) => match[ 0 ] )
				.filter( ( call ) => ! /,\s*'wc-nmi'\s*\)$/.test( call ) );

			expect( nonLiteral ).toEqual( [] );
		}
	);
} );
