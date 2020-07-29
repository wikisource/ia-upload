<?php

namespace Wikisource\IaUpload\Controller;

use Wikisource\IaUpload\OAuth\MediaWikiOAuth;
use Wikisource\IaUpload\OAuth\Token\ConsumerToken;
use Wikisource\IaUpload\OAuth\Token\RequestToken;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use DI\Container;
use Slim\Routing\RouteParser;

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
	protected $c;

	/**
	 * @var RouteParser
	 */
	protected $routeParser;

	/**
	 * @var MediaWikiOAuth
	 */
	protected $oAuthClient;

	/**
	 * OAuthController constructor.
	 * @param Container $c The Slim application's container.
	 * @param RouteParser $routeParser The Slim application's route parser.
	 */
	public function __construct( Container $c, RouteParser $routeParser ) {
		$this->c = $c;
		$this->routeParser = $routeParser;
		$config = $c->get( 'config' );
		$this->oAuthClient = new MediaWikiOAuth(
			$config['wiki_base_url'],
			new ConsumerToken( $config['consumerKey'], $config['consumerSecret'] )
		);
	}

	/**
	 * The first stage of the authentication process, which redirects the user to Commons.
	 * @param Request $request The HTTP request.
	 * @param Response $response The HTTP response.
	 * @return Response
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
	 * @param Response $response The HTTP response.
	 * @return Response
	 */
	public function callback( Request $request, Response $response ) {
		$session = $this->c->get( 'session' );
		$reqestToken = $session->get( 'request_token' );
		if ( !$reqestToken instanceof RequestToken ) {
			return $response->withStatus( 403, 'Unable to load request token from session' );
		}
		$verifier = $request->getQueryParams()['oauth_verifier'];
		$accessToken = [
			'value' => $this->oAuthClient->complete( $reqestToken, $verifier ),
			'version' => $this->c->get( 'config' )['consumerKey']
		];
		$session->set( 'access_token', $accessToken );
		$session->set( 'user', $this->oAuthClient->identify( $accessToken['value'] )->username );
		$session->delete( 'request_token' );
		// regenerate session id
		$session->id( true );
		return $response
			->withHeader( 'Location', $session->get( 'referer' ) )
			->withStatus( 302 );
	}

	/**
	 * Log out the current user.
	 * @param Request $request The HTTP request.
	 * @param Response $response The HTTP response.
	 * @return Response
	 */
	public function logout( Request $request, Response $response ) {
		$this->c->get( 'session' )->clear();
		return $response
			->withHeader( 'Location', $this->routeParser->urlFor( 'home' ) )
			->withStatus( 302 );
	}
}
