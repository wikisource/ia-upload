<?php

namespace Wikisource\IaUpload\ApiClient;

use GuzzleHttp\Client;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\MediawikiFactory;
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

	/**
	 * CommonsClient constructor.
	 * @param string $base_url The wiki base URL with no trailing slash or path.
	 * @param Client $oauthClient The Oauth client.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct( $base_url, Client $oauthClient, LoggerInterface $logger ) {
		$this->client = $oauthClient;
		$this->mediawikiApi = new MediawikiApi( $base_url . '/w/api.php', $oauthClient );
		$this->mediawikiApi->setLogger( $logger );
	}

	/**
	 * Can the current user upload files?
	 * @return bool
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
	 * @param string $pageTitle The page title.
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
	 * @throws UsageException If there's an API error.
	 */
	public function upload( $fileName, $filePath, $text, $comment ) {
		$factory = new MediawikiFactory( $this->mediawikiApi );
		$fileUploader = $factory->newFileUploader();
		$fileUploader->setChunkSize( 90 * 1024 * 1024 );
		$fileUploader->upload( $fileName, $filePath, $text, $comment );
	}

	/**
	 * Normalize a page title
	 *
	 * @param string $title The page title.
	 * @return string
	 */
	public function normalizePageTitle( $title ) {
		$trimmedTitle = trim( $title );
		$request = new SimpleRequest( 'query', [ 'titles' => $trimmedTitle ] );
		$result = $this->mediawikiApi->getRequest( $request );
		if ( !isset( $result['query']['normalized'][0]['to'] ) ) {
			return $trimmedTitle;
		}
		return $result['query']['normalized'][0]['to'];
	}

	/**
	 * Check if the page title is valid
	 *
	 * @param string $title The page title.
	 * @return bool
	 */
	public function isPageTitleValid( $title ) {
		return strpos( $title, ':' ) === false && strpos( $title, '/' ) === false;
	}
}
