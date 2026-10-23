// The screenshots of readme.txt, from the screenshot site set up by content.php and site.php:
//   node tools/wordpress-org/shots.js https://swiftling-shots.ddev.site [about-us page ID]
// Logs in as admin/admin, types the German words of the address bases on the URLs screen as a
// person would, then takes each screen at 1280×960, twice as sharp, into .wordpress-org. Needs
// Playwright (npx playwright install chromium), found as "playwright" or at $PLAYWRIGHT.
const { chromium } = require( process.env.PLAYWRIGHT || 'playwright' );
const path = require( 'path' );

const SITE = ( process.argv[ 2 ] || '' ).replace( /\/$/, '' );
const ABOUT = process.argv[ 3 ];
const OUT = path.join( __dirname, '..', '..', '.wordpress-org' );
if ( ! SITE || ! ABOUT ) {
	console.error( 'Usage: node shots.js <site address> <ID of the About us page>' );
	process.exit( 1 );
}

// In the order of the captions under Screenshots in readme.txt.
const SHOTS = [
	[ 'screenshot-1.png', '/wp-admin/admin.php?page=swiftling-translation&lang=de&post=' + ABOUT ],
	[ 'screenshot-2.png', '/wp-admin/admin.php?page=swiftling-languages' ],
	[ 'screenshot-3.png', '/wp-admin/admin.php?page=swiftling-addresses' ],
	[ 'screenshot-4.png', '/wp-admin/admin.php?page=swiftling-switcher' ],
	[ 'screenshot-5.png', '/wp-admin/admin.php?page=swiftling-translate-all' ],
	[ 'screenshot-6.jpg', '/de/', true ],
];

( async () => {
	const browser = await chromium.launch();
	const context = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1280, height: 960 }, deviceScaleFactor: 2 } );
	const guest = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1280, height: 960 }, deviceScaleFactor: 2 } );
	const page = await context.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( e.message ) );

	await page.goto( SITE + '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'admin' );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

	await page.goto( SITE + '/wp-admin/admin.php?page=swiftling-addresses' );
	await page.fill( 'input[name="bases[base:tax:category:0]"]:visible', 'kategorie' );
	await page.fill( 'input[name="bases[base:tax:post_tag:0]"]:visible', 'schlagwort' );
	await Promise.all( [ page.waitForNavigation(), page.click( 'text=Save Deutsch' ) ] );

	for ( const [ file, where, asGuest ] of SHOTS ) {
		const shot = asGuest ? await guest.newPage() : page;
		await shot.goto( SITE + where, { waitUntil: 'networkidle' } );
		await shot.waitForTimeout( 800 );
		await shot.screenshot( { path: path.join( OUT, file ), ...( file.endsWith( '.jpg' ) ? { type: 'jpeg', quality: 85 } : {} ) } );
		console.log( file );
	}
	console.log( errors.length ? 'Script errors: ' + errors.join( '; ' ) : 'No script errors.' );
	await browser.close();
} )();
