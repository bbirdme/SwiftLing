<?php
/**
 * A stand-in for the parts of Ultimate Member that SwiftLing calls: UM() with its configuration of
 * emails and its mailer, which says before and after an email which it sends to whom, hands out the
 * subject through the plugin's filter of the setting, and the body, the template before its
 * placeholders are filled, to its filter, as Ultimate Member 2.14 does.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidFunctionName, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting -- The plugin's own names, in one file for the tests.

if ( ! function_exists( 'UM' ) ) {
	class UM_Test_Config {
		public $email_notifications = array(
			'welcome_email'         => array( 'recipient' => 'user' ),
			'notification_new_user' => array( 'recipient' => 'admin' ),
		);
	}

	class UM_Test_Mail {
		public function get_email_template( $slug, $args = array() ) {
			return 'welcome_email' === $slug ? '<div>{site_name}</div><div>Hvala na prijavi! <a href="{site_url}">Prijava</a></div>' : '<div>Novi član: <a href="{site_url}">pogledaj</a></div>';
		}

		public function send( $email, $template, $args = array() ) {
			do_action( 'um_before_email_notification_sending', $email, $template, $args );
			$options = get_option( 'um_options', array() );
			$subject = apply_filters( 'um_get_option_filter__' . $template . '_sub', $options[ $template . '_sub' ] ?? '' );
			$message = apply_filters( 'um_email_send_message_content', $this->get_email_template( $template, $args ), $template, $args );
			wp_mail( $email, $subject, str_replace( array( '{site_name}', '{site_url}' ), array( 'Vrt', $args['url'] ?? home_url( '/prijava/' ) ), $message ) );
			do_action( 'um_after_email_notification_sending', $email, $template, $args );
		}
	}

	class UM_Test_Plugin {
		public function config() {
			return new UM_Test_Config();
		}

		public function mail() {
			return new UM_Test_Mail();
		}
	}

	function UM() {
		return new UM_Test_Plugin();
	}
}
