<?php

namespace IaUpload;

use IaUpload\OAuth\MediaWikiOAuth;
use IaUpload\OAuth\Token\ConsumerToken;
use IaUpload\OAuth\Token\RequestToken;
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

	/**
	 * OAuthController constructor.
	 * @param Application $app The Silex application.
	 * @param array $config The application configuration.
	 */
	public function __construct( Application $app, array $config ) {
		$this->app = $app;
		$this->oAuthClient = new MediaWikiOAuth(
			self::OAUTH_URL,
			new ConsumerToken( $config['consumerKey'], $config['consumerSecret'] )
		);
	}

	/**
	 * The first stage of the authentication process, which redirects the user to Commons to authenticate.
	 * @param Request $request The HTTP request.
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function init( Request $request ) {
		$this->app['session']->set( 'referer', $request->get( 'referer', '' ) );
		list( $redirectUri, $requestToken ) = $this->oAuthClient->initiate();
		$this->app['session']->set( 'request_token', $requestToken );
		return $this->app->redirect( $redirectUri );
	}

	/**
	 * The action that the user is redirected to after authorizing at Commons.
	 * @param Request $request The HTTP request.
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function callback( Request $request ) {
		$reqestToken = $this->app['session']->get( 'request_token' );
		if ( !$reqestToken instanceof RequestToken ) {
			$this->app->abort( 403, 'Unable to load request token from session' );
		}
		$verifier = $request->get( 'oauth_verifier' );
		$accessToken = $this->oAuthClient->complete( $reqestToken, $verifier );
		$this->app['session']->set( 'access_token', $accessToken );
		$this->app['session']->set( 'user', $this->oAuthClient->identify( $accessToken )->username );
		$this->app['session']->remove( 'request_token' );
		$this->app['session']->migrate();
		return $this->app->redirect( $this->app['session']->get( 'referer' ) );
	}

	/**
	 * Log out the current user.
	 * @param Request $request The HTTP request.
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function logout( Request $request ) {
		$this->app['session']->invalidate();
		return $this->app->redirect( $this->app['url_generator']->generate( 'home' ) );
	}
}
