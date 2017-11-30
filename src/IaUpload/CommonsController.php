<?php

namespace IaUpload;

use Exception;
use IaUpload\OAuth\MediaWikiOAuth;
use IaUpload\OAuth\Token\ConsumerToken;
use Mediawiki\Api\Guzzle\ClientFactory;
use Silex\Application;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the commons upload process
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class CommonsController {

	const COMMONS_API_URI = 'https://commons.wikimedia.org/w/api.php';

	/**
	 * @var Application
	 */
	protected $app;

	/**
	 * @var IaClient
	 */
	protected $iaClient;

	/**
	 * @var CommonsClient
	 */
	protected $commonsClient;

	/**
	 * @var array
	 */
	protected $config;

	private static $languageCategories = [
		'ar' => 'Arabic',
		'hy' => 'Armenian',
		'eu' => 'Basque',
		'br' => 'Breton',
		'ca' => 'Catalan',
		'hr' => 'Croatian',
		'da' => 'Danish',
		'zh' => 'Chinese',
		'cs' => 'Czech',
		'nl' => 'Dutch',
		'en' => 'English',
		'et' => 'Estonian',
		'fr' => 'French',
		'de' => 'German',
		'el' => 'Greek',
		'he' => 'Hebrew',
		'hu' => 'Hungarian',
		'id' => 'Indonesian',
		'ga' => 'Irish',
		'it' => 'Italian',
		'la' => 'Latin',
		'ml' => 'Malayalam',
		'nb' => 'Norwegian',
		'oc' => 'Occitan',
		'fa' => 'Persian',
		'pl' => 'Polish',
		'ro' => 'Romanian',
		'ru' => 'Russian',
		'sl' => 'Slovenian',
		'es' => 'Spanish',
		'sv' => 'Swedish',
		'uk' => 'Ukrainian',
		'vi' => 'Venetian'
	];

	/**
	 * CommonsController constructor.
	 * @param Application $app The Silex application
	 * @param array $config App configuration.
	 */
	public function __construct( Application $app, array $config ) {
		$this->app = $app;
		$this->config = $config;

		$this->iaClient = new IaClient();
		$this->commonsClient = new CommonsClient( $this->buildMediawikiClient(), $app['logger'] );
	}

	private function buildMediawikiClient() {
		if ( $this->app['session']->has( 'access_token' ) ) {
			$oAuth = new MediaWikiOAuth(
				OAuthController::OAUTH_URL,
				new ConsumerToken( $this->config['consumerKey'], $this->config['consumerSecret'] )
			);
			return $oAuth->buildMediawikiClientFromToken( $this->app['session']->get( 'access_token' ) );
		} else {
			return ( new ClientFactory() )->getClient();
		}
	}

	/**
	 * Get the full directory name of the working directory for the given job.
	 * @param string $iaId The IA item ID.
	 * @return string The full filesystem path to the directory (as returned by realpath()).
	 * @throws Exception If the directory can't be created or is not writable.
	 */
	protected function getJobDirectory( $iaId = null ) {
		$jobDirectoryName = __DIR__ . '/../../jobqueue/' . $iaId;
		if ( !is_dir( $jobDirectoryName ) ) {
			mkdir( $jobDirectoryName, 0755, true );
		}
		$jobDirectory = realpath( $jobDirectoryName );
		if ( $jobDirectory === false || !is_writable( $jobDirectory ) ) {
			throw new Exception( "Unable to create temporary directory '$jobDirectoryName'" );
		}
		return $jobDirectory;
	}

	/**
	 * The first action presented to the user.
	 * @param Request $request The HTTP request.
	 * @return Response
	 */
	public function init( Request $request ) {
		$jobs = [];
		foreach ( glob( $this->getJobDirectory() . '/*/job.json' ) as $jobFile ) {
			$jobInfo = \GuzzleHttp\json_decode( file_get_contents( $jobFile ) );
			$jobInfo->locked = file_exists( dirname( $jobFile ) . '/lock' );
			$jobInfo->failed = false;
			$logFile = dirname( $jobFile ) . '/log.txt';
			$jobInfo->failed = ( file_exists( $logFile ) && filemtime( $logFile ) < ( time() - 24 * 60 * 60 ) );
			$jobInfo->hasDjvu = file_exists( dirname( $jobFile ) . '/' . $jobInfo->iaId . '.djvu' );
			$jobs[] = $jobInfo;
		}
		return $this->outputsInitTemplate( [
			'iaId' => $request->get( 'iaId', '' ),
			'commonsName' => $request->get( 'commonsName', '' ),
			'jobs' => $jobs,
		] );
	}

	/**
	 * The second step, in which users fill in the Commons template etc.
	 * @param Request $request The HTTP request.
	 * @return Response
	 */
	public function fill( Request $request ) {
		// Get inputs.
		$iaId = $request->get( 'iaId', '' );
		$commonsName = $this->commonsClient->normalizePageTitle( $request->get( 'commonsName', '' ) );
		$fileSource = $request->get( 'fileSource', 'djvu' );
		// Validate inputs.
		if ( $iaId === '' || $commonsName === '' ) {
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => $this->app['i18n']->message( 'set-all-fields' ),
			] );
		}
		// Ensure that file name is less than or equal to 240 bytes.
		if ( strlen( $commonsName ) > 240 ) {
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => $this->app['i18n']->message( 'invalid-length', [ $commonsName ] ),
			] );
		}
		// Strip any trailing file extension.
		if ( preg_match( '/^(.*)\.(pdf|djvu)$/', $commonsName, $m ) ) {
			$commonsName = $m[1];
		}
		// Check that the filename is allowed on Commons.
		if ( !$this->commonsClient->isPageTitleValid( $commonsName ) ) {
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => $this->app['i18n']->message( 'invalid-commons-name', [ $commonsName ] ),
			] );
		}

		// Try to get IA details.
		$iaData = $this->iaClient->fileDetails( $iaId );
		if ( $iaData === false ) {
			$link = '<a href="https://archive.org/details/' . rawurlencode( $iaId ) . '">'
				. htmlspecialchars( $iaId )
				. '</a>';
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => $this->app['i18n']->message( 'no-found-on-ia', [ $link ] ),
			] );
		}
		$iaId = $iaData['metadata']['identifier'][0];

		// Make sure at least one of the required input formats is available.
		$djvuFilename = $this->getIaFileName( $iaData, 'djvu' );
		$pdfFilename = $this->getIaFileName( $iaData, 'pdf' );
		$jp2Filename = $this->getIaFileName( $iaData, 'jp2' );
		if ( ! ( $djvuFilename || $pdfFilename || $jp2Filename ) ) {
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => $this->app['i18n']->message( 'no-usable-files-found' ),
			] );
		}

		// See if the file already exists on Commons.
		$fullCommonsName = $commonsName . '.djvu';
		if ( $this->commonsClient->pageExist( 'File:' . $fullCommonsName ) ) {
			$link = '<a href="https://commons.wikimedia.org/wiki/File:' . rawurlencode( $fullCommonsName ) . '">'
				. htmlspecialchars( $fullCommonsName )
				. '</a>';
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => $this->app['i18n']->message( 'already-on-commons', [ $link ] ),
			] );
		}

		// Output the page.
		$templateParams = [
			'iaId' => $iaId,
			'commonsName' => $fullCommonsName,
			'djvuFilename' => $djvuFilename,
			'pdfFilename' => $pdfFilename,
			'jp2Filename' => $jp2Filename,
			'fileSource' => $fileSource,
		];
		list( $description, $notes ) = $this->createPageContent( $iaData );
		$templateParams['description'] = $description;
		$templateParams['notes'] = $notes;
		return $this->outputsFillTemplate( $templateParams );
	}

	/**
	 * The save action either uploads the IA DjVu to Commons, or when conversion is required it
	 * puts the job data into the queue for subsequent processing by the CLI part of this tool.
	 *
	 * @param Request $request The HTTP request.
	 * @return Response
	 */
	public function save( Request $request ) {
		// Get all form inputs.
		$jobInfo = [
			'iaId' => $request->get( 'iaId' ),
			'commonsName' => $this->commonsClient->normalizePageTitle( $request->get( 'commonsName' ) ),
			'description' => $request->get( 'description' ),
			'fileSource' => $request->get( 'fileSource', 'jp2' ),
			'removeFirstPage' => $request->get( 'removeFirstPage', 0 ) === 'yes',
		];
		if ( !$jobInfo['iaId'] || !$jobInfo['commonsName'] || !$jobInfo['description'] ) {
			$jobInfo['error'] = 'You must set all the fields of the form';
			return $this->outputsFillTemplate( $jobInfo );
		}

		// Check again that the Commons file doesn't exist.
		if ( $this->commonsClient->pageExist( 'File:' . $jobInfo['commonsName'] ) ) {
			$link = '<a href="http://commons.wikimedia.org/wiki/File:' . rawurlencode( $jobInfo['commonsName'] ) . '">'
				. htmlspecialchars( $jobInfo['commonsName'] )
				. '</a>';
			$jobInfo['error'] = $this->app['i18n']->message( 'already-on-commons', [ $link ] );
			return $this->outputsFillTemplate( $jobInfo );
		}

		// Check again that the IA item does exist.
		$iaData = $this->iaClient->fileDetails( $jobInfo['iaId'] );
		if ( $iaData === false ) {
			$link = '<a href="http://archive.org/details/' . rawurlencode( $jobInfo['iaId'] ) . '">'
				. htmlspecialchars( $jobInfo['iaId'] )
				. '</a>';
			$jobInfo['error'] = $this->app['i18n']->message( 'no-found-on-ia', [ $link ] );
			return $this->outputsFillTemplate( $jobInfo );
		}
		$jobInfo['iaId'] = $iaData['metadata']['identifier'][0];

		// Create a local working directory.
		$jobDirectory = $this->getJobDirectory( $jobInfo['iaId' ] );

		// For PDF and JP2 conversion, add the job to the queue.
		if ( $jobInfo['fileSource'] === 'pdf' || $jobInfo['fileSource'] === 'jp2' ) {
			// Create a private job file before writing contents to it,
			// because it contains the access token.
			$jobInfo['userAccessToken'] = $this->app['session']->get( 'access_token' );
			$jobFile = $jobDirectory . '/job.json';
			$oldUmask = umask( 0177 );
			touch( $jobFile );
			umask( $oldUmask );
			chmod( $jobFile, 0600 );
			file_put_contents( $jobFile, \GuzzleHttp\json_encode( $jobInfo ) );
			return $this->app->redirect( $this->app["url_generator"]->generate( 'home' ) );
		}

		// Use IA DjVu file (don't add it to the queue, as this shouldn't take too long).
		if ( $jobInfo['fileSource'] === 'djvu' ) {
			$djvuFilename = $this->getIaFileName( $iaData, 'djvu' );
			$remoteDjVuFile = $jobInfo['iaId'] . $djvuFilename;
			$localDjVuFile = $jobDirectory . '/' . $djvuFilename;
			try {
				$this->iaClient->downloadFile( $remoteDjVuFile, $localDjVuFile );
				if ( $jobInfo['removeFirstPage'] ) {
					$this->iaClient->removeFirstPage( $localDjVuFile );
				}
				$this->commonsClient->upload(
					$jobInfo['commonsName'],
					$localDjVuFile,
					$jobInfo['description'],
					'Importation from Internet Archive via [[toollabs:ia-upload|IA-upload]]'
				);
			} catch ( Exception $e ) {
				unlink( $localDjVuFile );
				rmdir( $jobDirectory );
				$jobInfo['error'] = "An error occurred: " . $e->getMessage();
				return $this->outputsFillTemplate( $jobInfo );
			}
			// Confirm that it was uploaded.
			if ( !$this->commonsClient->pageExist( 'File:' . $jobInfo['commonsName'] ) ) {
				$jobInfo['error'] = 'File failed to upload';
				return $this->outputsFillTemplate( $jobInfo );
			}
			unlink( $localDjVuFile );
			rmdir( $jobDirectory );
			$url = 'http://commons.wikimedia.org/wiki/File:' . rawurlencode( $jobInfo['commonsName'] );
			$msgParam = '<a href="' . $url . '">' . $jobInfo['commonsName'] . '</a>';
			return $this->outputsInitTemplate( [
				'success' => $this->app['i18n']->message( 'successfully-uploaded', [ $msgParam ] ),
			] );
		}
	}

	/**
	 * Display the log of a given job.
	 * @param Request $request The HTTP request.
	 * @param string $iaId The IA ID.
	 * @return Response
	 */
	public function logview( Request $request, $iaId ) {
		// @todo Not duplicate the log name between here and JobsCommand.
		$logFile = $this->getJobDirectory( $iaId ) . '/log.txt';
		$log = ( file_exists( $logFile ) ) ? file_get_contents( $logFile ) : 'No log available.';
		return new Response( $log, 200, [ 'Content-Type' => 'text/plain' ] );
	}

	/**
	 * Download a single DjVu file if possible.
	 * @param Request $request The HTTP request.
	 * @param string $iaId The IA ID.
	 * @return Response
	 */
	public function downloadDjvu( Request $request, $iaId ) {
		$filename = $this->getJobDirectory( $iaId ) . '/' . $iaId . '.djvu';
		if ( !file_exists( $filename ) ) {
			return new Response( 'File not found', 404 );
		}
		return new BinaryFileResponse( $filename );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param array $params Parameters to pass to the template
	 * @return Response
	 */
	protected function outputsInitTemplate( array $params ) {
		$defaultParams = [
			'iaId' => '',
			'commonsName' => '',
			'jobs' => [],
		];
		$params = array_merge( $defaultParams, $params );
		return $this->outputsTemplate( 'commons/init.twig', $params );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param array $params Parameters to pass to the template
	 * @return Response
	 */
	protected function outputsFillTemplate( array $params ) {
		$defaultParams = [
			'iaId' => '',
			'commonsName' => '',
			'iaFileName' => '',
			'description' => '',
			'notes' => []

		];
		$params = array_merge( $defaultParams, $params );
		return $this->outputsTemplate( 'commons/fill.twig', $params );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param string $templateName The template filename.
	 * @param array $params Parameters to pass to the template
	 * @return Response
	 */
	protected function outputsTemplate( $templateName, array $params ) {
		$defaultParams = [
			'debug' => $this->app['debug'],
			'success' => '',
			'warning' => '',
			'error' => '',
			'user' => $this->app['session']->get( 'user' ),
			'oauth_cid' => isset( $this->config[ 'consumerId' ] ) ? $this->config[ 'consumerId' ] : '',
		];
		$params = array_merge( $defaultParams, $params );
		return $this->app['twig']->render( $templateName, $params );
	}

	/**
	 * Returns the file name of the requested file from the given IA metadata.
	 *
	 * @param array $data The IA metadata containing a 'files' key.
	 * @param string $fileType One of 'djvu', 'pdf', or 'zip'.
	 * @return string|bool The filename, or false if none could be found.
	 */
	protected function getIaFileName( $data, $fileType = 'djvu' ) {
		// First check for djvu or pdf.
		if ( $fileType === 'djvu' || $fileType === 'pdf' ) {
			foreach ( $data['files'] as $filePath => $fileInfo ) {
				$djvu = ( $fileType === 'djvu' && $fileInfo['format'] === 'DjVu' );
				$pdfFormats = [ 'Text PDF', 'Additional Text PDF', 'Image Container PDF' ];
				$pdf = ( $fileType === 'pdf' && in_array( $fileInfo['format'], $pdfFormats ) );
				if ( $djvu || $pdf ) {
					return $filePath;
				}
			}
		}

		// Then jp2; we only consider to have a jp2 file if we've also got a  *_djvu.xml to go
		// with it. Could perhaps instead check for $fileInfo['format'] === 'Abbyy GZ'?
		if ( $fileType === 'jp2' ) {
			$filenames = array_keys( $data['files'] );
			$jp2 = preg_grep( '/.*_jp2\.zip/', $filenames );
			$xml = preg_grep( '/.*_djvu\.xml/', $filenames );
			if ( count( $jp2 ) > 0 && count( $xml ) > 0 ) {
				return array_shift( $jp2 );
			}
		}
		return false;
	}

	/**
	 * Creates the content of the description page
	 *
	 * @param array $data The IA metadata.
	 * @return array
	 */
	protected function createPageContent( $data ) {
		$language = $this->parseLanguageParam( $data );
		$notes = [];
		$content = '== {{int:filedesc}} ==' . "\n";
		$content .= '{{Book' . "\n";
		$content .= '| Author       = ' . $this->parseAuthorParam( $data, $notes ) . "\n";
		$content .= '| Editor       = ' . "\n";
		$content .= '| Translator   = ' . "\n";
		$content .= '| Illustrator  = ' . "\n";
		if ( isset( $data['metadata']['title'][0] ) ) {
			$content .= '| Title        = ' . $data['metadata']['title'][0] . "\n";
		} else {
			$content .= '| Title        = ' . "\n";
		}
		$content .= '| Subtitle     = ' . "\n";
		$content .= '| Series title = ' . "\n";
		if ( isset( $data['metadata']['volume'][0] ) ) {
			$content .= '| Volume       = ' . $data['metadata']['volume'][0] . "\n";
		} else {
			$content .= '| Volume       = ' . "\n";
		}
		$content .= '| Edition      = ' . "\n";
		if ( isset( $data['metadata']['publisher'][0] ) ) {
			$content .= '| Publisher    = ' . $data['metadata']['publisher'][0] . "\n";
		} else {
			$content .= '| Publisher    = ' . "\n";
		}
		if ( isset( $data['metadata']['printer'][0] ) ) {
			$content .= '| Printer      = ' . $data['metadata']['printer'][0] . "\n";
		} else {
			$content .= '| Printer      = ' . "\n";
		}
		if ( isset( $data['metadata']['date'][0] ) ) {
			$content .= '| Date         = ' . $data['metadata']['date'][0] . "\n";
		} elseif ( isset( $data['metadata']['year'][0] ) ) {
			$content .= '| Date         = ' . $data['metadata']['year'][0] . "\n";
		} else {
			$content .= '| Date         = ' . "\n";
		}
		$content .= '| City         = ' . "\n";
		if ( $language ) {
			$content .= '| Language     = {{language|' . $language . '}}' . "\n";
		} else {
			$content .= '| Language     = ' . "\n";
		}
		if ( isset( $data['metadata']['description'][0] ) ) {
			$content .= '| Description  = ' . $data['metadata']['description'][0] . "\n";
		} else {
			$content .= '| Description  = ' . "\n";
		}
		$content .= '| Source       = ' . $this->parseSourceParam( $data ) . "\n";
		$content .= '| Image        = {{PAGENAME}}' . "\n";
		$content .= '| Image page   = ' . "\n";
		$content .= '| Permission   = ' . "\n";
		$content .= '| Other versions = ' . "\n";
		$content .= '| Wikisource   = s:' . $language . ':Index:{{PAGENAME}}' . "\n";
		$content .= '| Homecat      = ' . "\n";
		$content .= '| Wikidata     = ' . "\n";
		$content .= '}}' . "\n" . '{{Djvu}}' . "\n\n";
		$content .= '== {{int:license-header}} ==' . "\n" . '{{PD-scan}}' . "\n\n";
		$content .= '[[Category:Uploaded with IA Upload]]' . "\n";

		$isCategorised = false;
		if ( isset( $data['metadata']['date'][0] ) && $this->commonsClient->pageExist( 'Category:' . $data['metadata']['date'][0] . ' books' ) ) {
			$content .= '[[Category:' . $data['metadata']['date'][0] . ' books]]' . "\n";
			$isCategorised = true;
		}
		if ( isset( $data['metadata']['creator'][0] ) && $this->commonsClient->pageExist( 'Category:' . $data['metadata']['creator'][0] ) ) {
			$content .= '[[Category:' . $data['metadata']['creator'][0] . ']]' . "\n";
			$isCategorised = true;
		}
		if ( isset( self::$languageCategories[$language] ) ) {
			$content .= '[[Category:DjVu files in ' .  self::$languageCategories[$language] . ']]' . "\n";
			$isCategorised = true;
		}
		if ( !$isCategorised ) {
			$content = '{{subst:unc}}' . "\n\n" . $content;
		}
		return [ trim( $content ), $notes ];
	}

	/**
	 * Get the wikitext for the Author parameter.
	 * @param mixed[] $data The IA metadata.
	 * @param string[] &$notes The notes array to add warnings to.
	 * @return string
	 */
	protected function parseAuthorParam( $data, &$notes ) {
		if ( !isset( $data['metadata']['creator'][0] ) ) {
			return '';
		}
		$creator = $data['metadata']['creator'][0];
		// If there's a comma, assume we're reversed.
		if ( strpos( $creator, ',' ) !== false ) {
			$creatorParts = array_map( 'trim', explode( ',', $creator ) );
			// Exclude any parts that are dates (numbers and hyphens).
			$authorParts = preg_grep( '/^[0-9-]*$/', $creatorParts, PREG_GREP_INVERT );
			$creator = join( ' ',  array_reverse( $authorParts ) );
		}
		if ( $this->commonsClient->pageExist( "Creator:$creator" ) ) {
			return "{{Creator:$creator}}";
		} else {
			$notes[] = $this->app['i18n']->message( 'creator-template-missing', [ $creator ] );
			return $creator;
		}
	}

	/**
	 * Get the wikitext for the Source parameter.
	 * @param mixed[] $data The IA metadata.
	 * @return string
	 */
	protected function parseSourceParam( $data ) {
		$out = '{{IA|' . $data['metadata']['identifier'][0] . '}}';
		if ( isset( $data['metadata']['source'][0] ) ) {
			$out .= "<br />Internet Archive source: ";
			// Google Books links (mostly from BUB) run afoul of the Abuse Filter.
			// e.g. http://books.google.com/books?id=zzsQAAAAIAAJ&hl=&source=gbs_api
			// Replace with: https://commons.wikimedia.org/wiki/Template:Google_Book_Search_link
			$googleBooks = '/.*books\.google\.com\/books\?id=([^&]*).*/';
			preg_match( $googleBooks, $data['metadata']['source'][0], $matches );
			if ( isset( $matches[1] ) ) {
				$out .= '{{Google Book Search link|' . $matches[1] . '}}';
			} else {
				$out .= $data['metadata']['source'][0];
			}
		}
		return $out;
	}

	/**
	 * Normalize a language code.
	 * @todo Make less hacky.
	 * @param mixed[] $data The IA metadata.
	 * @return string The language code.
	 */
	protected function parseLanguageParam( $data ) {
		if ( !isset( $data['metadata']['language'][0] ) ) {
			return '';
		}
		$language = strtolower( $data['metadata']['language'][0] );

		if ( strlen( $language ) == 2 ) {
			return $language;
		} elseif ( strlen( $language ) == 3 ) {
			return $language[0] . $language[1];
		} else {
			$language = ucfirst( $language );
			foreach ( self::$languageCategories as $id => $name ) {
				if ( $language == $name ) {
					return $id;
				}
			}
			return $language;
		}
	}
}
