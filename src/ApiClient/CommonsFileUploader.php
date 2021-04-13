<?php

namespace Wikisource\IaUpload\ApiClient;

use Mediawiki\Api\MediawikiApi;
use Mediawiki\Api\Service\FileUploader;
use Mediawiki\Api\SimpleRequest;

/**
 * A wrapper for FileUploader::upload() that lets us get the API response.
 * @TODO Remove after https://github.com/addwiki/addwiki/issues/86 is resolved.
 */
class CommonsFileUploader extends FileUploader {

	/**
	 * @param MediawikiApi $api
	 */
	public function __construct( MediawikiApi $api ) {
		parent::__construct( $api );
		$this->setChunkSize( 90 * 1024 * 1024 );
	}

	/**
	 * @param string $targetName
	 * @param string $location
	 * @param string $text
	 * @param string $comment
	 * @return mixed
	 */
	public function uploadWithResult( string $targetName, string $location, string $text = '', string $comment = '' ) {
		$params = [
			'filename' => $targetName,
			'token' => $this->api->getToken(),
			'text' => $text,
			'comment' => $comment,
			'filesize' => filesize( $location ),
			'file' => fopen( $location, 'r' ),
		];
		$params = $this->uploadByChunks( $params );
		return $this->api->postRequest( new SimpleRequest( 'upload', $params ) );
	}
}
