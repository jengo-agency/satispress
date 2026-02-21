<?php
/**
 * Composer packages.json endpoint.
 *
 * @package SatisPress
 * @license GPL-2.0-or-later
 * @since 0.3.0
 */

declare ( strict_types = 1 );

namespace SatisPress\Route;

use SatisPress\Capabilities;
use SatisPress\Exception\HttpException;
use SatisPress\HTTP\Request;
use SatisPress\HTTP\Response;
use SatisPress\HTTP\ResponseBody\JsonBody;
use SatisPress\Repository\PackageRepository;
use SatisPress\Transformer\PackageRepositoryTransformer;
use WP_Http as HTTP;

/**
 * Class for rendering packages.json for Composer.
 *
 * @since 0.3.0
 */
class Composer implements Route {
	/**
	 * Package repository.
	 *
	 * @var PackageRepository
	 */
	protected $repository;

	/**
	 * Repository transformer.
	 *
	 * @var PackageRepositoryTransformer
	 */
	protected $transformer;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param PackageRepository            $repository  Package repository.
	 * @param PackageRepositoryTransformer $transformer Package repository transformer.
	 */
	public function __construct( PackageRepository $repository, PackageRepositoryTransformer $transformer ) {
		$this->repository  = $repository;
		$this->transformer = $transformer;
	}

	/**
	 * Handle a request to the packages.json endpoint.
	 *
	 * @since 0.3.0
	 *
	 * @param Request $request HTTP request instance.
	 * @throws HttpException If the user doesn't have permission to view packages.
	 * @return Response
	 */
	public function handle( Request $request ): Response {
		if ( ! current_user_can( Capabilities::VIEW_PACKAGES ) ) {
			throw HttpException::forForbiddenResource();
		}

		$user_id       = get_current_user_id();
		$cache_version = get_option( 'satispress_packages_cache_version', '1' );
		$cache_key     = 'satispress_packages_' . $user_id . '_' . $cache_version;
		$cached_data   = get_transient( $cache_key );

		if ( false === $cached_data ) {
			$cached_data = $this->transformer->transform( $this->repository );
			set_transient( $cache_key, $cached_data, 12 * HOUR_IN_SECONDS );
		}

		$etag    = md5( wp_json_encode( $cached_data ) );
		$headers = [
			'Content-Type' => 'application/json; charset=' . get_option( 'blog_charset' ),
			'ETag'         => '"' . $etag . '"',
		];

		$if_none_match = $request->get_header( 'if-none-match' );
		if ( $if_none_match ) {
			$if_none_match = preg_replace( '/^W\//', '', $if_none_match );
			if ( trim( $if_none_match, '"' ) === $etag ) {
				return new Response(
					new \SatisPress\HTTP\ResponseBody\NullBody(),
					304,
					$headers
				);
			}
		}

		return new Response(
			new JsonBody( $cached_data ),
			HTTP::OK,
			$headers
		);
	}
}
