<?php
/**
 * This file is part of CopyPatrol application
 * Copyright (C) 2016  Niharika Kohli and contributors
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Niharika Kohli <nkohli@wikimedia.org>
 * @author Bryan Davis <bdavis@wikimedia.org>
 * @copyright © 2016 Niharika Kohli and contributors.
 */
namespace Plagiabot\Web;

use Wikimedia\Slimapp\AbstractApp;
use Wikimedia\Slimapp\Config;
use Wikimedia\Slimapp\HeaderMiddleware;
use Wikimedia\Slimapp\Auth\AuthManager;
use Less_Cache;

class App extends AbstractApp {

	/**
	 * Apply settings to the Slim application.
	 *
	 * @param \Slim\Slim $slim Application
	 */
	protected function configureSlim( \Slim\Slim $slim ) {
		$slim->config(
			[ 'displayErrorDetails' => true,
			  'debug' => true,
			  'oauth.enable' => Config::getBool( 'USE_OAUTH', false ),
			  'oauth.consumer_token' => Config::getStr( 'OAUTH_CONSUMER_TOKEN' ),
			  'oauth.secret_token' => Config::getStr( 'OAUTH_SECRET_TOKEN' ),
			  'oauth.endpoint' => Config::getStr( 'OAUTH_ENDPOINT' ),
			  'oauth.redir' => Config::getStr( 'OAUTH_REDIR' ),
			  'oauth.callback' => Config::getStr( 'OAUTH_CALLBACK' ),
			  'db.dsnwp' => Config::getStr( 'DB_DSN_WIKIPROJECT' ),
			  'db.dsnen' => Config::getStr( 'DB_DSN_ENWIKI' ),
			  'db.dsnpl' => Config::getStr( 'DB_DSN_PLAGIABOT' ),
			  'db.user' => Config::getStr( 'DB_USER' ),
			  'db.pass' => Config::getStr( 'DB_PASS' ),
			  'templates.path' => '../public_html/templates'
			]
		);
	}


	/**
	 * Pre-prepare our class objects for use, with the appropriate parameters
	 * They can now be accessed directly as, for example, $slim->oauthClient
	 * anywhere which has access to the $slim object
	 *
	 * @param \Slim\Helper\Set $container IOC container
	 */
	protected function configureIoc( \Slim\Helper\Set $container ) {
		// OAuth Config
		$container->singleton( 'oauthConfig', function ( $c ) {
			$conf = new \MediaWiki\OAuthClient\ClientConfig(
				$c->settings['oauth.endpoint']
			);
			$conf->setRedirUrl( $c->settings['oauth.redir'] );
			$conf->setConsumer(
				new \MediaWiki\OAuthClient\Consumer(
					$c->settings['oauth.consumer_token'],
					$c->settings['oauth.secret_token']
				) );
			return $conf;
		} );
		// OAuth Client
		$container->singleton( 'oauthClient', function ( $c ) {
			$client = new \MediaWiki\OAuthClient\Client(
				$c->oauthConfig,
				$c->log
			);
			$client->setCallback( $c->settings['oauth.callback'] );
			return $client;
		} );
		// Plagiabot DAO
		$container->singleton( 'plagiabotDao', function ( $c ) {
			return new Dao\PlagiabotDao(
				$c->settings['db.dsnpl'],
				$c->settings['db.user'], $c->settings['db.pass']
			);
		} );
		// En.wikipedia DAO
		$container->singleton( 'enwikiDao', function ( $c ) {
			return new Dao\EnwikiDao(
				$c->settings['db.dsnen'],
				$c->settings['db.user'], $c->settings['db.pass']
			);
		} );
		// Wikiproject DAO
		$container->singleton( 'wikiprojectDao', function ( $c ) {
			return new Dao\WikiprojectDao(
				$c->settings['db.dsnwp'],
				$c->settings['db.user'], $c->settings['db.pass']
			);
		} );
		// User manager
		$container->singleton( 'userManager', function ( $c ) {
			return new Controllers\OAuthUserManager(
				$c->oauthClient,
				$c->log
			);
		} );
		//
		$container->singleton( 'authManager', function ( $c ) {
			return new AuthManager( $c->userManager );
		} );
	}


	/**
	 * Configure view behavior.
	 *
	 * @param \Slim\View $view Default view
	 */
	protected function configureView( \Slim\View $view ) {
		$view->replace( [ 'app' => $this->slim, ] );
		$view->parserExtensions = [
			new \Slim\Views\TwigExtension()
		];
	}


