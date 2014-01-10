<?php

namespace IaUpload;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

/**
 * Entry point for Commons upload process
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class EntryPoint {

	/**
	 * @var array
	 */
	protected $config;

	public function __construct() {
		$this->config = parse_ini_file( __DIR__ . '/../../config.ini' );
	}

	public function commonsInit( Request $request, Application $app ) {
		if ( !$app['session']->has( 'token_key', null ) ) {
			return $app->redirect( $app['url_generator']->generate( 'oauth-init' ) );
		}

		$controller = new CommonsController( $app, $this->config );
		return $controller->init( $request );
	}

	public function commonsFill( Request $request, Application $app ) {
		if ( !$app['session']->has( 'token_key', null ) ) {
			return $app->redirect( $app['url_generator']->generate( 'oauth-init' ) );
		}

		$controller = new CommonsController( $app, $this->config );
		return $controller->fill( $request );
	}

	public function commonsSave( Request $request, Application $app ) {
		if ( !$app['session']->has( 'token_key', null ) ) {
			return $app->redirect( $app['url_generator']->generate( 'oauth-init' ) );
		}

		$controller = new CommonsController( $app, $this->config );
		return $controller->save( $request );
	}

	public function oAuthInit( Request $request, Application $app ) {
		$controller = new OAuthController( $app, $this->config );
		return $controller->init( $request );
	}

	public function oAuthCallback( Request $request, Application $app ) {
		$controller = new OAuthController( $app, $this->config );
		return $controller->callback( $request );
	}
} 