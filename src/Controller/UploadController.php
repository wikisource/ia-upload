<?php

namespace Wikisource\IaUpload\Controller;

use Exception;
use Mediawiki\Api\Guzzle\ClientFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DI\Container;
use Slim\Routing\RouteParser;
use GuzzleHttp\Psr7\LazyOpenStream;
use Wikisource\IaUpload\ApiClient\CommonsClient;
use Wikisource\IaUpload\ApiClient\IaClient;
use Wikisource\IaUpload\OAuth\MediaWikiOAuth;
use Wikisource\IaUpload\OAuth\Token\ConsumerToken;

/**
 * Controller for the commons upload process
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class UploadController {

	/**
	 * @var Container
	 */
	protected $c;

	/**
	 * @var RouteParser
	 */
	protected $routeParser;

	/**
	 * @var I18nContext
	 */
	protected $i18n;

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
	 * UploadController constructor.
	 * @param Container $c The Slim application's container.
	 * @param RouteParser $routeParser The Slim application's route parser.
	 */
	public function __construct( Container $c, RouteParser $routeParser ) {
		$this->c = $c;
		$this->routeParser = $routeParser;
		$this->config = $c->get( 'config' );
		$this->i18n = $c->get( 'i18n' );

		$this->iaClient = new IaClient();
		$this->commonsClient = new CommonsClient(
			$this->config['wiki_base_url'],
			$this->buildMediawikiClient(),
			$c->get( 'logger' )
		);
	}

	private function buildMediawikiClient() {
		$session = $this->c->get( 'session' );
		if ( $session->exists( 'access_token' ) ) {
			$oAuth = new MediaWikiOAuth(
				$this->config['wiki_base_url'],
				new ConsumerToken( $this->config['consumerKey'], $this->config['consumerSecret'] )
			);
			return $oAuth->buildMediawikiClientFromToken( $session->get( 'access_token' ) );
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
	 * @param Response $response The HTTP response.
	 * @return Response
	 */
	public function init( Request $request, Response $response ) {
		$jobs = [];
		foreach ( glob( $this->getJobDirectory() . '/*/job.json' ) as $jobFile ) {
			$jobInfo = \GuzzleHttp\json_decode( file_get_contents( $jobFile ) );
			$jobInfo->locked = file_exists( dirname( $jobFile ) . '/lock' );
			$jobInfo->failed = false;
			$logFile = dirname( $jobFile ) . '/log.txt';
			$aDayAgo = ( time() - 24 * 60 * 60 );
			$jobInfo->failed = ( file_exists( $logFile ) && filemtime( $logFile ) < $aDayAgo );
			$djvuFilename = dirname( $jobFile ) . '/' . $jobInfo->iaId . '.djvu';
			$jobInfo->hasDjvu = file_exists( $djvuFilename );
			$jobs[] = $jobInfo;
		}
		$query = $request->getQueryParams();
		return $this->outputsInitTemplate( [
			'iaId' => $query['iaId'] ?? '',
			'commonsName' => $query['commonsName'] ?? '',
			'jobs' => $jobs,
		], $response );
	}

	/**
	 * The second step, in which users fill in the Commons template etc.
	 * @param Request $request The HTTP request.
	 * @param Response $response The HTTP response.
	 * @return Response
	 */
	public function fill( Request $request, Response $response ) {
		// Get inputs.
		$query = $request->getQueryParams();
		$iaId = trim( $query['iaId'] ?? '' );
		$commonsName = $this->commonsClient->normalizePageTitle( $query['commonsName'] ?? '' );
		$format = $query['format'] ?? 'pdf';
		$fileSource = $query['fileSource'] ?? 'djvu';
		// Validate inputs.
		if ( $iaId === '' || $commonsName === '' ) {
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'format' => $format,
				'commonsName' => $commonsName,
				'error' => $this->i18n->message( 'set-all-fields' ),
			], $response );
		}
		// Ensure that file name is less than or equal to 240 bytes.
		if ( strlen( $commonsName ) > 240 ) {
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'format' => $format,
				'commonsName' => $commonsName,
				'error' => $this->i18n->message( 'invalid-length', [ $commonsName ] ),
			], $response );
		}
		// Strip any trailing file extension.
		$commonsName = preg_replace( '/\.(pdf|djvu)$/i', '', $commonsName );
		// Check that the filename is allowed on Commons.
		if ( !$this->commonsClient->isPageTitleValid( $commonsName ) ) {
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'format' => $format,
				'commonsName' => $commonsName,
				'error' => $this->i18n->message( 'invalid-commons-name', [ $commonsName ] ),
			], $response );
		}

		// Try to get IA details.
		$iaData = $this->iaClient->fileDetails( $iaId );
		if ( $iaData === false ) {
			$link = '<a href="https://archive.org/details/' . rawurlencode( $iaId ) . '">'
				. htmlspecialchars( $iaId )
				. '</a>';
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'format' => $format,
				'commonsName' => $commonsName,
				'error' => $this->i18n->message( 'no-found-on-ia', [ $link ] ),
			], $response );
		}
		$iaId = $iaData['metadata']['identifier'][0];

		// Make sure at least one of the required input formats is available.
		$djvuFilename = $this->getIaFileName( $iaData, 'djvu' );
		$pdfFilename = $this->getIaFileName( $iaData, 'pdf' );
		$jp2Filename = $this->getIaFileName( $iaData, 'jp2' );
		if ( ( $format === 'pdf' && !$pdfFilename )
			|| !( $djvuFilename || $pdfFilename || $jp2Filename ) ) {
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'format' => $format,
				'commonsName' => $commonsName,
				'error' => $this->i18n->message( 'no-usable-files-found' ),
			], $response );
		}

		// Size sanity checks.
		$warning = '';
		if ( $format === 'djvu' && $jp2Filename !== false ) {
			// Make sure the zip file isn't too large.
			$maxSizeInMb = 600;
			$sizeInMb = round( $iaData['files'][$jp2Filename]['size'] / ( 1024 * 1024 ) );
			if ( $sizeInMb > $maxSizeInMb ) {
				$msgParams = [ $sizeInMb, $maxSizeInMb ];
				$warning = $this->i18n->message( 'zip-file-too-large', $msgParams )
					. ' ' . $this->i18n->message( 'watch-log' );
			}
			// Make sure there aren't too many pages.
			$maxPageCount = 900;
			if ( isset( $iaData['metadata']['imagecount'][0] )
				&& $iaData['metadata']['imagecount'][0] > $maxPageCount ) {
				$msgParams = [ $iaData['metadata']['imagecount'][0], $maxPageCount ];
				$warning = $this->i18n->message( 'too-many-pages', $msgParams )
					. ' ' . $this->i18n->message( 'watch-log' );
			}
		}

		// See if the file already exists on Commons.
		$fullCommonsName = $commonsName . '.' . $format;
		if ( $this->commonsClient->pageExist( 'File:' . $fullCommonsName ) ) {
			$url = 'https://commons.wikimedia.org/wiki/File:' . rawurlencode( $fullCommonsName );
			$link = "<a href='$url'>" . htmlspecialchars( $fullCommonsName ) . '</a>';
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'format' => $format,
				'commonsName' => $commonsName,
				'error' => $this->i18n->message( 'already-on-commons', [ $link ] ),
			], $response );
		}

		// Output the page.
		list( $description, $notes ) = $this->createPageContent( $iaData, $format );
		$templateParams = [
			'warning' => $warning,
			'iaId' => $iaId,
			'format' => $format,
			'commonsName' => $commonsName,
			'djvuFilename' => $djvuFilename,
			'pdfFilename' => $pdfFilename,
			'jp2Filename' => $jp2Filename,
			'fileSource' => $fileSource,
			'description' => $description,
			'notes' => $notes,
		];
		return $this->outputsFillTemplate( $templateParams, $response );
	}

	/**
	 * The save action either uploads the IA file to Commons, or when conversion is required it
	 * puts the job data into the queue for subsequent processing by the CLI part of this tool.
	 *
	 * @param Request $request The HTTP request.
	 * @param Response $response The HTTP response.
	 * @return Response
	 */
	public function save( Request $request, Response $response ) {
		$data = $request->getParsedBody();
		// Normalize and strip any trailing file extension.
		$commonsName = $this->commonsClient->normalizePageTitle( $data['commonsName'] );
		$commonsName = preg_replace( '/\.(pdf|djvu)$/i', '', $commonsName );
		// Get all form inputs.
		$jobInfo = [
			'iaId' => $data['iaId'],
			'format' => $data['format'],
			'commonsName' => $commonsName,
			'fullCommonsName' => $commonsName . '.' . $data['format'],
			'description' => $data['description'],
			'fileSource' => $data['fileSource'] ?? 'jp2',
			'removeFirstPage' => ( $data['removeFirstPage'] ?? 0 ) === 'yes',
		];
		if ( !$jobInfo['iaId'] || !$jobInfo['commonsName'] || !$jobInfo['description'] ) {
			$jobInfo['error'] = 'You must set all the fields of the form';
			return $this->outputsFillTemplate( $jobInfo, $response );
		}

		// Check again that the Commons file doesn't exist.
		if ( $this->commonsClient->pageExist( 'File:' . $jobInfo['fullCommonsName'] ) ) {
			$url = 'http://commons.wikimedia.org/wiki/File:' . rawurlencode( $jobInfo['fullCommonsName'] );
			$link = '<a href="' . $url . '">' . htmlspecialchars( $jobInfo['fullCommonsName'] ) . '</a>';
			$jobInfo['error'] = $this->i18n->message( 'already-on-commons', [ $link ] );
			return $this->outputsFillTemplate( $jobInfo, $response );
		}

		// Check again that the IA item does exist.
		$iaData = $this->iaClient->fileDetails( $jobInfo['iaId'] );
		if ( $iaData === false ) {
			$link = '<a href="http://archive.org/details/' . rawurlencode( $jobInfo['iaId'] ) . '">'
				. htmlspecialchars( $jobInfo['iaId'] )
				. '</a>';
			$jobInfo['error'] = $this->i18n->message( 'no-found-on-ia', [ $link ] );
			return $this->outputsFillTemplate( $jobInfo, $response );
		}
		$jobInfo['iaId'] = $iaData['metadata']['identifier'][0];

		// Create a local working directory.
		$jobDirectory = $this->getJobDirectory( $jobInfo['iaId' ] );

		// For PDF and JP2 conversion to DjVu, add the job to the queue.
		if ( $jobInfo['format'] === 'djvu'
			&& ( $jobInfo['fileSource'] === 'pdf' || $jobInfo['fileSource'] === 'jp2' ) ) {
			// Create a private job file before writing contents to it,
			// because it contains the access token.
			$jobInfo['userAccessToken'] = $this->c->get( 'session' )->get( 'access_token' );
			$jobFile = $jobDirectory . '/job.json';
			$oldUmask = umask( 0177 );
			touch( $jobFile );
			umask( $oldUmask );
			chmod( $jobFile, 0600 );
			file_put_contents( $jobFile, \GuzzleHttp\json_encode( $jobInfo ) );
			return $response
				->withHeader( 'Location', $this->routeParser->urlFor( 'home' ) )
				->withStatus( 302 );
		} else {
			// Use IA file directly (don't add it to the queue, as this shouldn't take too long).
			$filename = $this->getIaFileName( $iaData, $jobInfo['format'] );
			$remoteFile = $jobInfo['iaId'] . $filename;
			$localFile = $jobDirectory . $filename;
			try {
				$this->iaClient->downloadFile( $remoteFile, $localFile );
				if ( $jobInfo['format'] === 'djvu' && $jobInfo['removeFirstPage'] ) {
					$this->iaClient->removeFirstPage( $localFile );
				}
				$this->commonsClient->upload(
					$jobInfo['fullCommonsName'],
					$localFile,
					$jobInfo['description'],
					'Importation from Internet Archive via [[toollabs:ia-upload|IA-upload]]'
				);
			} catch ( Exception $e ) {
				unlink( $localFile );
				rmdir( $jobDirectory );
				$jobInfo['error'] = "An error occurred: " . $e->getMessage();
				return $this->outputsFillTemplate( $jobInfo, $response );
			}
			// Confirm that it was uploaded.
			if ( !$this->commonsClient->pageExist( 'File:' . $jobInfo['fullCommonsName'] ) ) {
				unlink( $localFile );
				rmdir( $jobDirectory );
				$jobInfo['error'] = 'File failed to upload';
				return $this->outputsFillTemplate( $jobInfo, $response );
			}
			unlink( $localFile );
			rmdir( $jobDirectory );
			$url = $this->config['wiki_base_url']
				. '/wiki/File:'
				. rawurlencode( $jobInfo['fullCommonsName'] );
			$msgParam = '<a href="' . $url . '">' . $jobInfo['fullCommonsName'] . '</a>';
			return $this->outputsInitTemplate( [
				'success' => $this->i18n->message( 'successfully-uploaded', [ $msgParam ] ),
			], $response );
		}
	}

	/**
	 * Display the log of a given job.
	 * @param Request $request The HTTP request.
	 * @param Response $response The HTTP response.
	 * @param string $iaId The IA ID.
	 * @return Response
	 */
	public function logview( Request $request, Response $response, $iaId ) {
		// @todo Not duplicate the log name between here and JobsCommand.
		$response = $response->withHeader( 'Content-Type', 'text/plain' );
		$logFile = $this->getJobDirectory( $iaId ) . '/log.txt';
		if ( file_exists( $logFile ) ) {
			return $response->withBody( new LazyOpenStream( $logFile, 'r' ) );
		} else {
			$response->getBody()->write( 'No log available.' );
			return $response;
		}
	}

	/**
	 * Download a single DjVu file if possible.
	 * @param Request $request The HTTP request.
	 * @param Response $response The HTTP response.
	 * @param string $iaId The IA ID.
	 * @return Response
	 */
	public function downloadDjvu( Request $request, Response $response, $iaId ) {
		$filename = $this->getJobDirectory( $iaId ) . '/' . $iaId . '.djvu';
		if ( !file_exists( $filename ) ) {
			return $response->withStatus( 404, 'File not found' );
		}
		return $response
			->withHeader( 'Content-Type', 'image/vnd.djvu' )
			->withBody( new LazyOpenStream( $filename, 'r' ) );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param array $params Parameters to pass to the template
	 * @param Response $response The HTTP response.
	 * @return Response
	 */
	protected function outputsInitTemplate( array $params, Response $response ) {
		$defaultParams = [
			'iaId' => '',
			'commonsName' => '',
			'jobs' => [],
			'format' => 'pdf',
			'wiki_base_url' => $this->config['wiki_base_url'],
		];
		$params = array_merge( $defaultParams, $params );
		return $this->outputsTemplate( 'commons/init.twig', $params, $response );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param array $params Parameters to pass to the template
	 * @param Response $response The HTTP response.
	 * @return Response
	 */
	protected function outputsFillTemplate( array $params, Response $response ) {
		$defaultParams = [
			'iaId' => '',
			'commonsName' => '',
			'iaFileName' => '',
			'description' => '',
			'notes' => []

		];
		$params = array_merge( $defaultParams, $params );
		return $this->outputsTemplate( 'commons/fill.twig', $params, $response );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param string $templateName The template filename.
	 * @param array $params Parameters to pass to the template
	 * @param Response $response The HTTP response.
	 * @return Response
	 */
	protected function outputsTemplate( $templateName, array $params, Response $response ) {
		$defaultParams = [
			'debug' => $this->c->get( 'debug' ),
			'success' => '',
			'warning' => '',
			'error' => '',
			'user' => $this->c->get( 'session' )->get( 'user' ),
			'oauth_cid' => isset( $this->config[ 'consumerId' ] ) ? $this->config[ 'consumerId' ] : '',
		];
		$params = array_merge( $defaultParams, $params );
		return $this->c->get( 'view' )->render( $response, $templateName, $params );
	}

	/**
	 * Returns the file name of the requested file from the given IA metadata.
	 *
	 * @param array $data The IA metadata containing a 'files' key.
	 * @param string $fileType One of 'djvu', 'pdf', or 'zip'.
	 * @return string|bool The filename, or false if none could be found.
	 */
	protected function getIaFileName( $data, $fileType = 'djvu' ) {
		if ( $fileType === 'pdf' ) {
			$pdfFormats = [ 'Text PDF', 'Additional Text PDF', 'Image Container PDF' ];
			$largestPath = null;
			$largestSize = 0;
			foreach ( $data['files'] as $filePath => $fileInfo ) {
				if ( in_array( $fileInfo['format'], $pdfFormats ) && $fileInfo['size'] > $largestSize ) {
					$largestPath = $filePath;
					$largestSize = $fileInfo['size'];
				}
			}
			if ( $largestPath ) {
				return $largestPath;
			}
		} elseif ( $fileType === 'djvu' ) {
			foreach ( $data['files'] as $filePath => $fileInfo ) {
				if ( $fileInfo['format'] === 'DjVu' ) {
					return $filePath;
				}
			}
		} elseif ( $fileType === 'jp2' ) {
			// We only consider to have a jp2 file if we've also got a  *_djvu.xml to go
			// with it. Could perhaps instead check for $fileInfo['format'] === 'Abbyy GZ'?
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
	 * @param string $format The commons file format (pdf or djvu).
	 * @return array
	 */
	protected function createPageContent( $data, $format ) {
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
		$content .= '}}' . "\n";
		$content .= $format === 'pdf' ? '{{PDF}}' : '{{Djvu}}';
		$content .= "\n\n";
		$content .= '== {{int:license-header}} ==' . "\n" . '{{PD-scan}}' . "\n\n";
		$content .= '[[Category:Uploaded with IA Upload]]' . "\n";

		$isCategorised = false;
		$bookCatExists = isset( $data['metadata']['date'][0] ) && $this->commonsClient->pageExist(
			'Category:' . $data['metadata']['date'][0] . ' books'
		);
		if ( $bookCatExists ) {
			$content .= '[[Category:' . $data['metadata']['date'][0] . ' books]]' . "\n";
			$isCategorised = true;
		}
		$creatorCatExists = isset( $data['metadata']['creator'][0] ) && $this->commonsClient->pageExist(
			'Category:' . $data['metadata']['creator'][0]
		);
		if ( $creatorCatExists ) {
			$content .= '[[Category:' . $data['metadata']['creator'][0] . ']]' . "\n";
			$isCategorised = true;
		}
		if ( isset( self::$languageCategories[$language] ) ) {
			$format_caps = $format === 'pdf' ? 'PDF' : 'DjVu';
			$content .= '[[Category:'
				. $format_caps
				. ' files in '
				. self::$languageCategories[$language]
				. ']]'
				. "\n";
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
			$notes[] = $this->i18n->message( 'creator-template-missing', [ $creator ] );
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

		if ( preg_match( '/^[a-z]{2,3}$/', $language ) ) {
			return $language;
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
