<?php

namespace IaUpload;

use Guzzle\Common\Exception\GuzzleException;
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
	 * @var Utils
	 */
	protected $utils;

	/**
	 * @var array
	 */
	protected $config;

	public function __construct( Application $app, array $config ) {
		$this->app = $app;
		$this->config = $config;

		$this->iaClient = IaClient::factory();
		$this->commonsClient = CommonsClient::factory( array(
			'consumer_key' => $this->config['consumerKey'],
			'consumer_secret' => $this->config['consumerSecret'],
			'token'           => $this->app['session']->get( 'token_key', '' ),
			'token_secret'    => $this->app['session']->get( 'token_secret', '' )
		) );
		$this->utils = new Utils();
	}

	public function init( Request $request ) {
		return $this->outputsInitTemplate( array(
			'iaId' => $request->get( 'iaId', '' ),
			'commonsName' => $request->get( 'commonsName', '' )
		) );
	}

	public function fill( Request $request ) {
		$iaId = $request->get( 'iaId', '' );
		$commonsName = $this->commonsClient->normalizePageTitle( $request->get( 'commonsName', '' ) );
		if( $iaId === '' || $commonsName === '' ) {
			return $this->outputsInitTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => 'You must set all the fields of the form !'
			) );
		}
		if( preg_match('/^(.*)\.djvu$/', $commonsName, $m ) || preg_match( '/^(.*)\.pdf$/', $commonsName, $m ) ) {
			$commonsName = $m[1];
		}
		if( !$this->commonsClient->isPageTitleValid( $commonsName ) ) {
			return $this->outputsInitTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => 'The image name "' . htmlspecialchars( $commonsName ) . '" is not valid file name'
			) );
		}

		try {
			$iaData = $this->iaClient->fileDetails( $iaId );
		} catch( GuzzleException $e ) {
			return $this->outputsInitTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => '<a href="http://archive.org/details/' . rawurlencode( $iaId ) . '">Book ' . htmlspecialchars( $iaId ) . '</a> not found in Internet Archive !'
			) );
		}
		$iaId = $iaData['metadata']['identifier'][0];
		$file = $this->getFileName( $iaData );
		if( $file === null ) {
			return $this->outputsInitTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => 'No DjVu or PDF file found !'
			) );
		}
		list($iaFileName, $fileType) = $file;
		$fullCommonsName = $commonsName . '.' . $fileType;

		if( $this->commonsClient->pageExist( 'File:' . $fullCommonsName ) ) {
			return $this->outputsInitTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'error' => '<a href="http://commons.wikimedia.org/wiki/File:' . rawurlencode( $fullCommonsName ) . '">A file with the name ' . htmlspecialchars( $fullCommonsName ) . '</a> already exist on Commons !'
			) );
		}
		$templateParams = array(
			'iaId' => $iaId,
			'commonsName' => $fullCommonsName,
			'iaFileName' => $iaFileName
		);
		if($fileType == 'pdf') {
			$templateParams['warning'] = 'The export tool will upload the pdf file';
		}
		list( $description, $notes ) = $this->createPageContent( $iaData );
		$templateParams['description'] = $description;
		$templateParams['notes'] = $notes;

		return $this->outputsFillTemplate( $templateParams );
	}

	public function save( Request $request ) {
		$iaId = $request->get( 'iaId', '' );
		$commonsName = $this->commonsClient->normalizePageTitle( $request->get( 'commonsName', '' ) );
		$iaFileName = $request->get( 'iaFileName', '' );
		$description = $request->get( 'description', '' );
		if($iaId === '' || $commonsName === '' || $iaFileName === '' || $description === '') {
			return $this->outputsfillTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'iaFileName' => $iaFileName,
				'description' => $description,
				'error' => 'You must set all the fields of the form !'
			) );
		}
		if( $this->commonsClient->pageExist( 'File:' . $commonsName ) ) {
			return $this->outputsfillTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'iaFileName' => $iaFileName,
				'description' => $description,
				'error' => '<a href="http://commons.wikimedia.org/wiki/File:' . rawurlencode( $commonsName ) . '">A file with the name ' . htmlspecialchars( $commonsName ) . '</a> already exist on Commons !'
			) );
		}

		$tempFile = __DIR__ . '/../../' . $this->config['tempDirectory'] . $iaFileName;
		try {
			$this->iaClient->downloadFile( $iaId . $iaFileName, $tempFile );
		} catch( GuzzleException $e ) {
			return $this->outputsfillTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'iaFileName' => $iaFileName,
				'description' => $description,
				'error' => '<a href="http://archive.org/details/' . rawurlencode( $iaId ) . '">File</a> not found in Internet Archive !'
			) );
		}

		try {
			$this->commonsClient->upload( $commonsName, $tempFile, $description, 'Importation from Internet Archive' );
		} catch( GuzzleException $e ) {
			unlink( $tempFile );
			return $this->outputsfillTemplate( array(
				'iaId' => $iaId,
				'commonsName' => $commonsName,
				'iaFileName' => $iaFileName,
				'description' => $description,
				'error' => 'The upload to WikimediaCommons failed: ' . $e->getMessage()
			) );
		}

		unlink( $tempFile );
		return $this->outputsInitTemplate( array(
			'success' => '<a href="http://commons.wikimedia.org/wiki/File:' . rawurlencode( $commonsName ) . '">The file</a> have been successfully uploaded to Commons !'
		) );
	}

	/**
	 * Outputs a template as response
	 *
	 * @param Application $app
	 * @param $templateName
	 * @param array $params
	 * @return Response
	 */
	protected function outputsInitTemplate( array $params ) {
		$defaultParams = array(
			'iaId' => '',
			'commonsName' => ''
		);
		$params = array_merge( $defaultParams, $params );
		return $this->outputsTemplate( 'commons/init.twig', $params );

	}

	/**
	 * Outputs a template as response
	 *
	 * @param Application $app
	 * @param $templateName
	 * @param array $params
	 * @return Response
	 */
	protected function outputsFillTemplate( array $params ) {
		$defaultParams = array(
			'iaId' => '',
			'commonsName' => '',
			'iaFileName' => '',
			'description' => '',
			'notes' => array()

		);
		$params = array_merge( $defaultParams, $params );
		return $this->outputsTemplate( 'commons/fill.twig', $params );

	}

	/**
	 * Outputs a template as response
	 *
	 * @param Application $app
	 * @param $templateName
	 * @param array $params
	 * @return Response
	 */
	protected function outputsTemplate( $templateName, array $params ) {
		$defaultParams = array(
			'success' => '',
			'warning' => '',
			'error' => ''
		);
		$params = array_merge( $defaultParams, $params );
		return $this->app['twig']->render( $templateName, $params );

	}

	/**
	 * Returns the file name to use and its extension
	 *
	 * @param array $data
	 * @return array|null
	 */
	protected function getFileName( $data ) {
		$djvu = null;
		$pdf = null;
		foreach( $data['files'] as $i => $info) {
			if( $info['format'] == 'DjVu' ) {
				$djvu = $i;
			} else if( $info['format'] == 'Text PDF' ) {
				$pdf = $i;
			}
		}
		if( $djvu !== null ) {
			return array($djvu, 'djvu');
		} else if( $pdf !== null ) {
			return array($pdf, 'pdf');
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
		global $languageCategories;

		$language = $this->utils->normalizeLanguageCode( $data['metadata']['language'][0] );
		$notes = array();
		$content = '== {{int:filedesc}} ==' . "\n" . '{{Book' . "\n";
		if( isset($data['metadata']['creator'][0]) ) {
			if( $this->commonsClient->pageExist( 'Creator:' . $data['metadata']['creator'][0] ) ) {
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
		if( isset($data['metadata']['title'][0]) ) {
			$content .= '| Title        = ' . $data['metadata']['title'][0] . "\n";
		} else {
			$content .= '| Title        = ' . "\n";
		}
		$content .= '| Subtitle     = ' . "\n";
		$content .= '| Series title = ' . "\n";
		if( isset($data['metadata']['volume'][0]) ) {
			$content .= '| Volume       = ' . $data['metadata']['volume'][0] . "\n";
		} else {
			$content .= '| Volume       = ' . "\n";
		}
		$content .= '| Edition      = ' . "\n";
		if( isset($data['metadata']['publisher'][0]) ) {
			$content .= '| Publisher    = ' . $data['metadata']['publisher'][0] . "\n";
		} else {
			$content .= '| Publisher    = ' . "\n";
		}
		$content .= '| Printer      = ' . "\n";
		if( isset($data['metadata']['date'][0]) ) {
			$content .= '| Date         = ' . $data['metadata']['date'][0] . "\n";
		} else {
			$content .= '| Date         = ' . "\n";
		}
		$content .= '| City         = ' . "\n";
		if( $language ) {
			$content .= '| Language     = {{language|' . $language . '}}' . "\n";
		} else {
			$content .= '| Language     = ' . "\n";
		}
		if( isset($data['metadata']['description'][0]) ) {
			$content .= '| Description  = ' . $data['metadata']['description'][0] . "\n";
		} else {
			$content .= '| Description  = ' . "\n";
		}
		$content .= '| Source       = {{IA|' . $data['metadata']['identifier'][0] . '}}' . "\n";
		if( isset( $data['metadata']['source'][0] ) ) {
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

		$isCategorised = false;
		if( isset($data['metadata']['date'][0]) && $this->commonsClient->pageExist( 'Category:' . $data['metadata']['date'][0] . ' books' ) ) {
			$content .= '[[Category:' . $data['metadata']['date'][0] . ' books]]' . "\n";
			$isCategorised = true;
		}
		if( isset($data['metadata']['creator'][0]) && $this->commonsClient->pageExist( 'Category:' . $data['metadata']['creator'][0] ) ) {
			$content .= '[[Category:' . $data['metadata']['creator'][0] . ']]' . "\n";
			$isCategorised = true;
		}
		if( isset($languageCategories[$language]) ) {
			$content .= '[[Category:DjVu files in ' .  $languageCategories[$language] . ']]' . "\n";
			$isCategorised = true;
		}
		if( !$isCategorised ) {
			$content = '{{subst:unc}}' . "\n\n" . $content;
		}
		return array( trim( $content ), $notes );
	}
}
//TODO: move
$languageCategories = array(
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
);