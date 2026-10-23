// The banner and the icon of the plugin page on wordpress.org, into .wordpress-org:
//   node tools/wordpress-org/render.js
// The banner from banner.html, at both sizes wordpress.org shows; the icons from .wordpress-org/icon.svg,
// which wordpress.org shows itself where it can. Needs Playwright, as shots.js.
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright' );
const path = require( 'path' );

const OUT = path.join( __dirname, '..', '..', '.wordpress-org' );

( async () => {
	const browser = await chromium.launch();
	for ( const [ scale, file ] of [ [ 1, 'banner-1544x500.png' ], [ 0.5, 'banner-772x250.png' ] ] ) {
		const page = await ( await browser.newContext( { viewport: { width: 1544, height: 500 }, deviceScaleFactor: scale } ) ).newPage();
		await page.goto( 'file://' + path.join( __dirname, 'banner.html' ) );
		await page.evaluate( () => document.fonts.ready );
		await page.screenshot( { path: path.join( OUT, file ), clip: { x: 0, y: 0, width: 1544, height: 500 } } );
		console.log( file );
	}
	for ( const size of [ 128, 256 ] ) {
		const page = await ( await browser.newContext( { viewport: { width: size, height: size } } ) ).newPage();
		await page.goto( 'file://' + path.join( OUT, 'icon.svg' ) );
		await page.evaluate( ( s ) => {
			const svg = document.querySelector( 'svg' );
			svg.setAttribute( 'width', s );
			svg.setAttribute( 'height', s );
		}, size );
		await page.screenshot( { path: path.join( OUT, 'icon-' + size + 'x' + size + '.png' ), clip: { x: 0, y: 0, width: size, height: size } } );
		console.log( 'icon-' + size + 'x' + size + '.png' );
	}
	await browser.close();
} )();
