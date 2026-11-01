<?php
/**
 * Tests of the class of MailPoet.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Forms\MailPoet;

require_once __DIR__ . '/stubs/FakeMailPoet.php';

/**
 * What SwiftLing translates of MailPoet: the texts of a form's blocks and its message after
 * subscribing, offered by form, and put into the HTML the plugin printed, wherever it printed it.
 */
class MailPoetTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeMailPoet
	 */
	private $mailpoet;

	/**
	 * The blocks of the form of the tests: a paragraph, a field with a value it is filled with, and a
	 * button in columns.
	 *
	 * @var array
	 */
	private $body = array(
		array(
			'type'   => 'paragraph',
			'id'     => 'paragraph',
			'params' => array( 'content' => 'Savjeti iz vrta.' ),
		),
		array(
			'type'   => 'text',
			'id'     => 'first_name',
			'params' => array(
				'label' => 'Ime',
				'value' => 'Gost',
			),
		),
		array(
			'type' => 'columns',
			'body' => array(
				array(
					'type'   => 'submit',
					'id'     => 'submit',
					'params' => array( 'label' => 'Pretplati se!' ),
				),
			),
		),
	);

	/**
	 * Puts the fake in the registry and hooks it, with the plugin's table and a form.
	 */
	public function set_up() {
		parent::set_up();
		FakeMailPoet::install(
			$this->body,
			array(
				'success_message' => 'Provjerite poštu.',
				'segments'        => array( 1 ),
			)
		);
		$this->mailpoet = new FakeMailPoet();
		$builders       = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof MailPoet ? $this->mailpoet : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->mailpoet->register();
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
	 * Returns a form as the plugin prints it: its blocks rendered, and the message after subscribing.
	 */
	private function printed() {
		$renderer = new \MailPoet\Form\Renderer();
		return '<form class="mailpoet_form"><input type="hidden" name="data[form_id]" value="1" />' . $renderer->renderBlocks( $this->body, array(), 1 ) . "<div class=\"mailpoet_message\"><p class=\"mailpoet_validate_success\" style=\"display:none;\">Provjerite poštu.\n</p></div></form>";
	}

	/**
	 * Translates every text of the form into German.
	 */
	private function translate_form() {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'mailpoet-form:1' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		foreach ( array(
			'Savjeti iz vrta.'  => 'Tipps aus dem Garten.',
			'Ime'               => 'Vorname',
			'Pretplati se!'     => 'Abonnieren!',
			'Provjerite poštu.' => 'Sehen Sie in Ihr Postfach.',
		) as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
	}

	/**
	 * A form offers the texts of its blocks, also in columns, and its message after subscribing; not the
	 * value a field is filled with, which the plugin keeps. A form in the plugin's bin is not listed.
	 */
	public function test_texts_offered() {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'mailpoet-form:1' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Savjeti iz vrta.', 'Ime', 'Pretplati se!', 'Provjerite poštu.' ), array_keys( $units ) );
		$this->assertSame( 'First name › Label', Registry::fieldLabel( $units['Ime']['o'], $units['Ime']['p'] ) );
		$this->assertSame( 'Message after subscribing', Registry::fieldLabel( $units['Provjerite poštu.']['o'], $units['Provjerite poštu.']['p'] ) );

		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:mailpoet/subscription-form-block {"formId":1} /-->',
			)
		);
		$forms = $this->mailpoet->forms();
		$this->assertCount( 1, $forms );
		$this->assertSame( 1, $forms[0]['pages'] );
		$this->assertSame( array( 'mailpoet', 'mailpoet_subscription_form' ), $this->mailpoet->sendingActions() );
	}

	/**
	 * Translated, a form the plugin printed is printed in the language of the page, with names of its
	 * own, wherever it was printed, as often as it was; the original stays, and so does a form another
	 * plugin changed, and HTML without a form.
	 */
	public function test_printed_form_translated() {
		$this->translate_form();
		$page = '<main>Vrt</main>' . $this->printed() . '<aside>' . $this->printed() . '</aside>';
		$this->assertSame( 11, has_filter( 'the_content', array( $this->mailpoet, 'translateMarkup' ) ), 'After the plugin adds its forms below the content' );
		$this->assertSame( $page, $this->mailpoet->translateMarkup( $page ), 'The original' );

		$this->show_in( 'de' );
		$html = apply_filters( 'mailpoet_form_widget_post_process', $page, array( 'form_id' => 1 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( 2, substr_count( $html, '<label>Vorname</label>' ) );
		$this->assertSame( 2, substr_count( $html, 'placeholder="Vorname"' ) );
		$this->assertSame( 2, substr_count( $html, '<p class="mailpoet_form_paragraph">Tipps aus dem Garten.</p>' ) );
		$this->assertSame( 2, substr_count( $html, 'placeholder="Abonnieren!"' ) );
		$this->assertSame( 2, substr_count( $html, '>Sehen Sie in Ihr Postfach.</p>' ) );
		$this->assertSame( 2, substr_count( $html, 'value="Gost"' ), 'The value a field is filled with' );
		$this->assertStringStartsWith( '<main>Vrt</main>', $html );
		preg_match_all( '/mailpoet_error_\w+/', $html, $names );
		$this->assertCount( 4, array_unique( $names[0] ), 'Names of its own for each field of each printed form' );

		$changed = str_replace( '<label>Ime</label>', '<label>Ime *</label>', $this->printed() );
		$this->assertStringContainsString( '<label>Ime *</label>', $this->mailpoet->translateMarkup( $changed ), 'Changed by another plugin' );
		$this->assertSame( '<p>Ime</p>', $this->mailpoet->translateMarkup( '<p>Ime</p>' ) );
	}

	/**
	 * A form printed at the foot of the page, as a popup, is translated there; in the original, nothing
	 * printed there is kept back.
	 */
	public function test_form_at_the_foot_of_the_page() {
		$this->translate_form();
		$level = ob_get_level();
		$this->mailpoet->startFooter();
		$this->assertSame( $level, ob_get_level(), 'The original' );
		$this->mailpoet->endFooter();

		$this->show_in( 'de' );
		ob_start();
		$this->mailpoet->startFooter();
		echo $this->printed(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The plugin's form, as it prints it.
		$this->mailpoet->endFooter();
		$this->assertStringContainsString( '<label>Vorname</label>', (string) ob_get_clean() );
		$this->assertSame( $level, ob_get_level() );
	}
}
