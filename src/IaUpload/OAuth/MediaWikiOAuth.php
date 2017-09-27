<?php

namespace IaUpload\OAuth;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use IaUpload\OAuth\Token\AccessToken;
use IaUpload\OAuth\Token\ConsumerToken;
use IaUpload\OAuth\Token\RequestToken;
use IaUpload\OAuth\Token\Token;
use Mediawiki\Api\MediawikiApi;

/**
 * @since 0.1
 *
 * @author Thomas Pellissier Tanon
 *
 * Code inspired from https://github.com/wikimedia/mediawiki-oauthclient-php/blob/master/src/Client.php
 */
class MediaWikiOAuth {

	/**
	 * @var string
	 */
	private $baseUri;

	/**
	 * @var ConsumerToken
	 */
	private $consumerToken;

	/** @var string The user-agent with which to identify this tool. */
	protected $userAgent = 'wikisource/ia-upload';

	/**
	 * @param string $baseUri The URI of the index.php file of the wiki like 'https://commons.wikimedia.org/w/index.php'
	 * @param ConsumerToken $consumerToken The consumer token.
	 */
	public function __construct( $baseUri, ConsumerToken $consumerToken ) {
		$this->baseUri = $baseUri;
		$this->consumerToken = $consumerToken;
	}

	/**
	 * First part of 3-legged OAuth, get the request Token.
	 *
	 * After calling this method you should redirect your authorizing users to the redirect url,
	 * and keep track of the request token since you need to pass it into complete()
	 *
	 * @return array [redirect URI, request token]
	 * @throws MediaWikiOAuthException
	 */
	public function initiate() {
		$result = $this->doOAuthJsonRequest( null, [
			'title' => 'Special:OAuth/initiate',
			'oauth_callback' => 'oob'
		] );
		if ( !array_key_exists( 'oauth_callback_confirmed', $result ) || $result['oauth_callback_confirmed'] !== 'true' ) {
			throw new MediaWikiOAuthException( '', 'Callback was not confirmed' );
		}
		$requestToken = new RequestToken( $result['key'], $result['secret'] );

		$redirectUri = new Uri( $this->baseUri );
		$redirectUri = $redirectUri->withQuery( \GuzzleHttp\Psr7\build_query( [
			'title' => 'Special:OAuth/authenticate',
			'oauth_token' => $requestToken->getKey(),
			'oauth_consumer_key' => $this->consumerToken->getKey()
		] ) );
		return [ (string)$redirectUri, $requestToken ];
	}

	/**
	 * The final leg of the OAuth handshake.
	 *
	 * Exchange the request Token from initiate() and the verification code that the user submitted back to you
	 * for an access token which you'll use for all API calls.
	 *
	 * @param RequestToken $requestToken Request token obtained from initiate
	 * @param string $verifyCode Authorization code sent to the callback URL (oauth_verifier query parameter)
	 *
	 * @return AccessToken The access token
	 * @throws MediaWikiOAuthException
	 */
	public function complete( RequestToken $requestToken, $verifyCode ) {
		$result = $this->doOAuthJsonRequest( $requestToken, [
			'title' => 'Special:OAuth/token',
			'format' => 'json',
			'oauth_verifier' => $verifyCode
		] );
		return new AccessToken( $result['key'], $result['secret'] );
	}

	/**
	 * Optional step. This call the MediaWiki specific /identify method, which
	 * returns a signed statement of the authorizing user's identity. Use this
	 * if you are authenticating users in your application, and you need to
	 * know their username, groups, rights, etc in MediaWiki.
	 *
	 * @param AccessToken $accessToken Access token from complete()
	 * @return object containing attributes of the user
	 */
	public function identify( AccessToken $accessToken ) {
		$result = $this->doOAuthRequest( $accessToken, [
			'title' => 'Special:OAuth/identify'
		] );
		return JWT::decode( $result, $this->consumerToken->getSecret(), [ 'HS256' ] );
	}

	/**
	 * @param string $apiUrl The API Url
	 * @param AccessToken $accessToken The access token
	 * @return MediawikiApi
	 */
	public function buildMediawikiApiFromToken( $apiUrl, AccessToken $accessToken ) {
		return new MediawikiApi( $apiUrl, $this->buildMediawikiClientFromToken( $accessToken ) );
	}

	/**
	 * @deprecated Useful only because MediawikiApi is not able to do multipart POST requests
	 * @param AccessToken $accessToken The stored access token.
	 * @return Client
	 */
	public function buildMediawikiClientFromToken( AccessToken $accessToken ) {
		$stack = HandlerStack::create();
		$stack->push( $this->buildOAuth1MiddlewareFromToken( $accessToken ) );
		return new Client( [
			'auth' => 'oauth',
			'cookies' => true,
			'handler' => $stack,
			'headers' => [ 'User-Agent' => $this->userAgent ]
		] );
	}

	private function doOAuthJsonRequest( Token $token = null, array $params ) {
		$params['format'] = 'json';

		$result = \GuzzleHttp\json_decode( $this->doOAuthRequest( $token, $params ), true );

		if ( array_key_exists( 'error', $result ) ) {
			throw new MediaWikiOAuthException( $result['error'], $result['message'], $result );
		}

		return $result;
	}

	private function doOAuthRequest( Token $token = null, array $params ) {
		return $this->buildClientFromToken( $token )->get( '', [
			'query' => $params
		] )->getBody();
	}

	private function buildClientFromToken( Token $token = null ) {
		$stack = HandlerStack::create();
		$stack->push( $this->buildOAuth1MiddlewareFromToken( $token ) );
		return new Client( [
			'base_uri' => $this->baseUri,
			'headers' => [ 'User-Agent' => $this->userAgent ],
			'handler' => $stack,
			'auth' => 'oauth'
		] );
	}

	private function buildOAuth1MiddlewareFromToken( Token $token = null ) {
		$oAuthConfig = [
			'consumer_key' => $this->consumerToken->getKey(),
			'consumer_secret' => $this->consumerToken->getSecret(),
			'token' => '',
			'token_secret' => ''
		];
		if ( $token !== null ) {
			$oAuthConfig['token'] = $token->getKey();
			$oAuthConfig['token_secret'] = $token->getSecret();
		}
		return new Oauth1( $oAuthConfig );
	}
}
