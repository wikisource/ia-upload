<?php

namespace IaUpload;

use IaUpload\OAuth\MediaWikiOAuth;
use IaUpload\OAuth\Token\ConsumerToken;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for OAuth login
 *
 * @file
 * @ingroup IaUpload
 *
 * @licence GNU GPL v2+
 */
class OAuthController {

	/**
	 * @var Application
	 */
	protected $app;

	/**
	 * @var MediaWikiOAuth
	 */
	protected $oAuthClient;

	const OAUTH_URL = 'https://commons.wikimedia.org/w/index.php';

	public function __construct( Application $app, array $config ) {
		$this->app = $app;
		$this->oAuthClient = new MediaWikiOAuth(
		    self::OAUTH_URL,
			new ConsumerToken( $config['consumerKey'], $config['consumerSecret'] )
		);
	}

	public function init( Request $request ) {
		$this->app['session']->set( 'referer', $request->get( 'referer', '' ) );
		list( $redirectUri, $requestToken ) = $this->oAuthClient->initiate();
		$this->app['session']->set( 'request_token', $requestToken );
		return $this->app->redirect( $redirectUri );
	}

	public function callback( Request $request ) {
	    $accessToken = $this->oAuthClient->complete(
			$this->app['session']->get( 'request_token' ),
	        $request->get( 'oauth_verifier' )
		);
		$this->app['session']->set( 'access_token', $accessToken );
		$this->app['session']->set( 'user', $this->oAuthClient->identify( $accessToken )->username );

		return $this->app->redirect( $this->app['session']->get( 'referer' ) );
	}
}
