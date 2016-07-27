<?php

namespace IaUpload\OAuth\Token;

use JsonSerializable;
use Serializable;

/**
 * @since 0.1
 *
 * @author Thomas Pellissier Tanon
 *
 * An OAuth token
 */
abstract class Token implements JsonSerializable, Serializable {

	/**
	 * @var string
	 */
	public $key;

	/**
	 * @var string
	 */
	public $secret;

	/**
	 * @param string $key The token
	 * @param string $secret The token secret
	 */
	public function __construct( $key, $secret ) {
		$this->key = $key;
		$this->secret = $secret;
	}

	/**
	 * @return string
	 */
	public function getKey() {
		return $this->key;
	}

	/**
	 * @return string
	 */
	public function getSecret() {
		return $this->secret;
	}

	public function jsonSerialize() {
		return [
			'key' => $this->key,
			'secret' => $this->secret
		];
	}

	public function serialize() {
		return json_encode( $this );
	}

	public function unserialize( $serialized ) {
		$content = json_decode( $serialized, true );
		$this->key = $content['key'];
		$this->secret = $content['secret'];
	}
}
