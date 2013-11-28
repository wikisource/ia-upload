<?php

require_once __DIR__ . '/../vendor/autoload.php';

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

$app->get( 'commons/init', 'IaUpload\\Commons::init' )->bind( 'commons-init' );
$app->get( 'commons/fill', 'IaUpload\\Commons::fill' )->bind( 'commons-fill' );
$app->post( 'commons/save', 'IaUpload\\Commons::save' )->bind( 'commons-save' );

$app->run();