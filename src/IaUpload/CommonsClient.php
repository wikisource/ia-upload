<?php

namespace IaUpload;

use Exception;
use GuzzleHttp\Client;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;
use Mediawiki\Api\UsageException;
use Psr\Log\LoggerInterface;

/**
 * Client for Commons API
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class CommonsClient {

	/**
	 * @var Client
	 */
	private $client;

	/**
	 * @var MediawikiApi
	 */
	private $mediawikiApi;

	public function __construct( Client $oauthClient, LoggerInterface $logger ) {
		$this->client = $oauthClient;
		$this->mediawikiApi = new MediawikiApi( 'https://commons.wikimedia.org/w/api.php', $oauthClient );
		$this->mediawikiApi->setLogger( $logger );
	}

	/**
	 * Can the current user upload files?
	 * @return boolean
	 */
	public function canUpload() {
		$result = $this->mediawikiApi->getRequest( new SimpleRequest( 'query', [
			'meta' => 'userinfo',
			'uiprop' => 'rights'
		] ) );
		return in_array( 'upload', $result['query']['userinfo']['rights'] );
	}

	/**
	 * Returns if a given page exists
	 *
	 * @param string $pageTitle
	 * @return bool
	 */
	public function pageExist( $pageTitle ) {
		$result = $this->mediawikiApi->getRequest( new SimpleRequest( 'query', [
			'titles' => $pageTitle,
			'prop' => 'info'
		] ) );
		return !isset( $result['query']['pages'][-1] );
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
		$result = json_decode( $this->client->post( 'https://commons.wikimedia.org/w/api.php', [
			'multipart' => [
				[ 'name' => 'action', 'contents' => 'upload' ],
				[ 'name' => 'format', 'contents' => 'json' ],
				[ 'name' => 'filename', 'contents' => $fileName ],
				[ 'name' => 'text', 'contents' => $text ],
				[ 'name' => 'file', 'contents' => fopen( $filePath, 'r' ) ],
				[ 'name' => 'comment', 'contents' => $comment ],
				[ 'name' => 'token', 'contents' => $this->mediawikiApi->getToken( 'edit' ) ]
			]
		] )->getBody(), true );
		if ( array_key_exists( 'error', $result ) ) {
			throw new UsageException( $result['error']['code'], $result['error']['info'], $result );
		}
		if ( array_key_exists( 'warnings', $result ) ) {
			foreach ( $result['warnings'] as $module => $warningData ) {
				$warning = is_array( $warningData ) ? join( "\n", $warningData ) : $warningData;
				throw new UsageException( $module, $warning, $result );
			}
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
		$request = new SimpleRequest( 'query', [ 'titles' => trim( $title ) ] );
		$result = $this->mediawikiApi->getRequest( $request );
		if ( !isset( $result['query']['normalized'][0]['to'] ) ) {
			return $title;
		}
		return $result['query']['normalized'][0]['to'];
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
