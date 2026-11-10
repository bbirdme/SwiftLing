<?php
/**
 * GeoDirectory: what of a listing SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * GeoDirectory. A listing is a post of one of the plugin's types, as "gd_place", with a row of its own
 * table, "geodir_<type>_detail", that holds the values of its custom fields. Its title, description,
 * categories and tags, and the names of its fields, which the plugin prints through its language pack,
 * SwiftLing finds by itself. What it does not:
 *
 * - The values of a listing's text fields, as its opening hours or special offers, are read from the
 *   plugin's table: put in the mode of the moment where the plugin loads a listing, see
 *   translateListing(), as texts of the listing's post, "geodirectory/<field>", so its editor offers
 *   them. The plugin keeps the listing it loaded in the object cache: a page in a language and a page
 *   check keep their own for the request, see start().
 * - The names of the tabs of a listing's page are rows of the plugin's table "geodir_tabs_layout", which
 *   the plugin hands to its language pack: texts of their own, "geodirectory:tabs", translated where
 *   the plugin reads them, see translateTabs(), and offered with its settings.
 * - A listing keeps the language of the page it was posted or edited on, see keepLanguage(). The
 *   plugin writes its emails to the listing's owner, as that it is published or that someone reviewed
 *   it, in that language, and those to the site's owner in the original, see startEmail(). Their
 *   subjects and texts, when the site's owner wrote their own in the plugin's settings, are declared as
 *   text and offered on the Templates screen, see textOptions(); the plugin read its settings before
 *   SwiftLing started, so they are put in place where it asks for one, see emailText().
 * - The plugin's forms and lists send to admin-ajax.php from the page: answered in the language of that
 *   page, see sendingActions().
 * - A listing gets no separate copy: a copy would have no row in the plugin's table. It is translated
 *   in place, see noCopyTypes().
 *
 * Checked against the code of GeoDirectory 2.8 (the free plugin), in tests/JobBoardsTest.php, and by
 * hand on a site with the plugin. Its paid add-ons, as events, claims and pricing, were not checked.
 */
class GeoDirectory extends SettingsTexts {
	/**
	 * Where the texts of a listing's fields are in the map of its post.
	 */
	const BASE = 'geodirectory';

	/**
	 * Source of a text of a listing's field, for its rule key.
	 */
	const SOURCE = 'geodirectory:field';

	/**
	 * Where a listing keeps the language it was posted in.
	 */
	const LANGUAGE_META = 'swiftling_language';

	/**
	 * The object key of the names of the tabs of the plugin's listings.
	 */
	const TABS = 'geodirectory:tabs';

	/**
	 * The option of the plugin's settings.
	 */
	const SETTINGS = 'geodir_settings';

	/**
	 * The types of field whose values are text a visitor reads.
	 */
	const TEXT_FIELDS = array( 'text', 'textarea', 'html' );

	/**
	 * The plugin's emails to a listing's owner, whose subject and text its settings can keep.
	 */
	const OWNER_EMAILS = array( 'user_publish_post', 'user_pending_post', 'owner_comment_submit', 'owner_comment_approved' );

	/**
	 * The plugin's emails to the site's owner.
	 */
	const SITE_EMAILS = array( 'admin_pending_post', 'admin_post_edit' );

	/**
	 * The plugin's groups of the object cache with a listing it loaded.
	 */
	const CACHE_GROUPS = array( 'gd_post' );

	/**
	 * The actions of admin-ajax.php the plugin sends to from a page.
	 */
	const ACTIONS = array( 'geodir_search_form', 'geodir_save_post', 'geodir_auto_save_post', 'geodir_get_category_select', 'geodir_cpt_categories', 'geodir_cpt_tags', 'geodir_widget_listings', 'geodir_recently_viewed_listings', 'geodir_report_post_form', 'geodir_submit_report_post' );

