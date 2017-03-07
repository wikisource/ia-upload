<?php

namespace IaUpload;

use Exception;
use IaUpload\OAuth\MediaWikiOAuth;
use IaUpload\OAuth\Token\ConsumerToken;
use Mediawiki\Api\Guzzle\ClientFactory;
use Silex\Application;
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
		'en' => 'English‎',
		'et' => 'Estonian',
		'fr' => 'French',
		'de' => 'German‎',
		'el' => 'Greek‎',
		'he' => 'Hebrew‎',
		'hu' => 'Hungarian',
		'id' => 'Indonesian',
		'ga' => 'Irish',
		'it' => 'Italian‎',
		'la' => 'Latin',
		'ml' => 'Malayalam',
		'nb' => 'Norwegian‎',
		'oc' => 'Occitan‎',
		'fa' => 'Persian',
		'pl' => 'Polish‎',
		'ro' => 'Romanian',
		'ru' => 'Russian',
		'sl' => 'Slovenian',
		'es' => 'Spanish',
		'sv' => 'Swedish',
		'uk' => 'Ukrainian',
		'vi' => 'Venetian‎'
	];

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
	 *
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
	 * @param Request $request
	 * @return Response
	 */
	public function init( Request $request ) {
		$jobs = [];
		foreach ( glob( $this->getJobDirectory() . '/*/job.json' ) as $jobFile ) {
			$jobInfo = \GuzzleHttp\json_decode( file_get_contents( $jobFile ) );
			$jobInfo->locked = file_exists( dirname( $jobFile ) . '/lock' );
			$jobs[] = $jobInfo;
		}
		return $this->outputsInitTemplate( [
			'iaId' => $request->get( 'iaId', '' ),
			'commonsName' => $request->get( 'commonsName', '' ),
		    'jobs' => $jobs,
		] );
	}

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
			$link = '<a href="http://archive.org/details/' . rawurlencode( $iaId ) . '">'
				. htmlspecialchars( $iaId )
				. '</a>';
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => $this->app['i18n']->message( 'no-found-on-ia', [ $link ] ),
			] );
		}
		$iaId = $iaData['metadata']['identifier'][0];

		// See if a IA DjVu or PDF file exists.
		$hasDjvu = true;
		$iaFileName = $this->getDjvuFileName( $iaData );
		if ( strlen( $iaFileName ) < 1 ) {
			$hasDjvu = false;
		}
		$fullCommonsName = $commonsName . '.djvu';

		if ( $this->commonsClient->pageExist( 'File:' . $fullCommonsName ) ) {
			$link = '<a href="http://commons.wikimedia.org/wiki/File:' . rawurlencode( $fullCommonsName ) . '">'
				. htmlspecialchars( $fullCommonsName )
				. '</a>';
			return $this->outputsInitTemplate( [
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => $this->app['i18n']->message( 'already-on-commons', [ $link ] ),
			] );
		}
		$templateParams = [
			'iaId' => $iaId,
			'commonsName' => $fullCommonsName,
			'iaFileName' => $iaFileName,
			'hasDjvu' => $hasDjvu,
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
	 * @param Request $request
	 * @return Response
	 */
	public function save( Request $request ) {
		// Get all form inputs.
		$jobInfo = [
			'iaId' => $request->get( 'iaId' ),
			'commonsName' => $this->commonsClient->normalizePageTitle( $request->get( 'commonsName' ) ),
			'iaFileName' => $request->get( 'iaFileName' ),
			'description' => $request->get( 'description' ),
			'fileSource' => $request->get( 'fileSource', 'jp2' ),
			'hasDjvu' => $request->get( 'hasDjvu', 0 ) === 'yes',
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
				. htmlspecialchars( $iaId )
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
			return $this->app->redirect( $this->app["url_generator"]->generate( 'commons-init' ) );
		}

		// Use IA DjVu file (don't add it to the queue, as this shouldn't take too long).
		if ( $jobInfo['fileSource'] === 'djvu' ) {
			$remoteDjVuFile = $jobInfo['iaId'] . $jobInfo['iaFileName'];
			$localDjVuFile = $jobDirectory . '/' . $jobInfo['iaFileName'];
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
			return $this->outputsInitTemplate( [
				'success' => 'The file <a href="http://commons.wikimedia.org/wiki/File:'
					. rawurlencode( $jobInfo['commonsName'] ) . '">'
					. rawurlencode( $jobInfo['commonsName'] )
					. '</a> has been successfully uploaded to Commons!'
			] );
		}

	}

	/**
	 * Display the log of a given job.
	 * @param Request $request
	 * @param string $iaId
	 */
	public function logview( Request $request, $iaId ) {
		// @todo Not duplicate the log name between here and JobsCommand.
		$logFile = $this->getJobDirectory( $iaId ) . '/log.txt';
		$log = ( file_exists( $logFile ) ) ? file_get_contents( $logFile ) : 'No log available.';
		return new Response( $log, 200, [ 'Content-Type' => 'text/plain' ] );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param array $params
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
	 * @param array $params
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
	 * @param $templateName
	 * @param array $params
	 * @return Response
	 */
	protected function outputsTemplate( $templateName, array $params ) {
		$defaultParams = [
			'debug' => $this->app['debug'],
			'success' => '',
			'warning' => '',
			'error' => '',
			'user' => $this->app['session']->get( 'user' )
		];
		$params = array_merge( $defaultParams, $params );
		return $this->app['twig']->render( $templateName, $params );

	}

	/**
	 * Returns the file name to use
	 *
	 * @param array $data
	 * @return string null nothing found, caller should abort, '', should call PDF -> DjVu converter, not empty string the DjVu file name
	 */
	protected function getDjvuFileName( $data ) {
		$djvu = null;
		$pdf = null;
		foreach ( $data['files'] as $i => $info ) {
			if ( $info['format'] === 'DjVu' ) {
				$djvu = $i;
			} elseif ( $info['format'] === 'Additional Text PDF' || $info['format'] === 'Text PDF' ) {
				$pdf = $i;
			}
		}
		if ( $djvu !== null ) {
			return $djvu;
		} elseif ( $pdf !== null ) {
			return '';
		} else {
			return null;
		}
	}

	/**
	 * Creates the content of the description page
	 *
	 * @param array $data
	 * @return array
	 */
	protected function createPageContent( $data ) {
		$language = isset( $data['metadata']['language'][0] )
			? $this->normalizeLanguageCode( $data['metadata']['language'][0] )
			: '';
		$notes = [];
		$content = '== {{int:filedesc}} ==' . "\n" . '{{Book' . "\n";
		if ( isset( $data['metadata']['creator'][0] ) ) {
			if ( $this->commonsClient->pageExist( 'Creator:' . $data['metadata']['creator'][0] ) ) {
				$content .= '| Author       = {{Creator:' . $data['metadata']['creator'][0] . '}}' . "\n";
			} else {
				$notes[] = 'The author "' . $data['metadata']['creator'][0] . '" doesn’t have a <a href="http://commons.wikimedia.org/wiki/Commons:Creator">creator</a> template. Isn’t he known under an other name or do you want to <a href="http://commons.wikimedia.org/w/index.php?title=' . rawurlencode( $data['metadata']['creator'][0] ). '&action=edit&preload=Template:Creator/preload">create it</a> ?';
				$content .= '| Author       = ' . $data['metadata']['creator'][0] . "\n";
			}
		} else {
			$content .= '| Author       = ' . "\n";
		}
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
		$content .= '| Source       = {{IA|' . $data['metadata']['identifier'][0] . '}}' . "\n";
		if ( isset( $data['metadata']['source'][0] ) ) {
			$content .= '<br />Internet Archive source: ' . $data['metadata']['source'][0] . "\n";
		}
		$content .= '| Image        = {{PAGENAME}}' . "\n";
		$content .= '| Image page   = ' . "\n";
		$content .= '| Permission   = ' . "\n";
		$content .= '| Other versions = ' . "\n";
		$content .= '| Wikisource   = s:' . $language . ':Index:{{PAGENAME}}' . "\n";
		$content .= '| Homecat      = ' . "\n";
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
	 * Normalize a language code
	 * Very hacky
	 *
	 * @param string $language
	 * @return string
	 */
	private function normalizeLanguageCode( $language ) {
		$language = strtolower( $language );

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
