<?php

namespace Wikisource\IaUpload\Controller;

use Wikisource\IaUpload\OAuth\MediaWikiOAuth;
use Wikisource\IaUpload\OAuth\Token\ConsumerToken;
use Wikisource\IaUpload\OAuth\Token\RequestToken;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DI\Container;
use Slim\App;

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
	 * @var App
	 */
	protected $app;

	/**
	 * @var Container
	 */
	protected $c;

	/**
	 * @var MediaWikiOAuth
	 */
	protected $oAuthClient;

	const OAUTH_URL = 'https://commons.wikimedia.org/w/index.php';

	/**
	 * OAuthController constructor.
	 * @param App $app The Slim application.
	 * @param Container $c The Slim application container.
	 */
	public function __construct( App $app, Container $c ) {
		$this->app = $app;
		$this->c = $c;
		$config = $c->get( 'config' );
		$this->oAuthClient = new MediaWikiOAuth(
			self::OAUTH_URL,
			new ConsumerToken( $config['consumerKey'], $config['consumerSecret'] )
		);
	}

	/**
	 * The first stage of the authentication process, which redirects the user to Commons.
	 * @param Request $request The HTTP request.
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function init( Request $request, Response $response ) {
		$session = $this->c->get( 'session' );
		$query = $request->getQueryParams();
		$session->set( 'referer', $query['referer'] ?? '' );
		list( $redirectUri, $requestToken ) = $this->oAuthClient->initiate();
		$session->set( 'request_token', $requestToken );
		return $response
			->withHeader( 'Location', $redirectUri )
			->withStatus( 302 );
	}

	/**
	 * The action that the user is redirected to after authorizing at Commons.
	 * @param Request $request The HTTP request.
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function callback( Request $request, Response $response ) {
		$session = $this->c->get( 'session' );
		$reqestToken = $session->get( 'request_token' );
		if ( !$reqestToken instanceof RequestToken ) {
			return $response->withStatus( 403, 'Unable to load request token from session' );
		}
		$verifier = $request->getQueryParams()['oauth_verifier'];
		$accessToken = $this->oAuthClient->complete( $reqestToken, $verifier );
		$session->set( 'access_token', $accessToken );
		$session->set( 'user', $this->oAuthClient->identify( $accessToken )->username );
		$session->delete( 'request_token' );
		$session->id(true); // regenerate session id
		return $response
			->withHeader( 'Location', $session->get( 'referer' ) )
			->withStatus( 302 );
	}

	/**
	 * Log out the current user.
	 * @param Request $request The HTTP request.
	 * @return \Symfony\Component\HttpFoundation\RedirectResponse
	 */
	public function logout( Request $request, Response $response ) {
		$this->c->get( 'session' )->clear();
		return $response
			->withHeader( 'Location', $this->app->getRouteCollector()->getRouteParser()->urlFor( 'home' ) )
			->withStatus( 302 );
	}
}
