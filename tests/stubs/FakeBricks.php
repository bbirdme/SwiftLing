<?php
/**
 * Bricks without the theme, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Bricks;

require_once __DIR__ . '/FakeBricksDatabase.php';

/**
 * Bricks, without the theme: active, with the template post type it registers and some of its
 * elements, each with the controls Bricks 2.4.4 declares for it, and everything else of the real
 * class.
 */
class FakeBricks extends Bricks {
	/**
	 * Elements by name, as Bricks\Elements::get_element() returns them: label, controls and groups
	 * of controls. Copied from Bricks 2.4.4, only the keys this class reads.
	 */
	public const ELEMENTS = array(
		'heading'           => array(
			'label'    => 'Heading',
			'controls' => array(
				'text'        => array(
					'tab'  => 'content',
					'type' => 'text',
				),
				'tag'         => array(
					'tab'  => 'content',
					'type' => 'select',
				),
				'_cssId'      => array(
					'tab'   => 'style',
					'group' => '_css',
					'type'  => 'text',
				),
				'_cssClasses' => array(
					'tab'   => 'style',
					'group' => '_css',
					'type'  => 'text',
				),
				'_typography' => array(
					'tab'   => 'style',
					'group' => '_typography',
					'type'  => 'typography',
					'css'   => array( array( 'property' => 'font' ) ),
				),
				'_attributes' => array(
					'tab'    => 'style',
					'group'  => '_attributes',
					'type'   => 'repeater',
					'fields' => array(
						'name'  => array( 'type' => 'text' ),
						'value' => array( 'type' => 'text' ),
					),
				),
			),
		),
		'text-basic'        => array(
			'label'    => 'Basic Text',
			'controls' => array(
				'text' => array(
					'tab'  => 'content',
					'type' => 'textarea',
				),
			),
		),
		'block'             => array(
			'label'    => 'Block',
			'controls' => array(),
		),
		'animated-typing'   => array(
			'label'    => 'Anim. Typing',
			'controls' => array(
				'prefix'     => array(
					'tab'  => 'content',
					'type' => 'text',
				),
				'suffix'     => array(
					'tab'  => 'content',
					'type' => 'text',
				),
				'strings'    => array(
					'tab'    => 'content',
					'type'   => 'repeater',
					'fields' => array(
						'text' => array(
							'label' => 'Text',
							'type'  => 'text',
						),
					),
				),
				'cursorChar' => array(
					'tab'  => 'content',
					'type' => 'text',
				),
			),
		),
		'post-reading-time' => array(
			'label'    => 'Reading time',
			'controls' => array(
				'prefix' => array( 'type' => 'text' ),
				'suffix' => array( 'type' => 'text' ),
			),
		),
		'video'             => array(
			'label'    => 'Video',
			'controls' => array(
				'youTubeId' => array(
					'tab'  => 'content',
					'type' => 'text',
				),
			),
		),
		'code'              => array(
			'label'    => 'Code',
			'controls' => array(
				'code' => array(
					'tab'  => 'content',
					'type' => 'code',
					'mode' => 'php',
				),
				'css'  => array(
					'tab'  => 'content',
					'type' => 'code',
					'mode' => 'css',
				),
			),
		),
		'html'              => array(
			'label'    => 'HTML',
			'controls' => array(
				'html' => array(
					'tab'  => 'content',
					'type' => 'code',
					'mode' => 'text/html',
				),
			),
		),
		'countdown'         => array(
			'label'    => 'Countdown',
			'controls' => array(
				'fields' => array(
					'tab'    => 'content',
					'type'   => 'repeater',
					'fields' => array(
						'format' => array( 'type' => 'text' ),
					),
				),
			),
		),
		'form'              => array(
			'label'         => 'Form',
			'controls'      => array(
				'fields'                  => array(
					'tab'    => 'content',
					'group'  => 'fields',
					'type'   => 'repeater',
					'fields' => array(
						'label'       => array(
							'label' => 'Label',
							'type'  => 'text',
						),
						'placeholder' => array(
							'label' => 'Placeholder',
							'type'  => 'text',
						),
						'name'        => array(
							'label' => 'Attribute: Name',
							'type'  => 'text',
						),
						'format'      => array(
							'label' => 'Date format',
							'type'  => 'text',
						),
					),
				),
				'actions'                 => array(
					'tab'      => 'content',
					'group'    => 'actions',
					'type'     => 'select',
					'multiple' => true,
				),
				'successMessage'          => array(
					'tab'   => 'content',
					'group' => 'actions',
					'label' => 'Success message',
					'type'  => 'text',
				),
				'emailSubject'            => array(
					'tab'   => 'content',
					'group' => 'email',
					'label' => 'Subject',
					'type'  => 'text',
				),
				'emailContent'            => array(
					'tab'   => 'content',
					'group' => 'email',
					'label' => 'Email content',
					'type'  => 'textarea',
				),
				'fromEmail'               => array(
					'tab'   => 'content',
					'group' => 'email',
					'label' => 'From email address',
					'type'  => 'text',
				),
				'mailchimpPendingMessage' => array(
					'tab'      => 'content',
					'group'    => 'mailchimp',
					'label'    => 'Pending message',
					'type'     => 'text',
					'required' => array( 'apiKeyMailchimp', '!=', '', 'globalSettings' ),
				),
				'redirectTimeout'         => array(
					'tab'   => 'content',
					'group' => 'redirect',
					'type'  => 'number',
				),
			),
			'controlGroups' => array(
				'fields'    => array( 'title' => 'Fields' ),
				'actions'   => array( 'title' => 'Actions' ),
				'email'     => array(
					'title'    => 'Email',
					'required' => array( 'actions', '=', 'email' ),
				),
				'mailchimp' => array(
					'title'    => 'Mailchimp',
					'required' => array( 'actions', '=', 'mailchimp' ),
				),
				'redirect'  => array(
					'title'    => 'Redirect',
					'required' => array( 'actions', '=', 'redirect' ),
				),
			),
		),
	);

	/**
	 * Whether Bricks counts as the active theme.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the post type of Bricks' templates as Bricks 2.4.4 does: public, so a template can be
	 * opened on its own to edit it, but left out of search.
	 */
	public static function install(): void {
		register_post_type(
			'bricks_template',
			array(
				'public'              => true,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Returns an element as Bricks declares it.
	 *
	 * @param string $name Element name.
	 * @return array|null
	 */
	protected function element( string $name ): ?array {
		return isset( self::ELEMENTS[ $name ] ) ? self::ELEMENTS[ $name ] : null;
	}
}
