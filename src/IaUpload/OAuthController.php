<?php

namespace IaUpload;

use Guzzle\Http\Url;
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
	 * @var array
	 */
	protected $config;

	/**
	 * @var MediaWikiOAuthClient
	 */
	protected $oAuthClient;

	const OAUTH_URL = 'https://commons.wikimedia.org/w/index.php';

	public function __construct( Application $app, array $config ) {
		$this->app = $app;
		$this->config = $config;
		$this->oAuthClient = MediaWikiOAuthClient::factory( array(
			'base_url' => self::OAUTH_URL,
			'consumer_key' => $this->config['consumerKey'],
			'consumer_secret' => $this->config['consumerSecret'],
			'token'           => $this->app['session']->get( 'token_key', '' ),
			'token_secret'    => $this->app['session']->get( 'token_secret', '' )
		) );
	}

	public function init( Request $request ) {
		$token = $this->oAuthClient->getInitiationToken();
		$this->app['session']->set( 'token_key', $token['key'] );
		$this->app['session']->set( 'token_secret', $token['secret'] );

		$url = Url::factory( self::OAUTH_URL );
		$url->setQuery( array(
			'title' => 'Special:OAuth/authorize',
			'oauth_token' => $token['key'],
			'oauth_consumer_key' => $this->config['consumerKey']
		) );
		return $this->app->redirect( (string) $url );
	}

	public function callback( Request $request ) {
		$token = $this->oAuthClient->getFinalToken( $request->get( 'oauth_verifier' ) );
		$this->app['session']->set( 'token_key', $token['key'] );
		$this->app['session']->set( 'token_secret', $token['secret'] );

		return $this->app->redirect( 'commons/init' );
	}
} 