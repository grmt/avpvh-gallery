<?php
/**
 * A local HTTP transport for the real scoped Google client.
 *
 * @package avpvh-gallery
 */

namespace Avpvh\Tests;

use Avpvh\API_Client;
use Avpvh\Frontend\Share_Drive;
use Avpvh\Vendor\Google\Client as Google_Client;
use Avpvh\Vendor\Google\Service\Drive;
use Avpvh\Vendor\GuzzleHttp\Client;
use Avpvh\Vendor\GuzzleHttp\HandlerStack;
use Avpvh\Vendor\GuzzleHttp\Promise\FulfilledPromise;
use Avpvh\Vendor\GuzzleHttp\Psr7\Response;
use Avpvh\Vendor\Psr\Http\Message\RequestInterface;
use ReflectionProperty;

/** Keeps authentication, request building and response parsing real, without network access. */
final class Google_Transport {

	/**
	 * Runs assertions with a synthetic Google HTTP response.
	 *
	 * @param callable(RequestInterface):Response $respond HTTP fixture.
	 * @param callable(Google_Client):void        $run Assertions.
	 * @return void
	 */
	public static function run( callable $respond, callable $run ) {
		$client = new Google_Client();
		$client->setHttpClient(
			new Client(
				array(
					'base_uri' => 'https://www.googleapis.com/',
					'handler'  => HandlerStack::create(
						static function ( RequestInterface $request ) use ( $respond ) {
							return new FulfilledPromise( $respond( $request ) );
						}
					),
				)
			)
		);
		$client->setAccessToken(
			array(
				'access_token' => 'fixture',
				'created'      => time(),
				'expires_in'   => 3600,
			)
		);
		$properties = array();
		$previous   = array();

		foreach ( array(
			'current_batch'    => null,
			'drive_client'     => null,
			'pending_requests' => array(),
			'raw_client'       => $client,
		) as $name => $value ) {
			$properties[] = new ReflectionProperty( API_Client::class, $name );
			$previous[]   = end( $properties )->getValue();
			end( $properties )->setValue( null, $value );
		}

		$properties[] = new ReflectionProperty( Share_Drive::class, 'drive' );
		$previous[]   = end( $properties )->getValue();
		end( $properties )->setValue( null, new Drive( $client ) );
		update_option( 'avpvh_access_token', $client->getAccessToken() );

		try {
			$run( $client );
		} finally {
			foreach ( $properties as $index => $property ) {
				$property->setValue( null, $previous[ $index ] );
			}
		}
	}

	/**
	 * A multipart response parsed by Google's actual batch implementation.
	 *
	 * @param RequestInterface    $request Batch request.
	 * @param int                 $status Status for each part.
	 * @param array<string,mixed> $payload JSON for each part.
	 * @return Response
	 */
	public static function batch( RequestInterface $request, $status, array $payload ) {
		preg_match_all( '/Content-ID: (\S+)/', (string) $request->getBody(), $matches );
		$body = '';

		foreach ( $matches[1] as $content_id ) {
			$body .= "--fixture\r\nContent-Type: application/http\r\nContent-ID: response-" . $content_id .
				"\r\n\r\nHTTP/1.1 " . $status . " Fixture\r\nContent-Type: application/json\r\n\r\n" .
				wp_json_encode( $payload ) . "\r\n";
		}

		return new Response(
			200,
			array( 'Content-Type' => 'multipart/mixed; boundary=fixture' ),
			$body . '--fixture--'
		);
	}
}
