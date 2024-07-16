<?php

namespace Wikisource\IaUpload\Middleware;

use Krinkle\Intuition\Intuition;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 */
class TwigMessageExtension extends AbstractExtension {

	/**
	 * @var Intuition
	 */
	private Intuition $intuition;

	/**
	 * @param Intuition $intuition
	 */
	public function __construct( Intuition $intuition ) {
		$this->intuition = $intuition;
	}

	/**
	 * @inheritDoc
	 */
	public function getFilters() {
		return [
			new TwigFilter( 'message', [ $this, 'messageFilterCallback' ] ),
		];
	}

	/**
	 * Callback for 'message' filter.
	 *
	 * <code>
	 * {{ 'my-message-key'|message }}
	 * {{ 'my-message-key'|message( 'foo', 'bar' ) }}
	 * {{ 'my-message-key'|message( [ 'foo', 'bar' ] ) }}
	 * {{ 'my-message-key'|message( 'foo', 'bar' )|raw }}
	 * </code>
	 *
	 * @param string $key Message key
	 * @return string Unescaped message content
	 */
	public function messageFilterCallback( $key ) {
		$params = func_get_args();
		array_shift( $params );
		if ( count( $params ) == 1 && is_array( $params[0] ) ) {
			// Unwrap args array
			$params = $params[0];
		}
		return $this->intuition->msg( $key, $params );
	}
}
