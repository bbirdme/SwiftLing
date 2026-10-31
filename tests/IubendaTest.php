<?php
/**
 * Tests of the class of iubenda.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Iubenda;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeIubenda.php';

/**
 * What SwiftLing does for iubenda: puts the language of the page in the code of the cookie solution
 * the plugin prints, so the service shows its banner in that language.
 */
class IubendaTest extends TestCase {

	/**
	 * The code of the cookie solution, as pasted from the service.
	 */
	const CODE = '<script type="text/javascript">var _iub = _iub || []; _iub.csConfiguration = {"siteId":1234,"cookiePolicyId":5678,"lang":"en","storage":{"useSiteId":true}};</script>';

	/**
	 * The class, with the plugin's main object.
	 *
	 * @var FakeIubenda
	 */
	private $iubenda;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		$this->iubenda = new FakeIubenda( self::CODE );
		$builders      = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Iubenda ? $this->iubenda : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->iubenda->register();
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Runs what the plugin's hooks run when the page starts rendering, as WordPress does, and returns
	 * the code the plugin prints.
	 *
	 * @return string
	 */
	private function printed() {
		$this->assertSame( 0, has_action( 'template_redirect', array( $this->iubenda, 'pageLanguage' ) ) );
		$this->iubenda->pageLanguage();
		return $this->iubenda->main->options['cs']['code_default'];
	}

	/**
	 * A page in a language prints the code with that language, by the plugin's map of languages where
	 * it names one, as "pt-BR"; the original page prints it as pasted.
	 */
	public function test_language_of_the_page_in_the_code() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'pt_BR' => 'br',
				'fr_FR' => 'fr',
			)
		);
		$this->assertSame( self::CODE, $this->printed(), 'The original page' );
		foreach ( array(
			'de' => 'de',
			'br' => 'pt-BR',
			'fr' => 'fr',
		) as $slug => $code ) {
			$this->iubenda->main->options['cs']['code_default'] = self::CODE;
			$this->show_in( $slug );
			$this->assertSame( str_replace( '"lang":"en"', '"lang":"' . $code . '"', self::CODE ), $this->printed(), $slug );
		}
	}

	/**
	 * The language is put in the code however it was written, and nowhere else; a code without one,
	 * such as a script of embeds.iubenda.com, stays as it is.
	 */
	public function test_language_in_any_code() {
		$this->assertSame( "{lang: 'de-DE', 'lang' : 'de-DE'}", Iubenda::withLanguage( "{lang: 'en', 'lang' : 'en-GB'}", 'de-DE' ) );
		$this->assertSame( '{"cookiePolicyLang":"en", x.lang: "en"}', Iubenda::withLanguage( '{"cookiePolicyLang":"en", x.lang: "en"}', 'de' ) );
		$widget = '<script src="https://embeds.iubenda.com/widgets/abc.js"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A code as pasted, never printed here.
		$this->assertSame( $widget, Iubenda::withLanguage( $widget, 'de' ) );
		$this->assertSame( '{"siteId":1}', Iubenda::withLanguage( '{"siteId":1}', 'de' ) );
		$this->assertSame( '{"lang":"en"}', Iubenda::withLanguage( '{"lang":"en"}', '' ) );
		$this->assertSame( 'de', Iubenda::code( 'de_DE_formal', array() ) );
	}
}
