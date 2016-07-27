<?php

namespace IaUpload;

use GuzzleHttp\Client;
use Psr\Http\Message\StreamInterface;

/**
 * Client for IA API
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class IaClient {

	/**
	 * @var Client
	 */
	private $client;

	public function __construct() {
		$this->client = new Client( [
			'base_uri' => 'https://archive.org'
		] );
	}

	/**
	 * Returns details of a file
	 *
	 * @param string $fileId
	 * @return array
	 */
	public function fileDetails( $fileId ) {
		return \GuzzleHttp\json_decode( $this->client->get( '/details/' . rawurlencode( $fileId ), [
			'query' => [
				'output' => 'json'
			]
		] )->getBody(), true );
	}

	/**
	 * Downloads an IA file
	 *
	 * @param string $fileName the name of the file on IA
	 * @param string $path the path to put the file in
	 */
	public function downloadFile( $fileName, $path ) {
		$this->streamToFile(
			$this->client->get( '/download/' . $fileName )->getBody(),
			$path
		);
	}

	private function streamToFile( StreamInterface $stream, $fileName ) {
	    $file = fopen( $fileName, 'w' );
	    while ( !$stream->eof() ) {
	        fwrite( $file, $stream->read( 1024 ) );
		   }
		fclose( $file );
	}
}
