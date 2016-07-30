<?php

namespace IaUpload;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Psr\Http\Message\StreamInterface;

/**
 * Client for the API converting IA PDF to Djvu
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class IaDjvuClient {

	/**
	 * @var Client
	 */
	private $client;

	public function __construct() {
		$this->client = new Client( [
			'base_uri' => 'http://tools.wmflabs.org/phetools/pdf_to_djvu_cgi.py'
		] );
	}

	/**
	 * Starts the conversion of an IA file to DjVu
	 *
	 * @param string $fileId the ID of the file on IA
	 */
	public function startConversion( $fileId ) {
		$this->client->get( '', [
			'query' => [ 'cmd' => 'convert', 'ia_id' => $fileId ]
		] );
	}

	/**
	 * Returns the converted DjVu file
	 *
	 * @param string $fileId the ID of the file on IA
	 * @param string $path the path to put the file in
	 */
	public function downloadFile( $fileId, $path ) {
	    // TODO: call startConversion when https://github.com/phil-el/phetools/issues/8 will be fixed
		while ( true ) {
			try {
				$this->streamToFile( $this->client->get( '', [
					'query' => [ 'cmd' => 'get', 'ia_id' => $fileId ]
				] )->getBody(), $path );
				return;
			} catch ( BadResponseException $e ) {
				sleep( 1 ); // TODO: better than active waiting?
			}
		}
	}

	private function streamToFile( StreamInterface $stream, $fileName ) {
	    $file = fopen( $fileName, 'w' );
	    while ( !$stream->eof() ) {
	        fwrite( $file, $stream->read( 1024 ) );
		   }
		fclose( $file );
	}
}
