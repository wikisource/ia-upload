<?php

namespace IaUpload;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use pastuhov\Command\Command;
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

	/**
	 * Create a new IaClient, with an HTTP client pointing to archive.org.
	 */
	public function __construct() {
		$this->client = new Client( [
			'base_uri' => 'https://archive.org'
		] );
	}

	/**
	 * Returns details of a file
	 *
	 * @param string $fileId The IA identifier.
	 * @return array|boolean The details as an array, or false if none could be retrieved.
	 */
	public function fileDetails( $fileId ) {
		try {
			$details = $this->client->get( '/details/' . rawurlencode( $fileId ), [
				'query' => [
					'output' => 'json'
				]
			] );
		} catch ( GuzzleException $e ) {
			return false;
		}
		return \GuzzleHttp\json_decode( $details->getBody(), true );
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

	/**
	 * Remove the first page of the given DjVu file.
	 * This is used to strip the Google cover page on request.
	 * @param string $djvuFile Full filesystem path to the DjVu file.
	 */
	public function removeFirstPage( $djvuFile ) {
		$cmd = "djvm -d \"$djvuFile\" 1";
		$commandOutput = Command::exec( $cmd );
	}
}
