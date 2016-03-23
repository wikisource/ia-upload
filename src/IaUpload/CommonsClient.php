<?php

namespace IaUpload;

use Guzzle\Common\Collection;
use Guzzle\Http\Client;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Guzzle\Plugin\Cookie\CookieJar\ArrayCookieJar;
use Guzzle\Plugin\Cookie\CookiePlugin;
use Guzzle\Plugin\Oauth\OauthPlugin;

/**
 * Client for Commons API
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class CommonsClient extends Client {

	/**
	 * @var string the edit token to use
	 */
	protected $editToken;

	public static function factory( $config = [] ) {
		$required = [
			'consumer_key',
			'consumer_secret',
			'token',
			'token_secret'
		];
		$config = Collection::fromConfig( $config, [], $required );

		$client = new self( 'https://commons.wikimedia.org/w/api.php', $config );
		$client->addSubscriber( new CookiePlugin( new ArrayCookieJar() ) );
		$client->addSubscriber( new OauthPlugin( $config->toArray() ) );

		return $client;
	}

	/**
	 * Do a GET request to the API
	 *
	 * @param string[] $params parameters to put in the query part of the url
	 * @return array the API result
	 */
	protected function apiGet( $params ) {
		$params['format'] = 'json';

		$result = $this->get( null, null, [
			'query' => $params
		] )->send()->json();

		if ( array_key_exists( 'error', $result ) ) {
			throw new ClientErrorResponseException( $result['error']['info'] );
		}
		return $result;
	}

	/**
	 * Do a POST request to the API
	 *
	 * @param string[] $params parameters to put in the query part of the url
	 * @param string[] $postFields field to put in the post request
	 * @return array the API result
	 */
	protected function apiPost( $params, $postFields ) {
		$params['format'] = 'json';

		$result = $this->post( null, null, $postFields, [
			'query' => $params
		] )->send()->json();

		if ( array_key_exists( 'error', $result ) ) {
			throw new ClientErrorResponseException( $result['error']['info'] );
		}
		return $result;
	}

	/**
	 * Returns if a given page exists
	 *
	 * @param string $pageTitle
	 * @return bool
	 */
	public function pageExist( $pageTitle ) {
		$result = $this->apiGet( [
			'action' => 'query',
			'titles' => $pageTitle,
			'prop' => 'info'
		] );
		return !isset( $result['query']['pages'][-1] );
	}

	/**
	 * Returns the edit token for the current user
	 *
	 * @return string
	 */
	public function getEditToken() {
		if ( $this->editToken !== null ) {
			return $this->editToken;
		}

		$result = $this->apiGet( [
			'action' => 'tokens',
			'type' => 'edit'
		] );
		if ( !array_key_exists( 'edittoken', $result['tokens'] ) ) {
			throw new ClientErrorResponseException( 'Edittoken retriving failure' );
		}
		return $result['tokens']['edittoken'];
	}

	/**
	 * Returns the edit token for the current user
	 *
	 * @param string $fileName the name of the file to upload
	 * @param string $filePath the path to the file
	 * @param string $text the content of the description page
	 * @param string $comment an edit comment
	 * @return array
	 */
	public function upload( $fileName, $filePath, $text, $comment ) {
		if ( $this->editToken !== null ) {
			return $this->editToken;
		}

		$params = [
			'action' => 'upload',
			'filename' => $fileName
		];
		$post = [
			'text' => $text,
			'file' => '@' . $filePath,
			'comment' => $comment,
			'token' => $this->getEditToken()
		];
		$result = $this->apiPost( $params, $post );
		if ( array_key_exists( 'warnings', $result ) ) {
			throw new ClientErrorResponseException( $result['warnings'], implode( ' | ', $result['warnings'] ) );
		}
		return $result;
	}

	/**
	 * Normalize a page title
	 *
	 * @param string $title
	 * @return string
	 */
	public function normalizePageTitle( $title ) {
		return str_replace( [ ' ', "\t", "\n" ], [ '_', '_', '_' ], $title );
	}

	/**
	 * Check if the page title is valid
	 *
	 * @param string $title
	 * @return bool
	 */
	public function isPageTitleValid( $title ) {
		return strpos( $title, ':' ) === false && strpos( $title, '/' ) === false;
	}
}
