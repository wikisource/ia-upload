<?php

namespace IaUpload;

use Guzzle\Http\Client;

/**
 * Client for IA API
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class IaClient extends Client {

	public static function factory() {
		return new self( 'https://archive.org' );
	}

	/**
	 * Returns details of a file
	 *
	 * @param string $fileId
	 * @return array
	 */
	public function fileDetails( $fileId ) {
		return $this->get( '/details/' . rawurlencode( $fileId ), null, array(
			'query' => array(
				'output' => 'json'
			)
		) )->send()->json();
	}

	/**
	 * Downloads an IA file
	 *
	 * @param string $fileName the name of the file on IA
	 * @param string $path the path to put the file in
	 */
	public function downloadFile( $fileName, $path ) {
		$this->get( '/download/' . $fileName )
            ->setResponseBody( $path )
			->send();
	}
}