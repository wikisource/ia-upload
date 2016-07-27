<?php

namespace IaUpload;

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
	 * @var MediawikiApi
	 */
	private $mediawikiApi;

	public function __construct( MediawikiApi $mediawikiApi ) {
		$this->mediawikiApi = $mediawikiApi;
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
		$params = [
			'action' => 'upload',
			'filename' => $fileName,
			'text' => $text,
			'file' => fopen( $filePath, 'r' ),
			'comment' => $comment,
			'token' => $this->mediawikiApi->getToken( 'edit' )
		];
		$result = $this->mediawikiApi->postRequest( new SimpleRequest( 'upload', $params ) );
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
