<?php
/**
 * GiveWP: the texts of its donation forms.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

use SwiftLing\Builders\HtmlTexts;
use SwiftLing\Builders\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * GiveWP's donation forms, those of its visual form builder (GiveWP 3.0 and newer). A form is a post of
 * the type "give_forms" whose blocks and settings are JSON in the plugin's own table of meta,
 * "give_formmeta", read straight from the table. A visitor sees the form in a frame, the page of the
 * plugin's route "donation-form-view", which runs in the language of the page that holds it: the
 * plugin builds the frame's address with home_url(). The page hands the form to its script as data,
 * "window.givewpDonationFormExports": the fields the plugin made of the blocks, and the settings. The
 * page after a donation, the route "donation-confirmation-receipt-view", hands the receipt the same way,
 * "window.givewpDonationConfirmationReceiptExports", with its heading and description written from the
 * form's templates.
 *
 * Neither reaches any filter with the texts, so they are translated in that data, just before
 * WordPress prints it, see translateScripts(): under the owner "givewp:<form ID>",
 *
 * - "form/texts/settings/<name>": a text of the form's settings, see SETTINGS;
 * - "form/texts/fields/<key>": a text of a field, see FIELD_TEXTS, by its words, see HtmlTexts::key(),
 *   so a field moved keeps its translation and the same text is translated once.
 *
 * The receipt's heading and description are written again from the translation of their templates,
 * see Templates::refill(). Not translated: the words of the plugin itself, which its language packs
 * have, and forms of the plugin's older form editor.
 *
 * Checked against the code of GiveWP 4.19 (the free plugin), in tests/GiveWPTest.php, and by hand on a
 * site with the plugin.
 */
class GiveWP extends TableProvider {
	/**
	 * The handle of the plugin's script the data of a form and of a receipt goes with.
	 */
	const SCRIPT = 'givewp-donation-form-registrars';

	/**
	 * The names of the plugin's data for its script: the form, and the receipt.
	 */
	const FORM_DATA    = 'givewpDonationFormExports';
	const RECEIPT_DATA = 'givewpDonationConfirmationReceiptExports';

	/**
	 * The settings of a form a visitor reads.
	 */
	const SETTINGS = array( 'formTitle', 'heading', 'description', 'donateButtonCaption', 'multiStepFirstButtonText', 'multiStepNextButtonText', 'goalAchievedMessage', 'receiptHeading', 'receiptDescription', 'designSettingsImageAlt', 'offlineDonationsInstructions', 'formGridDonateButtonText', 'formExcerpt' );

	/**
	 * The texts of a field, as the plugin hands a field to its script.
	 */
	const FIELD_TEXTS = array( 'label', 'description', 'helpText', 'placeholder', 'content', 'checkboxLabel', 'linkText', 'modalHeading', 'modalAcceptanceText', 'agreementText', 'customAmountText' );

	/**
	 * Fields whose texts no visitor reads: the field that catches robots.
	 */
	const HIDDEN = array( 'honeypot' );

	/**
	 * The placeholders of the plugin's templates, as {first_name}.
	 */
	const PLACEHOLDER = '/\{[a-z0-9_:]+\}/i';

	/**
	 * The form the plugin built the fields of last, for its data printed next.
	 *
	 * @var int
	 */
	private $form = 0;

