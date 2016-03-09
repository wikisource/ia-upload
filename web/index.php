<?php

require_once __DIR__ . '/../vendor/autoload.php';

ini_set( 'memory_limit', '256M' ); // set memory limit to 256M to be sure that all files could be uploaded

$app = new Silex\Application();
$app->register( new Silex\Provider\SessionServiceProvider() );
$app->register( new Silex\Provider\UrlGeneratorServiceProvider() );
$app->register( new Silex\Provider\TwigServiceProvider(), array(
	'twig.path' => __DIR__ . '/../views',
) );
$app['debug'] = true; //TODO remove

$app->get( '/', function() use( $app ) {
	return $app->redirect( 'commons/init' );
} );

$app->get( 'commons/init', 'IaUpload\\EntryPoint::commonsInit' )->bind( 'commons-init' );
$app->get( 'commons/fill', 'IaUpload\\EntryPoint::commonsFill' )->bind( 'commons-fill' );
$app->post( 'commons/save', 'IaUpload\\EntryPoint::commonsSave' )->bind( 'commons-save' );
$app->get( 'oauth/init', 'IaUpload\\EntryPoint::oAuthInit' )->bind( 'oauth-init' );
$app->get( 'oauth/callback', 'IaUpload\\EntryPoint::oAuthCallback' )->bind( 'oauth-callback' );

$app->run();
