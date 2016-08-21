<?php

namespace IaUpload;

use Silex\Application;
use Silex\Provider\MonologServiceProvider;
use Silex\Provider\SessionServiceProvider;
use Silex\Provider\TwigServiceProvider;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

ini_set( 'memory_limit', '256M' ); // set memory limit to 256M to be sure that all files could be uploaded
date_default_timezone_set( 'UTC' );

$config = parse_ini_file( __DIR__ . '/../config.ini' );

$app = new Application();
$app->register( new SessionServiceProvider(), [
	'session.storage.options' => [
		'cookie_lifetime' => 24*60*60
	]
] );
$app->register( new TwigServiceProvider(), [
	'twig.path' => __DIR__ . '/../views',
] );
$app->register( new MonologServiceProvider() );
$app['debug'] = isset( $config['debug'] ) && $config['debug'];

$commonController = new CommonsController( $app, $config );
$oauthController = new OAuthController( $app, $config );

$app->get( '/', function() use( $app ) {
	return $app->redirect( 'commons/init' );
} );

$app->get( 'commons/init', function( Request $request ) use ( $commonController ) {
	return $commonController->init( $request );
} )->bind( 'commons-init' );

$app->get( 'commons/fill', function( Request $request ) use ( $commonController ) {
	return $commonController->fill( $request );
} )->bind( 'commons-fill' );

$app->post( 'commons/save', function( Request $request ) use ( $commonController ) {
	return $commonController->save( $request );
} )->bind( 'commons-save' );

$app->get( 'oauth/init', function( Request $request ) use ( $oauthController ) {
	return $oauthController->init( $request );
} )->bind( 'oauth-init' );

$app->get( 'oauth/callback', function( Request $request ) use ( $oauthController ) {
	return $oauthController->callback( $request );
} )->bind( 'oauth-callback' );

$app->run();
