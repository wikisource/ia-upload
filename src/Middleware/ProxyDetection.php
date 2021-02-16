<?php
/**
 * Modified from: https://github.com/akrabat/proxy-detection-middleware
 *
 * Copyright (c) 2015, Rob Allen (rob@akrabat.com)
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * The name of Rob Allen may not be used to endorse or promote products derived
 * from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace Wikisource\IaUpload\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ProxyDetection implements MiddlewareInterface {
	/**
	 * List of trusted proxy IP addresses
	 *
	 * If not empty, then one of these IP addresses must be in $_SERVER['REMOTE_ADDR']
	 * in order for the proxy headers to be looked at.
	 *
	 * @var array
	 */
	protected $trustedProxies;

	/**
	 * Constructor
	 *
	 * @param array $trustedProxies List of IP addresses of trusted proxies
	 */
	public function __construct( $trustedProxies = [] ) {
		$this->trustedProxies = $trustedProxies;
	}

	/**
	 * @param ServerRequestInterface $request PSR7 request
	 * @param RequestHandlerInterface $handler PSR7 handler
	 * @return ServerRequestInterface
	 *
	 * Override the request URI's scheme, host and port as determined from the proxy headers
	 */
	public function process(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler
	): ResponseInterface {
		if ( !empty( $this->trustedProxies ) ) {
			// get IP address from REMOTE_ADDR
			$ipAddress = null;
			$serverParams = $request->getServerParams();
			if ( isset( $serverParams['REMOTE_ADDR'] )
				&& $this->isValidIpAddress( $serverParams['REMOTE_ADDR'] ) ) {
				$ipAddress = $serverParams['REMOTE_ADDR'];
			}

			if ( !in_array( $ipAddress, $this->trustedProxies ) ) {
				return $handler->handle( $request );
			}
		}

		$uri = $request->getUri();

		$uri = $this->processProtoHeader( $request, $uri );
		$uri = $this->processPortHeader( $request, $uri );
		$uri = $this->processHostHeader( $request, $uri );

		$request = $request->withUri( $uri );

		return $handler->handle( $request );
	}

	/**
	 * Check that a given string is a valid IP address
	 *
	 * @param string $ip IP address to check
	 * @return bool
	 */
	protected function isValidIpAddress( $ip ) {
		$flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6;
		if ( filter_var( $ip, FILTER_VALIDATE_IP, $flags ) === false ) {
			return false;
		}
		return true;
	}

	/**
	 * @param ServerRequestInterface $request PSR7 request
	 * @param UriInterface $uri request URI
	 * @return UriInterface
	 */
	protected function processProtoHeader( ServerRequestInterface $request, UriInterface $uri ) {
		if ( $request->hasHeader( 'X-Forwarded-Proto' ) ) {
			$scheme = $request->getHeaderLine( 'X-Forwarded-Proto' );

			if ( in_array( $scheme, [ 'http', 'https' ] ) ) {
				return $uri->withScheme( $scheme );
			}
		}
		return $uri;
	}

	/**
	 * @param ServerRequestInterface $request PSR7 request
	 * @param UriInterface $uri request URI
	 * @return UriInterface
	 */
	protected function processPortHeader( ServerRequestInterface $request, UriInterface $uri ) {
		if ( $request->hasHeader( 'X-Forwarded-Port' ) ) {
			$port = trim( current( explode( ',', $request->getHeaderLine( 'X-Forwarded-Port' ) ) ) );

			if ( preg_match( '/^\d+\z/', $port ) ) {
				return $uri->withPort( (int)$port );
			}
		}
		return $uri;
	}

	/**
	 * @param ServerRequestInterface $request PSR7 request
	 * @param UriInterface $uri request URI
	 * @return UriInterface
	 */
	protected function processHostHeader( ServerRequestInterface $request, UriInterface $uri ) {
		if ( $request->hasHeader( 'X-Forwarded-Host' ) ) {
			$host = trim( current( explode( ',', $request->getHeaderLine( 'X-Forwarded-Host' ) ) ) );

			$port = null;
			if ( preg_match( '/^(\[[a-fA-F0-9:.]+\])(:\d+)?\z/', $host, $matches ) ) {
				$host = $matches[1];
				if ( $matches[2] ) {
					$port = (int)substr( $matches[2], 1 );
				}
			} else {
				$pos = strpos( $host, ':' );
				if ( $pos !== false ) {
					$port = (int)substr( $host, $pos + 1 );
					$host = strstr( $host, ':', true );
				}
			}
			$uri = $uri->withHost( $host );
			if ( $port ) {
				$uri = $uri->withPort( $port );
			}
		}
		return $uri;
	}
}
