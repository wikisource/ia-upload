<?php

namespace IaUpload;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;

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

	public function __construct( Client $oauthClient ) {
	    $this->client = $oauthClient;
		$this->mediawikiApi = new MediawikiApi( 'https://commons.wikimedia.org/w/api.php', $oauthClient );
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
		if ( array_key_exists( 'warnings', $result ) ) {
			throw new TransferException( $result['warnings'], implode( ' | ', $result['warnings'] ) );
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
