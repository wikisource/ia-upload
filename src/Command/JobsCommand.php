<?php

namespace Wikisource\IaUpload\Command;

use Exception;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Wikisource\IaUpload\ApiClient\CommonsClient;
use Wikisource\IaUpload\ApiClient\IaClient;
use Wikisource\IaUpload\Controller\OAuthController;
use Wikisource\IaUpload\DjvuMaker\DjvuMaker;
use Wikisource\IaUpload\OAuth\MediaWikiOAuth;
use Wikisource\IaUpload\OAuth\Token\AccessToken;
use Wikisource\IaUpload\OAuth\Token\ConsumerToken;

class JobsCommand extends Command {

	/** @var string The full filesystem path to the 'jobqueue' directory, with no trailing slash. */
	protected $jobqueueDir;

	/** @var array Config data from config.ini. */
	protected $config;

	public function __construct( array $config ) {
		parent::__construct();
		$this->config = $config;
	}

	/**
	 * Set name and job.
	 */
	protected function configure() {
		$this->setName( 'jobs' )->setDescription( 'Runs DjVu conversion jobs' );
	}

	/**
	 * @param InputInterface $input An InputInterface instance
	 * @param OutputInterface $output An OutputInterface instance
	 * @return null|int null or 0 if everything went fine, or an error code
	 * @throws Exception If unable to load the required DjVuMaker class.
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$this->jobqueueDir = __DIR__ . '/../../jobqueue';
		$jobs = glob( $this->jobqueueDir . '/*/job.json' );
		foreach ( $jobs as $jobFile ) {
			// Make sure we can write to the job directory.
			$jobDir = dirname( $jobFile );
			if ( !is_writable( $jobDir ) ) {
				throw new Exception( "Unable to write to job directory $jobDir" );
			}
			// Skip if this job is locked; otherwise lock this job.
			$lockFile = $jobDir . '/lock';
			if ( file_exists( $lockFile ) ) {
				continue;
			}
			touch( $lockFile );

			// Get job info and set up a log.
			$jobInfo = \GuzzleHttp\json_decode( file_get_contents( $jobFile ) );
			$log = new Logger( 'LOG' );
			$log->pushHandler( new ErrorLogHandler() );
			$log->pushHandler( new StreamHandler( $jobDir . '/log.txt' ) );

			// Make sure we can upload, before doing anything else.
			$mediawikiClient = $this->getMediawikiClient( $jobInfo->userAccessToken );
			$commonsClient = new CommonsClient( $this->config['wiki_base_url'], $mediawikiClient, $log );
			if ( !$commonsClient->canUpload() ) {
				throw new Exception( "Unable to upload to Commons" );
			}

			// Load the DjvuMaker class.
			$classType = ucfirst( strtolower( $jobInfo->fileSource ) );
			$fileSourceClass = 'Wikisource\\IaUpload\\DjvuMaker\\' . $classType . 'DjvuMaker';
			if ( !class_exists( $fileSourceClass ) ) {
				throw new Exception( "Unable to load class $fileSourceClass" );
			}

			// Generate the DjVu.
			$log->info( "Creating DjVu for $jobInfo->iaId from $classType" );
			/** @var DjvuMaker $jobClient */
			$jobClient = new $fileSourceClass( $jobInfo->iaId, $log );
			try {
				$localDjvu = $jobClient->createLocalDjvu();
			} catch ( Exception $e ) {
				$log->critical( $e->getMessage() );
				throw $e;
			}

			// Remove the first page if required.
			if ( $jobInfo->removeFirstPage ) {
				$iaClient = new IaClient();
				$iaClient->removeFirstPage( $localDjvu );
			}

			// Upload to Commons.
			$log->info( "Uploading to $localDjvu to Commons $jobInfo->fullCommonsName" );
			try {
				$comment = 'Imported from Internet Archive by '
					. 'the [[wikitech:Tool:IA Upload|IA Upload tool]] job queue';
				$commonsClient->upload(
					$jobInfo->fullCommonsName,
					$localDjvu,
					$jobInfo->description,
					$comment
				);
			} catch ( Exception $e ) {
				$log->critical( $e->getMessage() );
				throw $e;
			}
			$this->deleteDirectory( $jobDir );
		}
		return 0;
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

	/**
	 * @param \stdClass $accessTokenDetails The user's access token.
	 * @return \GuzzleHttp\Client
	 */
	protected function getMediawikiClient( $accessTokenDetails ) {
		$token = new ConsumerToken( $this->config['consumerKey'], $this->config['consumerSecret'] );
		$oAuth = new MediaWikiOAuth( $this->config['wiki_base_url'], $token );
		$accessToken = new AccessToken( $accessTokenDetails->key, $accessTokenDetails->secret );
		$mediawikiClient = $oAuth->buildMediawikiClientFromToken( $accessToken );
		return $mediawikiClient;
	}
}
