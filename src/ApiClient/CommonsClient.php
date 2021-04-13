<?php

namespace Wikisource\IaUpload\ApiClient;

use GuzzleHttp\Client;
use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\SimpleRequest;
use Psr\Log\LoggerInterface;

/**
 * Client for Commons API
 *
 * @file
 * @ingroup IaUpload
 *
 * @license GPL-2.0-or-later
 */
class CommonsClient {

	/** @var string */
	private $baseUrl;

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
	 * @param string $baseUrl The wiki base URL with no trailing slash or path.
	 * @param Client $oauthClient The Oauth client.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct( string $baseUrl, Client $oauthClient, LoggerInterface $logger ) {
		$this->baseUrl = rtrim( $baseUrl, '/' );
		$this->client = $oauthClient;
		$this->mediawikiApi = new MediawikiApi( $this->baseUrl . '/w/api.php', $oauthClient );
		$this->mediawikiApi->setLogger( $logger );
	}

	/**
	 * Get an HTML anchor element linking to the given page on Wikimedia Commons.
	 *
	 * @param string $pageName
	 * @return string
	 */
	public function getHtmlLink( string $pageName ): string {
		$url = $this->baseUrl . '/wiki/' . rawurlencode( $pageName );
		return '<a href="' . $url . '">' . htmlspecialchars( $pageName ) . '</a>';
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
	 * Returns page corresponding to IA item (if any)
	 *
	 * @param string $identifier The IA identifier.
	 * @return string
	 */
	public function pageForIAItem( $identifier ) {
		$result = $this->mediawikiApi->getRequest( new SimpleRequest( 'query', [
			'list' => 'iwbacklinks',
			'iwblprefix' => 'iarchive',
			'iwbltitle' => $identifier
		] ) );
		return empty( $result['query']['iwbacklinks'] )
			? ""
			: $result['query']['iwbacklinks'][0]['title'];
	}

	/**
	 * Upload a file to Commons.
	 *
	 * @param string $fileName the name of the file to upload
	 * @param string $filePath the path to the file
	 * @param string $text the content of the description page
	 * @param string $comment an edit comment
	 * @return array
	 */
	public function upload( string $fileName, string $filePath, string $text, string $comment ) {
		$fileUploader = new CommonsFileUploader( $this->mediawikiApi );
		return $fileUploader->uploadWithResult( $fileName, $filePath, $text, $comment );
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
