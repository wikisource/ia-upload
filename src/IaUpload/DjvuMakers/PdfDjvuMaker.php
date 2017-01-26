<?php

namespace IaUpload\DjvuMakers;

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
class PdfDjvuMaker extends DjvuMaker {

	/**
	 * @var Client
	 */
	private $client;

	public function createLocalDjvu() {
		$this->client = new Client( [
			'base_uri' => 'http://tools.wmflabs.org/phetools/pdf_to_djvu_cgi.py'
		] );
		$this->startConversion( $this->itemId );
		$localDjvuFile = $this->jobDir() . '/' . $this->itemId . '.djvu';
		$this->downloadFile( $this->itemId, $localDjvuFile );
		return $localDjvuFile;
	}

	/**
	 * Starts the conversion of an IA file to DjVu
	 *
	 * @param string $fileId the ID of the file on IA
	 */
	public function startConversion( $fileId ) {
		$this->log->info( "Requesting start of conversion of $fileId" );
		$this->client->get( '', [
			'query' => [ 'cmd' => 'convert', 'ia_id' => $fileId ]
		] );
	}

	/**
	 * Returns the converted DjVu file
	 *
	 * @param string $fileId the ID of the file on IA
	 * @param string $outputFile the path to put the file in
	 */
	public function downloadFile( $fileId, $outputFile ) {
		$this->log->info( "Starting download to $outputFile" );
	    // TODO: call startConversion when https://github.com/phil-el/phetools/issues/8 will be fixed
		while ( true ) {
			try {
				$this->log->debug( "Getting $fileId" );
				$this->client->get( '', [
					'query' => [ 'cmd' => 'get', 'ia_id' => $fileId ],
					'sink' => $outputFile,
				] );
				return;
			} catch ( BadResponseException $e ) {
				$errorResponse = \GuzzleHttp\json_decode( file_get_contents( $outputFile ) );
				$okayErrors = [ 0, 3 ];
				if ( $errorResponse && in_array( $errorResponse->error, $okayErrors ) ) {
					$this->log->debug( $errorResponse->text );
					sleep( 5 ); // Check again every 5 seconds.
				} else {
					throw $e;
				}
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