	/**
	 * What the editor calls each text of a form, by form and key, as read.
	 *
	 * @var array<int, array<string, string>>
	 */
	private $labels = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'givewp';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'GiveWP';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'GIVE_VERSION' );
	}

	/**
	 * Hooks where the plugin builds the fields of a form, and where WordPress prints the data of its
	 * script, in the head or in the footer of the page.
	 */
	public function register(): void {
		add_action( 'givewp_donation_form_schema', array( $this, 'noteForm' ), 10, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own action.
		add_action( 'wp_print_scripts', array( $this, 'translateScripts' ), 1 );
		add_action( 'wp_print_footer_scripts', array( $this, 'translateScripts' ), 1 );
	}

	/**
	 * Notes the form the plugin built the fields of: the data it prints next is that form's.
	 *
	 * @param mixed $form    The fields, as the plugin's object.
	 * @param mixed $form_id The form's ID.
	 */
	public function noteForm( $form, $form_id = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- The plugin passes the fields first.
		$this->form = is_numeric( $form_id ) ? (int) $form_id : 0;
	}

	/**
	 * In a language, translates the data of a form and of a receipt the plugin hands its script, before
	 * WordPress prints it.
	 */
	public function translateScripts(): void {
		if ( 'translate' !== swiftling_mode() || ! $this->form ) {
			return;
		}
		$scripts = wp_scripts();
		$before  = $scripts->get_data( self::SCRIPT, 'before' );
		if ( ! is_array( $before ) ) {
			return;
		}
		$changed = false;
		foreach ( $before as $index => $code ) {
			$new = is_string( $code ) ? $this->translateCode( $code ) : $code;
			if ( $new !== $code ) {
				$before[ $index ] = $new;
				$changed          = true;
			}
		}
		if ( $changed ) {
			$scripts->add_data( self::SCRIPT, 'before', $before );
		}
	}

	/**
	 * Returns a line of the data of the plugin's script with the texts of the form in the language: the
	 * form's data, or the receipt's. Any other line, or data in another shape, stays as it is.
	 *
	 * @param string $code The line, as "window.givewpDonationFormExports = {...};".
	 */
	private function translateCode( string $code ): string {
		if ( ! preg_match( '/^window\.(' . self::FORM_DATA . '|' . self::RECEIPT_DATA . ') = (\{.*\});$/s', trim( $code ), $found ) ) {
			return $code;
		}
		$data = json_decode( $found[2], true );
		if ( ! is_array( $data ) ) {
			return $code;
		}
		if ( self::FORM_DATA === $found[1] && isset( $data['form'] ) && is_array( $data['form'] ) ) {
			$data['form'] = $this->translateForm( $this->form, $data['form'] );
		} elseif ( self::RECEIPT_DATA === $found[1] && isset( $data['receipt'] ) && is_array( $data['receipt'] ) ) {
			$data['receipt'] = $this->translateReceipt( $this->form, $data['receipt'] );
		} else {
			return $code;
		}
		$json = wp_json_encode( $data );
		return is_string( $json ) ? 'window.' . $found[1] . ' = ' . $json . ';' : $code;
	}

	/**
	 * Returns the data of a form with its texts in the mode of the moment: its settings, and the texts
	 * of its fields, at any depth.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $form    The form's data: its fields under "nodes", its settings under "settings".
	 */
	protected function translateForm( int $form_id, array $form ): array {
		$places = array();
		$labels = array();
		foreach ( self::SETTINGS as $name ) {
			if ( 'offlineDonationsInstructions' === $name && empty( $form['settings']['offlineDonationsCustomize'] ) ) {
				continue;
			}
			self::addPlace( $places, $form, array( 'settings', $name ), 'settings/' . $name );
			$labels[ 'settings/' . $name ] = self::settingLabel( $name );
		}
		$this->fieldPlaces( $places, $labels, $form, array(), '' );
		$this->labels[ $form_id ] = $labels + ( $this->labels[ $form_id ] ?? array() );
		return $this->translate( $form_id, $form, $places );
	}

	/**
	 * Adds the places of the texts of the fields under a node of a form, and what the editor calls each.
	 *
	 * @param array<string, array<int, int|string>> $places Places so far.
	 * @param array<string, string>                 $labels Names of the texts so far.
	 * @param array                                 $form   The form's data.
	 * @param array<int, int|string>                $path   Keys that lead to the node.
	 * @param string                                $where  What the editor calls the node's section.
	 */
	private function fieldPlaces( array &$places, array &$labels, array $form, array $path, string $where ): void {
		$node = $path ? self::at( $form, $path ) : $form;
		if ( ! is_array( $node ) || in_array( $node['type'] ?? '', self::HIDDEN, true ) ) {
			return;
		}
		if ( $path ) {
			$name  = isset( $node['label'] ) && is_string( $node['label'] ) && '' !== trim( $node['label'] ) ? wp_strip_all_tags( $node['label'] ) : (string) ( $node['name'] ?? '' );
			$field = implode( ' › ', array_filter( array( $where, $name ), 'strlen' ) );
			foreach ( self::FIELD_TEXTS as $text ) {
				$this->fieldPlace( $places, $labels, $form, array_merge( $path, array( $text ) ), $field . ' › ' . self::textLabel( $text ) );
			}
			foreach ( array_keys( is_array( $node['levels'] ?? null ) ? $node['levels'] : array() ) as $level ) {
				/* translators: %d: number of an amount a donor can choose, counted from one. */
				$this->fieldPlace( $places, $labels, $form, array_merge( $path, array( 'levels', $level, 'label' ) ), $field . ' › ' . sprintf( __( 'Amount %d', 'swiftling' ), (int) $level + 1 ) );
			}
			if ( 'section' === ( $node['type'] ?? '' ) ) {
				$where = $name;
			}
		}
		foreach ( array_keys( is_array( $node['nodes'] ?? null ) ? $node['nodes'] : array() ) as $child ) {
			$this->fieldPlaces( $places, $labels, $form, array_merge( $path, array( 'nodes', $child ) ), $where );
		}
	}

	/**
	 * Adds the place of a text of a field, keyed by its words.
	 *
	 * @param array<string, array<int, int|string>> $places Places so far.
	 * @param array<string, string>                 $labels Names of the texts so far.
	 * @param array                                 $form   The form's data.
	 * @param array<int, int|string>                $path   Keys that lead to the text.
	 * @param string                                $label  What the editor calls the text.
	 */
	private function fieldPlace( array &$places, array &$labels, array $form, array $path, string $label ): void {
		$value = self::at( $form, $path );
		if ( ! is_string( $value ) || ! preg_match( '/\p{L}/u', wp_strip_all_tags( $value ) ) ) {
			return;
		}
		$key = 'fields/' . HtmlTexts::key( $value );
		// The same text is one text, wherever it is: each place is listed, the first names it.
		$places[ $key . '/' . md5( implode( '/', $path ) ) ] = $path;
		$labels[ $key ]                                      = $labels[ $key ] ?? $label;
	}

	/**
	 * Runs texts through the current mode, the same text once: the places of a text of a field are
	 * keyed by its words and where it is, the text by its words alone.
	 *
	 * @param int                                   $form_id   Form ID.
	 * @param mixed                                 $container The form's data.
	 * @param array<string, array<int, int|string>> $places    Places, see fieldPlace().
	 * @return mixed
	 */
	protected function translate( int $form_id, $container, array $places ) {
		$merged = array();
		$where  = array();
		foreach ( $places as $key => $path ) {
			$text            = 0 === strpos( $key, 'fields/' ) ? substr( $key, 0, 15 ) : $key;
			$merged[ $text ] = $path;
			$where[ $key ]   = $text;
		}
		$translated = parent::translate( $form_id, $container, $merged );
		foreach ( $where as $key => $text ) {
			$value = self::at( $translated, $merged[ $text ] );
			if ( is_string( $value ) && $merged[ $text ] !== $places[ $key ] && self::at( $container, $places[ $key ] ) === self::at( $container, $merged[ $text ] ) ) {
				self::setAt( $translated, $places[ $key ], $value );
			}
		}
		return $translated;
	}

	/**
	 * Returns a receipt with its heading and description in the mode of the moment: written again from
	 * the translations of the form's templates. The labels of the details that are texts of the form's
	 * fields, as a field of its own, are translated as there.
	 *
	 * @param int   $form_id Form ID.
	 * @param array $receipt The receipt's data.
	 */
	protected function translateReceipt( int $form_id, array $receipt ): array {
		$settings = $this->settingsOf( $form_id );
		foreach ( array(
			'heading'     => 'receiptHeading',
			'description' => 'receiptDescription',
		) as $field => $setting ) {
			$written  = $receipt['settings'][ $field ] ?? null;
			$template = $settings[ $setting ] ?? null;
			if ( ! is_string( $written ) || ! is_string( $template ) || '' === trim( $template ) ) {
				continue;
			}
			$translated = $this->translate( $form_id, array( 'settings' => array( $setting => $template ) ), array( 'settings/' . $setting => array( 'settings', $setting ) ) );
			$refilled   = Templates::refill( $template, (string) $translated['settings'][ $setting ], $written, self::PLACEHOLDER );
			if ( null !== $refilled ) {
				$receipt['settings'][ $field ] = $refilled;
			}
		}
		foreach ( array( 'donorDetails', 'donationDetails', 'additionalDetails' ) as $list ) {
			$places = array();
			foreach ( array_keys( is_array( $receipt[ $list ] ?? null ) ? $receipt[ $list ] : array() ) as $row ) {
				$label = self::at( $receipt, array( $list, $row, 'label' ) );
				if ( is_string( $label ) && '' !== trim( $label ) ) {
					$places[ 'fields/' . HtmlTexts::key( $label ) . '/' . $row ] = array( $list, $row, 'label' );
				}
			}
			if ( $places && 'translate' === swiftling_mode() ) {
				$receipt = $this->translate( $form_id, $receipt, $places );
			}
		}
		return $receipt;
	}

	/**
	 * Returns the title of a form as an email names a donation, in the mode of the moment: the form's
	 * title, and after it the description of the amount the donor chose, each translated as on the form.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $title   The title, as the plugin wrote it.
	 */
	public function translateTitle( int $form_id, string $title ): string {
		$data = $form_id ? $this->dataOf( $form_id ) : null;
		$name = is_array( $data ) ? ( $data['settings']['formTitle'] ?? null ) : null;
		$post = $form_id ? get_post_field( 'post_title', $form_id, 'raw' ) : '';
		foreach ( array_unique( array_filter( array( is_string( $name ) ? $name : '', is_string( $post ) ? $post : '' ), 'strlen' ) ) as $original ) {
			if ( 0 !== strpos( $title, $original ) ) {
				continue;
			}
			$translated = $this->translate( $form_id, array( 'settings' => array( 'formTitle' => $original ) ), array( 'settings/formTitle' => array( 'settings', 'formTitle' ) ) );
			$rest       = substr( $title, strlen( $original ) );
			// The description of the chosen amount, after the plugin's separator.
			if ( preg_match( '/^(\s*[^\p{L}\s]?\s*)(\p{L}.*)$/su', $rest, $level ) ) {
				$texts = $this->translate( $form_id, array( 'level' => $level[2] ), array( 'fields/' . HtmlTexts::key( $level[2] ) => array( 'level' ) ) );
				$rest  = $level[1] . $texts['level'];
			}
			return $translated['settings']['formTitle'] . $rest;
		}
		return $title;
	}

	/**
	 * Returns the stored settings of a form, as the plugin reads them.
	 *
	 * @param int $form_id Form ID.
	 * @return array<string, mixed>
	 */
	protected function settingsOf( int $form_id ): array {
		$data = $this->dataOf( $form_id );
		return is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
	}

	/**
	 * Returns a form's data as the plugin hands it to its script: its fields, made of its blocks, and
	 * its settings.
	 *
	 * @param int $form_id Form ID.
	 * @return array|null Null when there is no such form.
	 */
	protected function dataOf( int $form_id ): ?array {
		if ( ! class_exists( '\Give\DonationForms\Models\DonationForm' ) || ! function_exists( 'give' ) ) {
			return null;
		}
		$form = \Give\DonationForms\Models\DonationForm::find( $form_id );
		if ( ! $form ) {
			return null;
		}
		try {
			$schema = give( \Give\DonationForms\Repositories\DonationFormRepository::class )->getFormSchemaFromBlocks( $form_id, $form->blocks );
		} catch ( \Throwable $e ) {
			return null;
		}
		$data             = json_decode( (string) wp_json_encode( $schema ), true );
		$data             = is_array( $data ) ? $data : array();
		$data['settings'] = json_decode( (string) wp_json_encode( $form->settings ), true );
		return $data;
	}

	/**
	 * Returns the forms of the plugin's form builder, drafts too.
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	protected function allForms(): array {
		global $wpdb;
		$meta = $wpdb->prefix . 'give_formmeta';
		if ( ! $this->hasTable( $meta ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the Forms screen.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.post_title FROM %i p JOIN %i m ON m.form_id = p.ID AND m.meta_key = 'formBuilderFields' WHERE p.post_type = 'give_forms' AND p.post_status IN ( 'publish', 'draft', 'private', 'pending' ) ORDER BY p.ID LIMIT 200", $wpdb->posts, $meta ) );
		$forms = array();
		foreach ( (array) $rows as $row ) {
			$forms[] = array(
				'id'    => (int) $row->ID,
				'title' => trim( (string) $row->post_title ),
			);
		}
		return $forms;
	}

	/**
	 * Reads the texts of a form as the page of the form hands them to its script.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function readTexts( int $form_id ): void {
		$data = $this->dataOf( $form_id );
		if ( null !== $data ) {
			$this->translateForm( $form_id, $data );
		}
	}

	/**
	 * Returns the ways a post shows a form: the plugin's block or shortcode with the form's ID.
	 *
	 * @param int $form_id Form ID.
	 * @return array<int, string[]>
	 */
	protected function pageMarks( int $form_id ): array {
		return array(
			array( '<!-- wp:give/donation-form ', '"id":' . $form_id . ',' ),
			array( '<!-- wp:give/donation-form ', '"id":' . $form_id . '}' ),
			array( '[give_form id="' . $form_id . '"' ),
		);
	}

	/**
	 * Whether the current user may edit the plugin's forms.
	 */
	protected function mayEdit(): bool {
		return current_user_can( 'edit_give_forms' ) || current_user_can( 'manage_options' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- The plugin's own capability.
	}

	/**
	 * Returns what the editor calls a text of a form.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of a form of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		$form_id = $this->formOf( $owner );
		if ( ! $form_id || 0 !== strpos( $path, self::BASE . '/' ) ) {
			return '';
		}
		$key = substr( $path, strlen( self::BASE ) + 1 );
		if ( 0 === strpos( $key, 'settings/' ) ) {
			return self::settingLabel( substr( $key, 9 ) );
		}
		return $this->labels[ $form_id ][ $key ] ?? __( 'Field', 'swiftling' );
	}

	/**
	 * Returns what the editor calls a setting of a form.
	 *
	 * @param string $name Name of the setting.
	 */
	private static function settingLabel( string $name ): string {
		$labels = array(
			'formTitle'                    => __( 'Title', 'swiftling' ),
			'heading'                      => __( 'Heading', 'swiftling' ),
			'description'                  => __( 'Description', 'swiftling' ),
			'donateButtonCaption'          => __( 'Button', 'swiftling' ),
			'multiStepFirstButtonText'     => __( 'Button of the first step', 'swiftling' ),
			'multiStepNextButtonText'      => __( 'Button of the next steps', 'swiftling' ),
			'goalAchievedMessage'          => __( 'Message when the goal is reached', 'swiftling' ),
			'receiptHeading'               => __( 'Heading after the donation', 'swiftling' ),
			'receiptDescription'           => __( 'Text after the donation', 'swiftling' ),
			'designSettingsImageAlt'       => __( 'Alt text', 'swiftling' ),
			'offlineDonationsInstructions' => __( 'Instructions for an offline donation', 'swiftling' ),
			'formGridDonateButtonText'     => __( 'Button in a grid of forms', 'swiftling' ),
			'formExcerpt'                  => __( 'Excerpt', 'swiftling' ),
		);
		return $labels[ $name ] ?? $name;
	}

	/**
	 * Returns what the editor calls a text of a field.
	 *
	 * @param string $text Name of the text, see FIELD_TEXTS.
	 */
	private static function textLabel( string $text ): string {
		$labels = array(
			'label'               => __( 'Label', 'swiftling' ),
			'description'         => __( 'Description', 'swiftling' ),
			'helpText'            => __( 'Help text', 'swiftling' ),
			'placeholder'         => __( 'Placeholder', 'swiftling' ),
			'content'             => __( 'Text', 'swiftling' ),
			'checkboxLabel'       => __( 'Label of the checkbox', 'swiftling' ),
			'linkText'            => __( 'Text of the link', 'swiftling' ),
			'modalHeading'        => __( 'Heading of the window', 'swiftling' ),
			'modalAcceptanceText' => __( 'Button of the window', 'swiftling' ),
			'agreementText'       => __( 'Agreement text', 'swiftling' ),
			'customAmountText'    => __( 'Text of a custom amount', 'swiftling' ),
		);
		return $labels[ $text ] ?? $text;
	}
}
