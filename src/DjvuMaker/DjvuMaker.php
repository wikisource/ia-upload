<?php

namespace Wikisource\IaUpload\DjvuMaker;

use Exception;
use Monolog\Logger;
use Wikisource\IaUpload\ApiClient\IaClient;

abstract class DjvuMaker {

	/** @var string The Internet Archive item identifier. */
	protected $itemId;

	/** @var IaClient */
	protected $iaClient;

	/** @var Logger */
	protected $log;

	/**
	 * DjvuMaker constructor.
	 * @param string $itemIdentifier The IA ID.
	 * @param Logger $log The logger.
	 */
	public function __construct( $itemIdentifier, Logger $log ) {
		$this->itemId = $itemIdentifier;
		$this->iaClient = new IaClient();
		$this->log = $log;
	}

	/**
	 * Create a local DjVu file (in jobqueue/<item>/<item>.jdvu for the current item.
	 * @return string The full filesystem path to the created DjVu.
	 */
	abstract public function createLocalDjvu();

	/**
	 * Get the local temporary directory for this item.
	 * @todo Not duplicate this from CommonsController.
	 * @return string Full local filesystem path to the directory, with no trailing slash.
	 * @throws Exception If the job directory doesn't exist.
	 */
	protected function jobDir() {
		$dirName = __DIR__ . '/../../jobqueue/' . $this->itemId;
		$dir = realpath( $dirName );
		if ( $dir === false ) {
			throw new Exception( "Unable to find job directory $dirName" );
		}
		return $dir;
	}

}
