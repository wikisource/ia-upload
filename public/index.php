<?php

namespace IaUpload;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\Session;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Monolog\Logger;
use Wikimedia\SimpleI18n\I18nContext;
use Wikimedia\SimpleI18n\JsonCache;
use Wikimedia\SimpleI18n\TwigExtension;
use Wikisource\IaUpload\Controller\OAuthController;
use Wikisource\IaUpload\Controller\UploadController;

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

// Create app.
$container = new Container();
AppFactory::setContainer( $container );
$app = AppFactory::create();

// Config.
$container->set( 'config', $config );

// Debugging.
$container->set( 'debug', isset( $config['debug'] ) && $config['debug'] );

// Logging.
$container->set( 'logger', function() {
	return new Logger( 'ia-upload' );
} );

// Internationalisation.
$container->set( 'i18n', function() {
	return new I18nContext( new JsonCache( __DIR__ . '/../i18n' ) );
} );

// Views.
$container->set( 'view', function() use ( $container ) {
	$view = Twig::create(__DIR__ . '/../views');
	$view->addExtension( new TwigExtension( $container->get( 'i18n' ) ) );
	return $view;
} );

// Session helper.
$container->set( 'session', function() {
	return new \SlimSession\Helper();
} );

$app->addBodyParsingMiddleware();

// Ensure the tool is accessed over HTTPS.
$app->add( function ( Request $request, RequestHandler $handler ) {
	if ( $request->getHeaderLine( 'X-Forwarded-Proto' ) == 'http' ) {
		$uri = 'https://' . $request->getHost() . $request->getHeaderLine( 'X-Original-URI' );
		$response = new Response();
		return $response
			->withHeader( 'Location', $uri )
			->withStatus( 302 );
	}
	return $handler->handle( $request );
} );

// Add tool labs' IPs as trusted.
// See https://wikitech.wikimedia.org/wiki/Help:Tool_Labs/Web#Web_proxy_servers
$app->add( new \RKA\Middleware\IpAddress(
	true,
	[ '10.68.21.49', '10.68.21.81' ],
	null,
	[ 'X-Forwarded', 'X-Forwarded-For' ],
) );

// Sessions.
$app->add( function ( Request $request, RequestHandler $handler) {
	$session = new Session( [
		'name' => 'ia-upload-session',
		'lifetime' => '30 days', // matches default $wgCookieExpiration
		//'path' => $request->getBaseUrl() . '/',
		'httponly' => true,
		'secure' => $request->getUri()->getHost() !== 'localhost',
	] );
	return $session($request, $handler);
} );

// Twig view middleware.
$app->add( TwigMiddleware::createFromContainer( $app ) );

// Routes.
function uploadController( Container $app ) {
	return new UploadController( $app );
}
function oauthController( Container $app ) {
	return new OAuthController( $app );
}

$iaIdPattern = '[a-zA-Z0-9\._-]*';

$app->get( '/', function ( Request $request, Response $response ) {
	return uploadController( $this )->init( $request, $response );
} )->setName( 'home' );

// @deprecated in favour of 'home'.
$app->get( '/commons/init', function ( Request $request, Response $response ) use ( $app ) {
	$homeUrl = $app->getRouteCollector()->getRouteParser()->urlFor( 'home', null, $request->getQueryParams() );
	return $response
		->withHeader( 'Location', $homeUrl )
		->withStatus( 302 );
} );

$app->get( '/commons/fill', function ( Request $request, Response $response ) {
	return uploadController( $this )->fill( $request, $response );
} )->setName( 'commons-fill' );

$app->post( '/commons/save', function ( Request $request, Response $response ) {
	return uploadController( $this )->save( $request, $response );
} )->setName( 'commons-save' );

$app->get( "/log/{iaId:$iaIdPattern}", function ( Request $request, Response $response, $args ) {
	return uploadController( $this )->logview( $request, $response, $args['iaId'] );
} )->setName( 'log' );

$app->get( "/{iaId:$iaIdPattern}.djvu", function ( Request $request, Response $response, $args ) {
	return uploadController( $this )->downloadDjvu( $request, $response, $args['iaId'] );
} )->setName( 'djvu' );

$app->get( '/oauth/init', function ( Request $request, Response $response ) {
	return oauthController( $this )->init( $request, $response );
} )->setName( 'oauth-init' );

$app->get( '/oauth/callback', function ( Request $request, Response $response ) {
	return oauthController( $this )->callback( $request, $response );
} )->setName( 'oauth-callback' );

$app->get( '/logout', function ( Request $request, Response $response ) {
	return oauthController( $this )->logout( $request, $response );
} )->setName( 'logout' );

$app->run();
