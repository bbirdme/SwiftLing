<?php
/**
 * A stand-in for the parts of Paid Memberships Pro that SwiftLing calls: its levels, two templates of
 * emails, one to a member and one to the site's owner, and its email, which writes itself whole, hands
 * itself to the filter pmpro_email_filter and fills its placeholders once more, as Paid Memberships
 * Pro 3.9 does.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, WordPress.NamingConventions.ValidFunctionName, Generic.Classes.OpeningBraceSameLine, Squiz.Commenting, PEAR.NamingConventions.ValidClassName, WordPress.WP.I18n.TextDomainMismatch -- The plugin's own names, in one file for the tests.

if ( ! class_exists( 'PMPro_Email_Template', false ) ) {
	/**
	 * The levels of the tests, by ID, as the plugin's table has them.
	 */
	$GLOBALS['pmpro_test_levels'] = array();

	function pmpro_getLevel( $id ) {
		return $GLOBALS['pmpro_test_levels'][ (int) $id ] ?? false;
	}

	function pmpro_getAllLevels() {
		return $GLOBALS['pmpro_test_levels'];
	}

	abstract class PMPro_Email_Template {
		final public static function get_all_email_templates() {
			return apply_filters(
				'pmpro_email_templates',
				array(
					'checkout_free'       => 'PMPro_Email_Template_Checkout_Free',
					'checkout_free_admin' => 'PMPro_Email_Template_Checkout_Free_Admin',
				)
			);
		}

		final public static function get_email_template( string $template_slug ) {
			$templates = self::get_all_email_templates();
			return $templates[ $template_slug ] ?? null;
		}

		final public function send() {
			$email           = new PMProEmail();
			$email->email    = $this->get_recipient_email();
			$email->subject  = static::get_default_subject();
			$email->body     = static::get_default_body();
			$email->data     = array(
				'login_url'             => home_url( '/prijava/' ),
				'membership_id'         => 1,
				'membership_level_name' => pmpro_getLevel( 1 )->name,
			);
			$email->template = static::get_template_slug();
			return $email->sendEmail();
		}
	}

	class PMPro_Email_Template_Checkout_Free extends PMPro_Email_Template {
		protected $user;

		public function __construct( $user ) {
			$this->user = $user;
		}

		public static function get_template_slug() {
			return 'checkout_free';
		}

		public static function get_template_name() {
			return 'Checkout - Free';
		}

		public static function get_default_subject() {
			return __( 'Your membership at {{ sitename }}', 'paid-memberships-pro' );
		}

		public static function get_default_body() {
			return '<p>You are now a !!membership_level_name!!. Log in: !!login_url!!</p>';
		}

		public function get_recipient_email() {
			return $this->user->user_email;
		}
	}

	class PMPro_Email_Template_Checkout_Free_Admin extends PMPro_Email_Template_Checkout_Free {
		public static function get_template_slug() {
			return 'checkout_free_admin';
		}

		public static function get_default_body() {
			return '<p>A new !!membership_level_name!! joined. See: !!login_url!!</p>';
		}

		public function get_recipient_email() {
			return get_bloginfo( 'admin_email' );
		}
	}

	class PMProEmail {
		public $email;
		public $subject;
		public $body;
		public $template;
		public $data = array();

		public function sendEmail() {
			global $pmpro_email_templates_defaults;
			$subject = get_option( 'pmpro_email_' . $this->template . '_subject' );
			if ( ! empty( $subject ) ) {
				$this->subject = $subject;
			}
			$body = get_option( 'pmpro_email_' . $this->template . '_body' );
			if ( ! empty( $body ) ) {
				$this->body = $body;
			}
			$this->body    = $this->fill( $this->body );
			$this->subject = $this->fill( $this->subject );
			$email         = apply_filters( 'pmpro_email_filter', $this );
			if ( empty( $email ) ) {
				return false;
			}
			$header        = apply_filters( 'pmpro_email_header', $pmpro_email_templates_defaults['header']['body'], $this );
			$this->body    = $this->fill( $header . $this->body );
			$this->subject = $this->fill( $this->subject );
			return wp_mail( $this->email, $this->subject, $this->body );
		}

		private function fill( $text ) {
			foreach ( $this->data + array( 'sitename' => 'Vrt' ) as $key => $value ) {
				$text = str_replace( array( '!!' . $key . '!!', '{{ ' . $key . ' }}' ), (string) $value, $text );
			}
			return $text;
		}
	}
}