	/**
	 * Configure routes to be handled by application.
	 *
	 * @param \Slim\Slim $slim Application
	 */
	protected function configureRoutes( \Slim\Slim $slim ) {
		$middleware = array(
			'must-revalidate' => function () use ( $slim ) {
				$slim->response->headers->set(
					'Cache-Control', 'private, must-revalidate, max-age=0'
				);
				$slim->response->headers->set(
					'Expires', 'Thu, 01 Jan 1970 00:00:00 GMT'
				);
			},
			'inject-user' => function () use ( $slim ) {
				$user = $slim->authManager->getUserData();
				$slim->view->set( 'user', $user );
			}
		);
		$slim->group( '/', $middleware['inject-user'],
			function () use ( $slim ) {
				$slim->get( '', function () use ( $slim ) {
					$page = new Controllers\CopyPatrol( $slim );
					$page->setDao( $slim->plagiabotDao );
					$page->setEnwikiDao( $slim->enwikiDao );
					$page->setWikiprojectDao( $slim->wikiprojectDao );

					// determine if we are on the staging environment, so we can show a banner in the view
					$rootUri = $slim->request->getRootUri();
					$slim->view->set( 'staging', preg_match( '/\/plagiabot/', $rootUri ) );

					$page();
				} )->name( 'home' );
				$slim->get( 'login', function () use ( $slim ) {
					$slim->render( 'login.html' );
				} )->name( 'login' );
				$slim->get( 'logout', function () use ( $slim ) {
					$slim->authManager->logout();
					$slim->redirect( $slim->urlFor( 'home' ) );
				} )->name( 'logout' );
				$slim->get( 'loadmore', function () use ( $slim ) {
					$lastId = $slim->request->get( 'lastId' );
					$page = new Controllers\CopyPatrol( $slim );
					$page->setDao( $slim->plagiabotDao );
					$page->setEnwikiDao( $slim->enwikiDao );
					$page->setWikiprojectDao( $slim->wikiprojectDao );
					$page( $lastId );
				} )->name( 'loadmore' );
				$slim->get( 'index.css', function () use ( $slim ) {
					// Compile LESS if need be, otherwise serve cached asset
					// Cached files get automatically deleted if they are over a week old
					// The value for the .less key defines the root of assets within the Less
					//   e.g. /copypatrol/ makes url('image.gif') route to /copypatrol/image.gif
					$rootUri = $slim->request->getRootUri();
					$lessFiles = array( APP_ROOT . '/src/Less/index.less' => $rootUri . '/' );
					$options = array( 'cache_dir' => APP_ROOT . '/src/Less/cache' );
					$cssFileName = Less_Cache::Get( $lessFiles, $options );
					$slim->response->headers->set( 'Content-Type', 'text/css' );
					$slim->response->setBody( file_get_contents( APP_ROOT . '/src/Less/cache/' . $cssFileName ) );
				} )->name( 'index.css' );
			} );
		$slim->group( '/review/',
			function () use ( $slim ) {
				$slim->get( 'add', function () use ( $slim ) {
					// TODO: Ideally the authentication step should be taken care of by a middleware
					// but I haven't found a way to make it work with AJAX requests so far
					if ( $slim->authManager->isAuthenticated() ) {
						$page = new Controllers\Review( $slim );
						$page->setDao( $slim->plagiabotDao );
						$page();
					} else {
						echo json_encode( array( 'error' => 'Unauthorized' ) );
					}
				} )->name( 'add_review' );
			} );
		$slim->group( '/oauth/',
			function () use ( $slim ) {
				$slim->get( '', function () use ( $slim ) {
					$page = new Controllers\AuthHandler( $slim );
					$page->setOAuth( $slim->oauthClient );
					$page( 'init' );
				} )->name( 'oauth_init' );
				$slim->get( 'callback', function () use ( $slim ) {
					$page = new Controllers\AuthHandler( $slim );
					$page->setOAuth( $slim->oauthClient );
					$page->setUserManager( $slim->userManager );
					$page( 'callback' );
				} )->name( 'oauth_callback' );
			} );
	}


	/**
	 * Customize header middleware for app
	 *
	 * @return \Wikimedia\Slimapp\HeaderMiddleware
	 */
	protected function configureHeaderMiddleware() {
		return array(
			'Vary' => 'Cookie',
			'X-Frame-Options' => 'DENY',
			'Content-Security-Policy' =>
				"default-src 'self' *; " .
				"frame-src 'self'; " .
				"object-src 'self'; " .
				// Needed for css data:... sprites
				"img-src 'self' data:; " .
				// Needed for jQuery and Modernizr feature detection
				"style-src 'self' * 'unsafe-inline';" .
				"script-src 'self' * 'unsafe-exec' 'unsafe-inline'",
			// Don't forget to override this for any content that is not
			// actually HTML (e.g. json)
			'Content-Type' => 'text/html; charset=UTF-8',
		);
	}

}