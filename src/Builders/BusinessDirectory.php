<?php
/**
 * Business Directory Plugin: what of a listing SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Business Directory Plugin. A listing is a post of the type "wpbdp_listing", its categories and tags
 * terms, and the values of its fields its meta, read through get_post_meta(): SwiftLing finds them
 * by itself. What it does not:
 *
 * - The fields themselves, their names, descriptions and choices, are rows of the plugin's own table,
 *   "wpbdp_form_fields": texts of their own, "business-directory:fields", translated where the plugin
 *   makes a field, see translateField(), and offered on the Templates screen, see listTexts().
 * - The fields that show a listing's title, short description and description read them from the
 *   post itself, past get_the_title() and the_content: put in the mode of the moment where the plugin
 *   hands a value out, see translateValue().
 * - The plugin builds its addresses' rules from the address of its main page on every request and
 *   writes them again when they differ. In another language that page has its translated slug, so the
 *   rules changed on every request and a listing there was not found. The rules are built from the
 *   page's original slug, see ruleBase(); SwiftLing puts a translated slug back before WordPress reads
 *   an address.
 * - A listing gets no separate copy: its plan and payments are kept by its ID. It is translated in
 *   place, see noCopyTypes().
 *
 * - A listing keeps the language of the page it was posted on, see keepLanguage(). The plugin writes
 *   its emails in the actions it hooks, one to the site's owner and one to the listing's owner often
 *   in the same action: around each, see openWindow(), the email the plugin writes next is put in the
 *   language of its reader where the plugin reads the setting that decides who gets it, see
 *   decideReader(): the site's owner in the original, the listing's owner in the listing's language.
 *   A message a visitor sends a listing's owner from its page is written in the listing's language,
 *   see contactTemplate(). The subjects and texts of the emails are values of the plugin's settings,
 *   read before SwiftLing starts: put in place where the plugin asks for one, see translateTemplate(),
 *   and offered with the fields.
 *
 * Checked against the code of Business Directory Plugin 6.4 (the free plugin), in tests/JobBoardsTest.php,
 * and by hand on a site with the plugin. Its premium modules were not checked.
 */
class BusinessDirectory extends SettingsTexts {
	/**
	 * The plugin's type of post.
	 */
	const POST_TYPE = 'wpbdp_listing';

	/**
	 * The object key of the texts of the plugin's fields.
	 */
	const FIELDS = 'business-directory:fields';

	/**
	 * Source of a text of a field, for its rule key.
	 */
	const SOURCE = 'business-directory:field';

	/**
	 * The subject of the plugin's fields on the Templates screen.
	 */
	const SUBJECT = 'business-directory-fields';

	/**
	 * Where a listing keeps the language it was posted in.
	 */
	const LANGUAGE_META = 'swiftling_language';

	/**
	 * The option of the plugin's settings.
	 */
	const SETTINGS = 'wpbdp_settings';

	/**
	 * The plugin's emails to the owners of listings and to visitors, by their setting: each a subject
	 * and a text.
	 */
	const TEMPLATES = array( 'email-confirmation-message', 'email-templates-listing-published', 'email-templates-contact', 'email-templates-payment-completed', 'email-templates-payment-abandoned' );

	/**
	 * The setting with the plugin's notices before and after a listing expires, each a subject and a text.
	 */
	const NOTICES = 'expiration-notices';

	/**
	 * The actions the plugin writes its emails in, by what its first argument is: a listing, a post, the
	 * ID of a listing, a payment, or "site" for those with an email to the site's owner only.
	 */
	const WINDOWS = array(
		'transition_post_status'              => 'post',
		'wpbdp_listing_status_change'         => 'listing',
		'wpbdp_listing_renewed'               => 'listing',
		'wpbdp_listing_maybe_send_notices'    => 'notice',
		'WPBDP_Payment::status_change'        => 'payment',
		'wpbdp_edit_listing'                  => 'site',
		'wpbdp_listing_maybe_flagging_notice' => 'site',
	);

