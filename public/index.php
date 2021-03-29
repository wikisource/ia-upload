<?php

namespace IaUpload;

use DI\ContainerBuilder;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Slim\Middleware\Session;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Wikimedia\SimpleI18n\I18nContext;
use Wikimedia\SimpleI18n\JsonCache;
use Wikimedia\SimpleI18n\TwigExtension;
use Wikisource\IaUpload\Controller\OAuthController;
use Wikisource\IaUpload\Controller\UploadController;
use Wikisource\IaUpload\Middleware\ProxyDetection;

require_once __DIR__ . '/../vendor/autoload.php';

// Set memory limit to 256M to be sure that all files could be uploaded.
ini_set( 'memory_limit', '256M' );
date_default_timezone_set( 'UTC' );

$configFile = __DIR__ . '/../config.ini';
$config = parse_ini_file( $configFile );
if ( $config === false ) {
	echo "Unable to parse config file at $configFile";
	exit( 1 );
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions( [

	'config' => $config,

	'debug' => isset( $config['debug'] ) && $config['debug'],

	'logger' => function ( ContainerInterface $c ) {
		return new Logger( 'ia-upload' );
	},

	// Internationalisation.
	'i18n' => function ( ContainerInterface $c ) {
		return new I18nContext( new JsonCache( __DIR__ . '/../i18n' ) );
	},

	'view' => function ( ContainerInterface $c ) {
		$view = Twig::create( __DIR__ . '/../views' );
		$view->addExtension( new TwigExtension( $c->get( 'i18n' ) ) );
		return $view;
	},

	// Session helper.
	'session' => function ( ContainerInterface $c ) {
		return new \SlimSession\Helper();
	},

] );

// Create app.
$container = $containerBuilder->build();
AppFactory::setContainer( $container );
$app = AppFactory::create();
$routeParser = $app->getRouteCollector()->getRouteParser();

$app->addBodyParsingMiddleware();

// Sessions.
$app->add( function ( Request $request, RequestHandler $handler ) {
	$session = new Session( [
		'name' => 'ia-upload-session',
		// matches default $wgCookieExpiration
		'lifetime' => '30 days',
		'path' => '/',
		'httponly' => true,
		'secure' => $request->getUri()->getHost() !== 'localhost',
	] );
	return $session( $request, $handler );
} );

// Twig view middleware.
$app->add( TwigMiddleware::createFromContainer( $app ) );

// Correct for proxies in URI detection.
$app->add( new ProxyDetection() );

// Routing middleware.
$app->addRoutingMiddleware();

// Error middleware.
if ( $container->get( 'debug' ) ) {
	$app->addErrorMiddleware( true, true, true );
}

/**
 * Convenience method for UploadController.
 * @return UploadController
 */
function uploadController() {
	global $container, $routeParser;
	return new UploadController( $container, $routeParser );
}

/**
 * Convenience method for OAuthController.
 * @return OAuthController
 */
function oauthController() {
	global $container, $routeParser;
	return new OAuthController( $container, $routeParser );
}

$iaIdPattern = '[a-zA-Z0-9\._-]+';

$app->get( '/', function ( Request $request, Response $response ) {
	return uploadController()->init( $request, $response );
} )->setName( 'home' );

// @deprecated in favour of 'home'.
$app->get( '/commons/init', function ( Request $request, Response $response ) use ( $routeParser ) {
	$homeUrl = $routeParser->urlFor( 'home', [], $request->getQueryParams() );
	return $response
		->withHeader( 'Location', $homeUrl )
		->withStatus( 302 );
} );

$app->get( '/commons/fill', function ( Request $request, Response $response ) {
	return uploadController()->fill( $request, $response );
} )->setName( 'commons-fill' );

$app->post( '/commons/save', function ( Request $request, Response $response ) {
	return uploadController()->save( $request, $response );
} )->setName( 'commons-save' );

$app->get( "/log/{iaId:$iaIdPattern}", function ( Request $request, Response $response, $args ) {
	return uploadController()->logview( $request, $response, $args['iaId'] );
} )->setName( 'log' );

$app->get( "/{iaId:$iaIdPattern}.djvu", function ( Request $request, Response $response, $args ) {
	return uploadController()->downloadDjvu( $request, $response, $args['iaId'] );
} )->setName( 'djvu' );

$app->get( '/oauth/init', function ( Request $request, Response $response ) {
	return oauthController()->init( $request, $response );
} )->setName( 'oauth-init' );

$app->get( '/oauth/callback', function ( Request $request, Response $response ) {
	return oauthController()->callback( $request, $response );
} )->setName( 'oauth-callback' );

$app->get( '/logout', function ( Request $request, Response $response ) {
	return oauthController()->logout( $request, $response );
} )->setName( 'logout' );

$app->run();
