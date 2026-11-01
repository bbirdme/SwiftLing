<?php
/**
 * A stand-in for the parts of MailPoet that SwiftLing calls: its container of services and its renderer
 * of the blocks of a form, which, as the plugin's, makes up a random name for the errors of each field and
 * counts the IDs of checkboxes through the request each time it renders a block.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound, Generic.Files.OneObjectStructurePerFile, Universal.Namespaces.OneDeclarationPerFile, Universal.Namespaces.DisallowCurlyBraceSyntax, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- The plugin's own names, in one file for the tests.

namespace MailPoet\DI {

	/**
	 * The plugin's container of services.
	 */
	class ContainerWrapper {
		/**
		 * Returns the container.
		 */
		public static function getInstance() {
			return new self();
		}

		/**
		 * Returns a service.
		 *
		 * @param string $id The service's class.
		 */
		public function get( $id ) {
			return new $id();
		}
	}
}

namespace MailPoet\Form {

	/**
	 * The plugin's renderer of forms.
	 */
	class Renderer {
		/**
		 * Renders blocks, also those in columns.
		 *
		 * @param array    $blocks   Blocks.
		 * @param array    $settings The form's settings.
		 * @param int|null $form_id  Form ID.
		 */
		public function renderBlocks( array $blocks = array(), array $settings = array(), $form_id = null ) {
			$html = '';
			foreach ( $blocks as $block ) {
				if ( isset( $block['body'] ) ) {
					$html .= '<div class="mailpoet_form_column">' . $this->renderBlocks( $block['body'], $settings, $form_id ) . '</div>';
					continue;
				}
				$params = $block['params'] ?? array();
				if ( isset( $params['content'] ) ) {
					$html .= '<p class="mailpoet_form_paragraph">' . $params['content'] . '</p>';
					continue;
				}
				$label = (string) ( $params['label'] ?? '' );
				$html .= '<label>' . esc_html( $label ) . '</label><input id="' . wp_unique_id( 'mailpoet_checkbox_' ) . '" placeholder="' . esc_attr( $label ) . '" value="' . esc_attr( (string) ( $params['value'] ?? '' ) ) . '" data-parsley-errors-container=".mailpoet_error_' . wp_generate_password( 5, false ) . '"/>';
			}
			return $html;
		}
	}
}
