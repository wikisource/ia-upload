<?php

namespace Wikisource\IaUpload\Intuition;

use Krinkle\Intuition\Intuition;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TwigExtension extends AbstractExtension
{

	/** @var Intuition */
	private $intuition;

	public function __construct(Intuition $intuition)
	{
		$this->intuition = $intuition;
	}

	public function getFilters() {
		return array(
			new TwigFilter(
				'message', array( $this, 'messageFilterCallback' )
			),
		);
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
	 * @param string ... Message params
	 * @return string Unescaped message content
	 * @see I18nContext::message
	 */
	public function messageFilterCallback( $key /*...*/ ) {
		$params = func_get_args();
		array_shift( $params );
		if ( count( $params ) == 1 && is_array( $params[0] ) ) {
			// Unwrap args array
			$params = $params[0];
		}
		return $this->intuition->msg( $key, [ 'variables' => $params ] );
	}
}