	/**
	 * The actions the plugin is writing emails in: the listing of each, and whether this class changed
	 * the language.
	 *
	 * @var array<int, array{listing: int, changed: bool, inert?: bool, saving?: bool}>
	 */
	private $windows = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'business-directory';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Business Directory Plugin';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'WPBDP_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin: a listing needs nothing of a builder.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A listing needs nothing of a builder.
		return false;
	}

	/**
	 * Returns the plugin's listings: translated in place.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( self::POST_TYPE );
	}

	/**
	 * Hooks where the plugin builds its rules, makes a field and hands out a value.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters.
		add_filter( 'wpbdp_url_base_url', array( $this, 'ruleBase' ), 10, 3 );
		add_filter( 'wpbdp_form_field_args', array( $this, 'translateField' ), 10, 1 );
		add_filter( 'wpbdp_form_field_value', array( $this, 'translateValue' ), 10, 3 );
		add_filter( 'wpbdp_get_option', array( $this, 'translateTemplate' ), 10, 2 );
		add_filter( 'wpbdp_get_option_email-templates-contact', array( $this, 'contactTemplate' ), 10, 1 );
		add_action( 'WPBDP_Listing::set_field_values', array( $this, 'keepLanguage' ), 10, 1 );
		foreach ( self::WINDOWS as $action => $kind ) {
			add_action( $action, array( $this, 'openWindow' ), 9, 3 );
			add_action( $action, array( $this, 'closeWindow' ), 11, 0 );
		}
		// The notice that a listing is published, which the plugin sends at the end of saving the post.
		add_action( 'save_post', array( $this, 'openPublishedWindow' ), PHP_INT_MAX - 1, 2 );
		add_action( 'wp_after_insert_post', array( $this, 'closePublishedWindow' ), 0, 0 );
		// Who gets the email the plugin writes next.
		add_filter( 'wpbdp_get_option_admin-notifications', array( $this, 'decideReader' ), 10, 1 );
		add_filter( 'wpbdp_get_option_user-notifications', array( $this, 'decideReader' ), 10, 1 );
		add_filter( 'wpbdp_get_option_' . self::NOTICES, array( $this, 'decideReader' ), 10, 1 );
		// phpcs:enable
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns the values of the plugin's settings that hold text: the subject and text of each email to
	 * a listing's owner or a visitor. Those of the notices are declared by fieldHoldsText().
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$paths = array();
		foreach ( self::TEMPLATES as $template ) {
			$paths[] = $template . '/subject';
			$paths[] = $template . '/body';
		}
		return array( self::SETTINGS => $paths );
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		return __( 'The subjects and texts of the emails to the owners of listings, in the language each listing was posted in, and to visitors. Keep every placeholder, such as [listing]: the plugin fills it in.', 'swiftling' );
	}

	/**
	 * Whether a value is one text as a whole: the text of an email, with the plugin's placeholders.
	 *
	 * @param string $path Path of the value.
	 */
	public function isWhole( string $path ): bool {
		return 1 === preg_match( '#^option/' . self::SETTINGS . '/(' . implode( '|', array_map( 'preg_quote', self::TEMPLATES ) ) . '|' . self::NOTICES . '/\d+)/body$#', $path );
	}

	/**
	 * Keeps the placeholders of the plugin's emails, as [listing], as they are when a text goes to
	 * machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, '/\[[a-z][a-z_-]*\]/' );
	}

	/**
	 * Returns an email of the plugin's settings the plugin asks for in the mode of the moment: it read
	 * its settings before SwiftLing started.
	 *
	 * @param mixed $value      The setting: a subject and a text, or the list of notices.
	 * @param mixed $setting_id Which setting.
	 * @return mixed
	 */
	public function translateTemplate( $value, $setting_id = '' ) {
		if ( ! is_array( $value ) || ! self::inMode() ) {
			return $value;
		}
		if ( in_array( $setting_id, self::TEMPLATES, true ) ) {
			return $this->templateIn( (string) $setting_id, $value );
		}
		if ( self::NOTICES === $setting_id ) {
			foreach ( $value as $index => $notice ) {
				if ( is_array( $notice ) ) {
					$value[ $index ] = $this->templateIn( self::NOTICES . '/' . $index, $notice );
				}
			}
		}
		return $value;
	}

	/**
	 * Returns an email's subject and text in the mode of the moment.
	 *
	 * @param string $path  Where the email is in the plugin's settings.
	 * @param array  $email The email: its subject and text.
	 */
	private function templateIn( string $path, array $email ): array {
		foreach ( array( 'subject', 'body' ) as $part ) {
			if ( isset( $email[ $part ] ) && is_string( $email[ $part ] ) ) {
				$email[ $part ] = $this->optionText( self::SETTINGS, $path . '/' . $part, $email[ $part ] );
			}
		}
		return $email;
	}

	/**
	 * Returns the email a visitor sends a listing's owner from the listing's page in the language of the
	 * listing, with its title in it: the plugin works the title out in the language of the page first.
	 *
	 * @param mixed $value The email: its subject and text.
	 * @return mixed
	 */
	public function contactTemplate( $value ) {
		$listing = isset( $_REQUEST['listing_id'] ) ? absint( $_REQUEST['listing_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The plugin checks its form; only the listing's ID is read.
		if ( ! is_array( $value ) || ! $listing || self::POST_TYPE !== get_post_type( $listing ) || $this->windows ) {
			return $value;
		}
		$slug = self::listingLanguage( $listing );
		if ( swiftling_writing_language() === $slug ) {
			return $value;
		}
		$stored = swiftling_raw_option( self::SETTINGS );
		$email  = is_array( $stored ) && isset( $stored['email-templates-contact'] ) && is_array( $stored['email-templates-contact'] ) ? $stored['email-templates-contact'] : $value;
		swiftling_write_in( $slug );
		try {
			$email = $this->templateIn( 'email-templates-contact', $email );
			$title = (string) get_the_title( $listing );
		} finally {
			swiftling_resume_translation();
		}
		foreach ( array( 'subject', 'body' ) as $part ) {
			if ( isset( $email[ $part ] ) && is_string( $email[ $part ] ) ) {
				$email[ $part ] = str_replace( '[listing]', $title, $email[ $part ] );
			}
		}
		return $email;
	}

	/**
	 * Keeps the language of the page a listing was posted or edited on; one in the original keeps none.
	 * Saved in the admin, a listing keeps the language it had.
	 *
	 * @param mixed $listing The plugin's object of the listing.
	 */
	public function keepLanguage( $listing ): void {
		$id = is_object( $listing ) && method_exists( $listing, 'get_id' ) ? (int) $listing->get_id() : 0;
		if ( ! $id || self::POST_TYPE !== get_post_type( $id ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		$slug = swiftling_writing_language();
		if ( '' === $slug ) {
			delete_post_meta( $id, self::LANGUAGE_META );
		} else {
			update_post_meta( $id, self::LANGUAGE_META, $slug );
		}
	}

	/**
	 * Before the plugin writes the emails of one of its actions: notes the listing they are about, until
	 * closeWindow(). An action with an email to the site's owner only is in the original; in the others,
	 * each email is put in its reader's language once the plugin decides who gets it, see decideReader().
	 *
	 * @param mixed $first  The action's first argument.
	 * @param mixed $second Its second.
	 * @param mixed $third  Its third.
	 */
	public function openWindow( $first = null, $second = null, $third = null ): void {
		$kind    = self::WINDOWS[ current_action() ] ?? '';
		$listing = 0;
		if ( 'listing' === $kind || 'site' === $kind ) {
			$listing = self::listingOf( $first );
		} elseif ( 'notice' === $kind ) {
			$listing = self::listingOf( $third );
		} elseif ( 'payment' === $kind ) {
			$listing = is_object( $first ) && isset( $first->listing_id ) ? (int) $first->listing_id : 0;
		} elseif ( 'post' === $kind ) {
			// Only a listing that is published now sends anything: to its owner.
			$listing = $first !== $second && 'publish' === $first && $third instanceof \WP_Post && self::POST_TYPE === $third->post_type ? $third->ID : 0;
		}
		$this->windows[] = array(
			'listing' => $listing,
			'changed' => false,
			// Another post's status: the plugin sends nothing.
			'inert'   => 'post' === $kind && ! $listing,
		);
		if ( 'site' === $kind ) {
			$this->writeFor( '' );
		} elseif ( 'post' === $kind && $listing ) {
			$this->writeFor( self::listingLanguage( $listing ) );
		}
	}

	/**
	 * After one of the plugin's actions: the request goes on in its own language.
	 */
	public function closeWindow(): void {
		$window = array_pop( $this->windows );
		if ( null !== $window && $window['changed'] ) {
			swiftling_resume_translation();
		}
	}

	/**
	 * When the plugin sends the notice that a listing is published at the end of saving its post, as for a
	 * listing published in the admin: in the listing's language, until closePublishedWindow().
	 *
	 * @param mixed $post_id The post.
	 * @param mixed $post    Its post.
	 */
	public function openPublishedWindow( $post_id, $post = null ): void {
		global $wpbdp;
		$sender = is_object( $wpbdp ) && isset( $wpbdp->listing_email_notification ) ? $wpbdp->listing_email_notification : null;
		if ( ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type || ! $sender || false === has_action( 'save_post', array( $sender, 'send_listing_published_notification' ) ) ) {
			return;
		}
		$this->windows[] = array(
			'listing' => (int) $post_id,
			'changed' => false,
			'saving'  => true,
		);
		$this->writeFor( self::listingLanguage( (int) $post_id ) );
	}

	/**
	 * After WordPress saved a post: the notice that a listing is published is sent.
	 */
	public function closePublishedWindow(): void {
		$last = count( $this->windows ) - 1;
		if ( $last >= 0 && ! empty( $this->windows[ $last ]['saving'] ) ) {
			$this->closeWindow();
		}
	}

	/**
	 * Puts the email the plugin writes next in the language of its reader, where the plugin reads the
	 * setting that decides who gets it: the site's owner in the original, the listing's owner in the
	 * listing's language. Outside the plugin's actions, nothing changes.
	 *
	 * @param mixed $value The setting.
	 * @return mixed
	 */
	public function decideReader( $value ) {
		$last = count( $this->windows ) - 1;
		if ( $last < 0 || ! empty( $this->windows[ $last ]['inert'] ) ) {
			return $value;
		}
		$owner = 'wpbdp_get_option_admin-notifications' !== current_filter();
		$this->writeFor( $owner ? self::listingLanguage( $this->windows[ $last ]['listing'] ) : '' );
		return $value;
	}

	/**
	 * Puts the request in a language for the email the plugin writes next, in the action it writes in.
	 *
	 * @param string $slug Address slug of the language, or an empty string for the original.
	 */
	private function writeFor( string $slug ): void {
		$last = count( $this->windows ) - 1;
		if ( $this->windows[ $last ]['changed'] ) {
			swiftling_resume_translation();
			$this->windows[ $last ]['changed'] = false;
		}
		if ( swiftling_writing_language() !== $slug ) {
			swiftling_write_in( $slug );
			$this->windows[ $last ]['changed'] = true;
		}
	}

	/**
	 * Returns the ID of a listing an action of the plugin is about: its object or its ID.
	 *
	 * @param mixed $listing The listing.
	 */
	private static function listingOf( $listing ): int {
		if ( is_object( $listing ) && method_exists( $listing, 'get_id' ) ) {
			return (int) $listing->get_id();
		}
		return is_numeric( $listing ) ? (int) $listing : 0;
	}

	/**
	 * Returns the language a listing was posted in, or an empty string for the original.
	 *
	 * @param int $listing The listing.
	 */
	private static function listingLanguage( int $listing ): string {
		$slug = $listing ? (string) get_post_meta( $listing, self::LANGUAGE_META, true ) : '';
		return null !== swiftling_target_language( $slug ) ? $slug : '';
	}

	/**
	 * Returns the address of the plugin's main page the plugin builds its rules from: with the page's
	 * original slug in every language. For a link, which the plugin builds with more arguments, the
	 * address stays as it is, with the slug of the language.
	 *
	 * @param mixed $url     The page's address, as WordPress built it.
	 * @param mixed $page_id The page.
	 * @param mixed $context What the plugin builds a link to; none for its rules.
	 * @return mixed
	 */
	public function ruleBase( $url, $page_id = 0, $context = null ) {
		if ( null !== $context || ! is_numeric( $page_id ) || ! (int) $page_id || '' === swiftling_writing_language() ) {
			return $url;
		}
		$original = swiftling_in_link_language(
			'',
			static function () use ( $page_id ) {
				return _get_page_link( (int) $page_id );
			}
		);
		return is_string( $original ) ? (string) preg_replace( '/\?.*/', '', $original ) : $url;
	}

	/**
	 * Returns a field the plugin makes with its name, description and choices in the mode of the moment.
	 *
	 * @param mixed $attrs The field's settings, as the plugin read them from its table.
	 * @return mixed
	 */
	public function translateField( $attrs ) {
		if ( ! is_array( $attrs ) || empty( $attrs['id'] ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $attrs;
		}
		$base = 'fields/' . (int) $attrs['id'] . '/';
		foreach ( array( 'label', 'description' ) as $part ) {
			if ( isset( $attrs[ $part ] ) && is_string( $attrs[ $part ] ) && '' !== trim( $attrs[ $part ] ) ) {
				$attrs[ $part ] = (string) swiftling_transform_text( self::FIELDS, $base . $part, self::SOURCE, $attrs[ $part ] );
			}
		}
		if ( isset( $attrs['field_data']['options'] ) && is_array( $attrs['field_data']['options'] ) ) {
			foreach ( $attrs['field_data']['options'] as $key => $choice ) {
				if ( is_string( $choice ) && '' !== trim( $choice ) ) {
					$attrs['field_data']['options'][ $key ] = (string) swiftling_transform_text( self::FIELDS, $base . 'options/' . swiftling_escape_segment( (string) $key ), self::SOURCE, $choice );
				}
			}
		}
		return $attrs;
	}

	/**
	 * Returns a value of a listing the plugin hands out in the mode of the moment: its title, short
	 * description or description, which the plugin reads from the post itself.
	 *
	 * @param mixed $value   The value.
	 * @param mixed $post_id The listing.
	 * @param mixed $field   The plugin's field.
	 * @return mixed
	 */
	public function translateValue( $value, $post_id = 0, $field = null ) {
		if ( ! is_string( $value ) || '' === trim( $value ) || ! is_object( $field ) || ! method_exists( $field, 'get_association' ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $value;
		}
		$post = get_post( is_object( $post_id ) ? (int) $post_id->ID : (int) $post_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return $value;
		}
		$owner = swiftling_post_owner( $post->ID );
		switch ( $field->get_association() ) {
			case 'title':
				return $value === $post->post_title ? (string) swiftling_filter_title( $value, $post->ID ) : $value;
			case 'excerpt':
				return $value === $post->post_excerpt ? (string) swiftling_transform( $owner, 'field/post_excerpt', 'field:post_excerpt', $value ) : $value;
			case 'content':
				return $value === $post->post_content ? (string) swiftling_translate_post_content( $owner, $value ) : $value;
		}
		return $value;
	}

	/**
	 * Declares the names, descriptions and choices of the plugin's fields as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( self::FIELDS === $owner && 0 === strpos( $path, 'fields/' ) ) {
			return true;
		}
		$notices = 'option/' . self::SETTINGS . '/' . self::NOTICES;
		if ( 'options' === $owner && 0 === strpos( $path, $notices . '/' ) ) {
			return 1 === preg_match( '#^' . preg_quote( $notices, '#' ) . '/\d+/(subject|body)$#', $path );
		}
		return parent::fieldHoldsText( $owner, $path );
	}

	/**
	 * Returns what the editor calls a text of a field: the field's name, and which text of it.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 'options' === $owner && preg_match( '#/' . self::NOTICES . '/\d+/(subject|body)$#', $path, $part ) ) {
			return 'subject' === $part[1] ? __( 'Notice about expiring › Subject', 'swiftling' ) : __( 'Notice about expiring › Text', 'swiftling' );
		}
		if ( self::FIELDS !== $owner || ! preg_match( '#^fields/(\d+)/(label|description|options)#', $path, $found ) ) {
			return parent::fieldLabel( $owner, $path );
		}
		$names = array(
			'label'       => __( 'Name', 'swiftling' ),
			'description' => __( 'Description', 'swiftling' ),
			'options'     => __( 'Choice', 'swiftling' ),
		);
		$label = $this->rows()[ (int) $found[1] ]['label'] ?? '';
		return ( '' !== $label ? $label : '#' . $found[1] ) . ' › ' . $names[ $found[2] ];
	}

	/**
	 * Returns a subject of the editor: the plugin's fields.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		if ( self::SUBJECT !== $key ) {
			return parent::subject( $key );
		}
		return array(
			'key'   => $key,
			'kind'  => 'fields',
			'title' => __( 'Fields of Business Directory', 'swiftling' ),
			'type'  => __( 'Settings', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => 'manage_options',
			'note'  => __( 'The names, descriptions and choices of the fields of every listing, as its forms and its pages show them. Each is translated once for every listing.', 'swiftling' ),
		);
	}

	/**
	 * Returns the subject of the plugin's fields, for the Templates screen.
	 *
	 * @return array
	 */
	public function subjects(): array {
		$groups = parent::subjects();
		// The plugin keeps an email in its settings only once the site's owner changed it.
		$stored = swiftling_raw_option( self::SETTINGS );
		$kept   = is_array( $stored ) && array_intersect_key( $stored, array_flip( array_merge( self::TEMPLATES, array( self::NOTICES ) ) ) );
		if ( ! $kept ) {
			$groups[0]['subjects'] = array();
		}
		if ( $this->rows() ) {
			array_unshift(
				$groups[0]['subjects'],
				array(
					'key'         => self::SUBJECT,
					'title'       => __( 'Fields', 'swiftling' ),
					'description' => __( 'Names, descriptions and choices', 'swiftling' ),
				)
			);
		}
		$groups[0]['description'] = __( 'The fields of its listings, and its emails.', 'swiftling' );
		return $groups[0]['subjects'] ? $groups : array();
	}

	/**
	 * Returns the texts of the plugin's fields for their subject, read as a page in a language reads
	 * them, so their object and path are the ones translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( self::SUBJECT !== $subject['key'] ) {
			return parent::listTexts( $subject );
		}
		$rows  = $this->rows();
		$texts = array();
		foreach ( swiftling_collect(
			function () use ( $rows ): void {
				foreach ( $rows as $row ) {
					$this->translateField( $row );
				}
			}
		) as $leaf ) {
			if ( self::FIELDS !== $leaf['o'] ) {
				continue;
			}
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'text',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns the plugin's fields as its table keeps them, by ID.
	 *
	 * @return array<int, array{id: int, label: string, description: string, field_data: array}>
	 */
	private function rows(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpbdp_form_fields';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		$rows = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, label, description, field_data FROM %i ORDER BY weight DESC, id LIMIT %d', $table, 500 ) ) as $row ) {
			$data                   = maybe_unserialize( (string) $row->field_data );
			$rows[ (int) $row->id ] = array(
				'id'          => (int) $row->id,
				'label'       => (string) $row->label,
				'description' => (string) $row->description,
				'field_data'  => is_array( $data ) ? $data : array(),
			);
		}
		return $rows;
	}
}