	/**
	 * The text fields of each of the plugin's types: their names, by the name of their column.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $fields = array();

	/**
	 * The emails being written: whether this class changed the language for each.
	 *
	 * @var bool[]
	 */
	private $writing = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'geodirectory';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'GeoDirectory';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'GEODIRECTORY_VERSION' );
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
	 * Returns the plugin's types of listing: translated in place.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return function_exists( 'geodir_get_posttypes' ) ? array_values( array_map( 'strval', (array) geodir_get_posttypes() ) ) : array( 'gd_place' );
	}

	/**
	 * Returns the actions of admin-ajax.php the plugin sends to from a page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return self::ACTIONS;
	}

	/**
	 * Returns the values of the plugin's settings that hold text: the subject and text of each email to
	 * a listing's owner the site's owner wrote, and the footer of every email.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$paths = array( 'email_footer_text' );
		foreach ( self::OWNER_EMAILS as $email ) {
			$paths[] = 'email_' . $email . '_subject';
			$paths[] = 'email_' . $email . '_body';
		}
		return array( self::SETTINGS => $paths );
	}

	/**
	 * Returns the sentence the editor shows above the texts of the plugin's settings.
	 */
	protected function settingsNote(): string {
		return __( 'The names of the tabs of a listing\'s page, and the subjects and texts of the emails to the owners of listings when you wrote your own in GeoDirectory\'s settings; the plugin\'s own are translated by its language pack. Each email goes out in the language the listing was posted in. Keep every placeholder, such as [#listing_link#]: the plugin fills it in.', 'swiftling' );
	}

	/**
	 * Whether a value is one text as a whole: the text of an email, with the plugin's placeholders.
	 *
	 * @param string $path Path of the value.
	 */
	public function isWhole( string $path ): bool {
		return 1 === preg_match( '#^option/' . self::SETTINGS . '/email_[a-z_]+_body$#', $path );
	}

	/**
	 * Hooks where the plugin loads a listing, hands out a setting, saves a listing and writes an email.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		add_filter( 'geodir_get_post_info', array( $this, 'translateListing' ), 10, 2 );
		add_filter( 'geodir_get_option', array( $this, 'emailText' ), 10, 2 );
		add_filter( 'geodir_tab_settings', array( $this, 'translateTabs' ), 10, 1 );
		add_action( 'geodir_post_saved', array( $this, 'keepLanguage' ), 10, 3 );
		foreach ( array_merge( self::OWNER_EMAILS, self::SITE_EMAILS ) as $email ) {
			add_action( 'geodir_pre_' . $email . '_email', array( $this, 'startEmail' ), 1, 2 );
			add_action( 'geodir_post_' . $email . '_email', array( $this, 'endEmail' ), PHP_INT_MAX, 0 );
		}
		// phpcs:enable
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * In a page check and a page in a language, keeps the listings the plugin loads in the object cache
	 * for the request only: one translated must not be served to another language.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		unset( $probe );
		if ( 'control' !== $mode ) {
			wp_cache_add_non_persistent_groups( self::CACHE_GROUPS );
		}
	}

	/**
	 * Returns a listing the plugin loaded with the values of its text fields in the mode of the moment.
	 *
	 * @param mixed $listing The plugin's object of the listing: its post and its row.
	 * @param mixed $post_id The listing.
	 * @return mixed
	 */
	public function translateListing( $listing, $post_id = 0 ) {
		if ( ! is_object( $listing ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $listing;
		}
		$id   = (int) ( $listing->ID ?? $post_id );
		$type = (string) ( $listing->post_type ?? get_post_type( $id ) );
		if ( ! $id ) {
			return $listing;
		}
		foreach ( array_keys( $this->textFields( $type ) ) as $column ) {
			if ( isset( $listing->$column ) && is_string( $listing->$column ) && '' !== trim( $listing->$column ) ) {
				$path             = self::BASE . '/' . $column;
				$listing->$column = (string) swiftling_transform_text( swiftling_post_owner( $id ), $path, self::SOURCE, $listing->$column );
			}
		}
		return $listing;
	}

	/**
	 * Returns the tabs of a listing's page with their names in the mode of the moment: the site's owner
	 * names them in the plugin's settings, and the plugin hands each name to its language pack, which has
	 * only its own.
	 *
	 * @param mixed $tabs The tabs.
	 * @return mixed
	 */
	public function translateTabs( $tabs ) {
		if ( ! is_array( $tabs ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $tabs;
		}
		foreach ( $tabs as $tab ) {
			if ( is_object( $tab ) && ! empty( $tab->id ) && isset( $tab->tab_name ) && is_string( $tab->tab_name ) && '' !== trim( $tab->tab_name ) ) {
				$tab->tab_name = (string) swiftling_transform_text( self::TABS, 'tabs/' . (int) $tab->id . '/name', self::SOURCE, $tab->tab_name );
			}
		}
		return $tabs;
	}

	/**
	 * Reads the names of the tabs of every type of listing, so the subject of the settings offers them.
	 */
	protected function readMoreTexts(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'geodir_tabs_layout';
		if ( ! $this->hasTable( $table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
		$this->translateTabs( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, tab_name FROM %i ORDER BY post_type, sort_order LIMIT %d', $table, 500 ) ) );
	}

	/**
	 * Returns the text fields of one of the plugin's types: their names, by the name of their column.
	 *
	 * @param string $type Post type.
	 * @return array<string, string>
	 */
	private function textFields( string $type ): array {
		if ( ! isset( $this->fields[ $type ] ) ) {
			global $wpdb;
			$this->fields[ $type ] = array();
			$table                 = $wpdb->prefix . 'geodir_custom_fields';
			if ( $this->hasTable( $table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once a request.
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT htmlvar_name, field_type, frontend_title FROM %i WHERE post_type = %s AND is_active = 1', $table, $type ) );
				foreach ( (array) $rows as $row ) {
					$column = (string) $row->htmlvar_name;
					// A field that holds a number holds no letters, and is left as it is.
					if ( in_array( (string) $row->field_type, self::TEXT_FIELDS, true ) && 0 !== strpos( $column, 'post_' ) ) {
						$this->fields[ $type ][ $column ] = (string) $row->frontend_title;
					}
				}
			}
		}
		return $this->fields[ $type ];
	}

	/**
	 * Declares the values of a listing's text fields as text, beside the texts of the plugin's settings.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( self::TABS === $owner || ( 0 === strpos( $owner, 'post:' ) && 0 === strpos( $path, self::BASE . '/' ) ) ) {
			return true;
		}
		return parent::fieldHoldsText( $owner, $path );
	}

	/**
	 * Returns what the editor calls a value of a listing's field: the field's name.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( self::TABS === $owner ) {
			return __( 'Name of a tab of a listing\'s page', 'swiftling' );
		}
		if ( 0 === strpos( $owner, 'post:' ) && 0 === strpos( $path, self::BASE . '/' ) ) {
			$fields = $this->textFields( (string) get_post_type( (int) substr( $owner, 5 ) ) );
			$column = substr( $path, strlen( self::BASE ) + 1 );
			return isset( $fields[ $column ] ) && '' !== $fields[ $column ] ? $fields[ $column ] : $column;
		}
		return parent::fieldLabel( $owner, $path );
	}

	/**
	 * Returns a text of the plugin's settings the plugin asks for in the mode of the moment: it read its
	 * settings before SwiftLing started.
	 *
	 * @param mixed  $value The setting, as the plugin read it.
	 * @param string $key   Which setting.
	 * @return mixed
	 */
	public function emailText( $value, $key = '' ) {
		if ( ! is_string( $value ) || ! in_array( $key, $this->textOptions()[ self::SETTINGS ], true ) ) {
			return $value;
		}
		return $this->optionText( self::SETTINGS, $key, $value );
	}

	/**
	 * Keeps the language of the page a listing was posted or edited on; one in the original keeps none.
	 * Saved in the admin, a listing keeps the language it had.
	 *
	 * @param mixed $postarr The listing's data.
	 * @param mixed $gd_post The listing's row.
	 * @param mixed $post    The listing's post.
	 */
	public function keepLanguage( $postarr, $gd_post = null, $post = null ): void {
		unset( $postarr, $gd_post );
		if ( ! $post instanceof \WP_Post || ( is_admin() && ! wp_doing_ajax() ) ) {
			return;
		}
		$slug = swiftling_writing_language();
		if ( '' === $slug ) {
			delete_post_meta( $post->ID, self::LANGUAGE_META );
		} else {
			update_post_meta( $post->ID, self::LANGUAGE_META, $slug );
		}
	}

	/**
	 * Before the plugin writes an email: one to the site's owner in the original, one to a listing's
	 * owner in the language of the listing. Until endEmail().
	 *
	 * @param mixed $email_name Which email.
	 * @param mixed $email_vars What it is about: the listing, or a review of it.
	 */
	public function startEmail( $email_name, $email_vars = array() ): void {
		$slug = '';
		if ( in_array( $email_name, self::OWNER_EMAILS, true ) ) {
			$post = is_array( $email_vars ) && isset( $email_vars['post'] ) && is_object( $email_vars['post'] ) ? (int) $email_vars['post']->ID : 0;
			if ( ! $post && is_array( $email_vars ) && isset( $email_vars['comment'] ) && is_object( $email_vars['comment'] ) ) {
				$post = (int) $email_vars['comment']->comment_post_ID;
			}
			$slug = $post ? (string) get_post_meta( $post, self::LANGUAGE_META, true ) : '';
			$slug = null !== swiftling_target_language( $slug ) ? $slug : '';
		}
		$changed = swiftling_writing_language() !== $slug;
		if ( $changed ) {
			swiftling_write_in( $slug );
		}
		$this->writing[] = $changed;
	}

	/**
	 * After the plugin sent an email: the request goes on in its own language.
	 */
	public function endEmail(): void {
		if ( array_pop( $this->writing ) ) {
			swiftling_resume_translation();
		}
	}

	/**
	 * Keeps the placeholders of the plugin's emails, as [#listing_link#], as they are when a text goes
	 * to machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, '/\[#[a-z_]+#\]/' );
	}
}
