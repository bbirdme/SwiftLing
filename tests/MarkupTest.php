<?php
/**
 * Tests of the HTML handling every translation service can use.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Translation\Markup;

/**
 * Tags with attributes go to a service as short numbered tags and come back whole; parts to keep go
 * in spans a service leaves alone; plain text travels as HTML and comes back plain.
 */
class MarkupTest extends TestCase {

	/**
	 * Attributes stay on the site; a translation with the numbered tags gets them back, and one that
	 * lost or doubled a tag is refused.
	 */
	public function test_tags_with_attributes() {
		list( $sent, $tags ) = Markup::protect( 'Read <a href="https://example.org/a/" class="x">this</a> <strong>now</strong>.' );
		$this->assertSame( 'Read <a i="0">this</a> <strong>now</strong>.', $sent );
		$this->assertSame( 'Lies <a href="https://example.org/a/" class="x">das</a> <strong>jetzt</strong>.', Markup::restore( 'Lies <a i="0">das</a> <strong>jetzt</strong>.', $tags ) );
		$this->assertNull( Markup::restore( 'Lies das jetzt.', $tags ), 'Lost' );
		$this->assertNull( Markup::restore( 'Lies <a i="0">das</a> <a i="0">jetzt</a>.', $tags ), 'Twice' );
	}

	/**
	 * Parts to keep travel in spans marked translate="no" and come back out of them; a translation
	 * that changed one is refused.
	 */
	public function test_parts_to_keep() {
		list( $sent, $parts ) = Markup::keep( 'Hello %1$s at Lavanda', array( '%1$s', 'Lavanda' ) );
		$this->assertSame( 'Hello <span translate="no">%1$s</span> at <span translate="no">Lavanda</span>', $sent );
		$this->assertSame( 'Hallo %1$s bei Lavanda', Markup::unkeep( 'Hallo <span translate="no">%1$s</span> bei <span translate="no">Lavanda</span>', $parts ) );
		$this->assertNull( Markup::unkeep( 'Hallo <span translate="no">% 1 $ s</span> bei <span translate="no">Lavanda</span>', $parts ) );
	}

	/**
	 * Plain text goes as HTML, with line breaks as <br>, and comes back plain.
	 */
	public function test_plain_text() {
		$this->assertSame( 'A &lt;b&gt; &amp; "c"<br>d', Markup::plainToHtml( "A <b> & \"c\"\nd" ) );
		$this->assertSame( "A <b> & \"c\"\nd", Markup::htmlToPlain( 'A &lt;b&gt; &amp; "c"<br>d' ) );
	}
}
