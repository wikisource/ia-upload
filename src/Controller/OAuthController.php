<?php

namespace Wikisource\IaUpload\Controller;

use Wikisource\IaUpload\OAuth\MediaWikiOAuth;
use Wikisource\IaUpload\OAuth\Token\ConsumerToken;
use Wikisource\IaUpload\OAuth\Token\RequestToken;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DI\Container;

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
	 * @var Container
	 */
	protected $app;

	/**
	 * @var MediaWikiOAuth
	 */
	protected $oAuthClient;

	const OAUTH_URL = 'https://commons.wikimedia.org/w/index.php';

	/**
	 * OAuthController constructor.
	 * @param Container $app The Slim application container.
	 */
	public function __construct( Container $app ) {
		$this->app = $app;
		$config = $app->get( 'config' );
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
		$session = $this->app->get( 'session' );
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
		$session = $this->app->get( 'session' );
		$reqestToken = $session->get( 'request_token' );
		if ( !$reqestToken instanceof RequestToken ) {
			return $response->withStatus( 403, 'Unable to load request token from session' );
		}
		$verifier = $request->getQueryParams()['oauth_verifier'];
		$accessToken = $this->oAuthClient->complete( $reqestToken, $verifier );
		$session->set( 'access_token', $accessToken );
		$session->set( 'user', $this->oAuthClient->identify( $accessToken )->username );
		$session->remove( 'request_token' );
		$session->migrate();
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
		$this->app->get( 'session' )->invalidate();
		return $response
			->withHeader( 'Location', $this->app->getRouteCollector()->getRouteParser()->urlFor( 'home' ) )
			->withStatus( 302 );
	}
}
