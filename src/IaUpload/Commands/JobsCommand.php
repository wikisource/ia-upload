<?php

namespace IaUpload\Commands;

use Exception;
use IaUpload\CommonsClient;
use IaUpload\OAuth\MediaWikiOAuth;
use IaUpload\OAuth\Token\AccessToken;
use IaUpload\OAuth\Token\ConsumerToken;
use IaUpload\OAuthController;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class JobsCommand extends Command {

	/**
	 * Set name and job.
	 */
	protected function configure() {
		$this->setName( 'jobs' )->setDescription( 'Runs DjVu conversion jobs' );
	}

	/**
	 * @param InputInterface  $input  An InputInterface instance
	 * @param OutputInterface $output An OutputInterface instance
	 * @return null|int null or 0 if everything went fine, or an error code
	 * @throws Exception If unable to load the required DjVuMaker class.
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$jobsDir = __DIR__ . '/../../../jobqueue';
		$jobs = glob( $jobsDir . '/*/job.json' );
		foreach ( $jobs as $jobFile ) {
			// Skip if this job is locked; otherwise lock this job.
			$lockFile = dirname( $jobFile ) . '/lock';
			if ( file_exists( $lockFile ) ) {
				continue;
			}
			touch( $lockFile );

			// Get job info and set up a log.
			$jobInfo = \GuzzleHttp\json_decode( file_get_contents( $jobFile ) );
			$log = new Logger( 'LOG' );
			$log->pushHandler( new ErrorLogHandler() );
			$log->pushHandler( new StreamHandler( dirname( $jobFile ) . '/log.txt' ) );

			// Make sure we can upload, before doing anything else.
			$mediawikiClient = $this->getMediawikiClient( $jobInfo->userAccessToken );
			$commonsClient = new CommonsClient( $mediawikiClient, $log );
			if ( !$commonsClient->canUpload() ) {
				throw new Exception( "Unable to upload to Commons" );
			}

			// Load the DjvuMaker class.
			$classType = ucfirst( strtolower( $jobInfo->fileSource ) );
			$fileSourceClass = '\\IaUpload\\DjvuMakers\\'.$classType.'DjvuMaker';
			if ( !class_exists( $fileSourceClass ) ) {
				throw new Exception( "Unable to load class $fileSourceClass" );
			}

			// Generate the DjVu.
			$log->info( "Creating DjVu for $jobInfo->iaId from $classType" );
			$jobClient = new $fileSourceClass( $jobInfo->iaId, $log );
			try {
				$localDjvu = $jobClient->createLocalDjvu();
			} catch ( Exception $e ) {
				$log->critical( $e->getMessage() );
				throw $e;
			}

			// Upload to Commons.
			$log->info( "Uploading to $localDjvu to Commons $jobInfo->commonsName" );
			$commonsClient->upload(
				$jobInfo->commonsName,
				$localDjvu,
				$jobInfo->description,
				'Imported from Internet Archive by the [[wikitech:Tool:IA Upload|IA Upload tool]] job queue'
			);
			$this->deleteDirectory( dirname( $jobFile ) );
		}
		return 0;
	}

	/**
	 * @param $dir
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
	 * @param string $accessToken The user's access token.
	 * @return \GuzzleHttp\Client
	 */
	protected function getMediawikiClient( $accessTokenDetails ) {
		// @TODO This shouldn't be here.
		$configFile = __DIR__ . '/../../../config.ini';
		$config = parse_ini_file( $configFile );
		$token = new ConsumerToken( $config['consumerKey'], $config['consumerSecret'] );
		$oAuth = new MediaWikiOAuth( OAuthController::OAUTH_URL, $token );
		$accessToken = new AccessToken( $accessTokenDetails->key, $accessTokenDetails->secret );
		$mediawikiClient = $oAuth->buildMediawikiClientFromToken( $accessToken );
		return $mediawikiClient;
	}
}
