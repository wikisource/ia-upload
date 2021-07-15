<?php

namespace Wikisource\IaUpload\Command;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;

abstract class CommandBase extends Command {

	/** @var array Config data from config.ini. */
	protected $config;

	/**
	 * JobsCommand constructor.
	 * @param array $config The Slim application's config.
	 */
	public function __construct( array $config ) {
		parent::__construct();
		$this->config = $config;
	}

	/**
	 * Get list of job.json files.
	 * @return string
	 */
	protected function getJobs(): array {
		return glob( dirname( __DIR__, 3 ) . '/jobqueue/*/job.json' );
	}

	/**
	 * Delete a directory tree, to any depth.
	 * @param string $dir The directory to delete.
	 */
	protected function deleteDirectory( $dir ) {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getRealPath() );
			} else {
				unlink( $file->getRealPath() );
			}
		}
		rmdir( $dir );
	}
}
