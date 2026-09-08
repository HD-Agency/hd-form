<?php
/**
 * Dynamic Field Options REST API
 *
 * GET /wp-json/hd/v1/form/dynamic-options
 *
 * Public read-only metadata endpoint for dynamic form dropdowns.
 * It returns only whitelisted post/page/category labels and IDs, applies a
 * small per-IP rate limit, and caches normalized query contexts.
 *
 * @package HDForm\API
 */

declare(strict_types=1);

namespace HDForm\API;

defined( 'ABSPATH' ) || exit;

final class DynamicFieldAPI extends AbstractAPI {
	private const CACHE_GROUP = 'hd_form_dynamic_options';
	private const CACHE_TTL   = 300;

	/**
	 * Request-local cache keeps repeated calls cheap even when object-cache
	 * functions are stubbed or unavailable in tests.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private static array $runtimeCache = [];

	public function __construct() {
		$this->namespace = HD_FORM_REST_NAMESPACE;
		$this->rest_base = 'form';
	}

	/** ---------------------------------------- */

	/**
	 * Register routes.
	 */
	/**
	 * Register routes.
	 */
	protected function registerRoutes(): void {
		$namespaces = array_unique(
			array_filter(
				[
					$this->namespace,
					'wp/v2',
					'hd/v1',
					'hd',
				]
			)
		);

		foreach ( $namespaces as $ns ) {
			register_rest_route(
				$ns,
				"/{$this->rest_base}/dynamic-options",
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'getOptions' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'source'     => [
							'required'          => true,
							'type'              => 'string',
							'enum'              => [ 'post', 'page', 'category' ],
							'sanitize_callback' => 'sanitize_key',
						],
						'ids'        => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						],
						'post_type'  => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => 'post',
						],
						'parent'     => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						],
						'taxonomy'   => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
							'default'           => 'category',
						],
						'term_id'    => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						],
						'hide_empty' => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
							'default'           => true,
						],
						'limit'      => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						],
						'lang'       => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						],
					],
				]
			);
		}
	}

	/**
	 * Handle GET request.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return \WP_REST_Response
	 */
	public function getOptions( \WP_REST_Request $request ): \WP_REST_Response {
		$rateLimitCheck = $this->rateLimit( 'form_dynamic_options', 60, 60 );
		if ( $rateLimitCheck instanceof \WP_REST_Response ) {
			return $rateLimitCheck;
		}

		$hideEmptyParam = $request->get_param( 'hide_empty' );
		$source         = sanitize_key( (string) $request->get_param( 'source' ) );
		$ids            = self::parseIds( (string) $request->get_param( 'ids' ) );
		$postType       = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) );
		$parentId       = absint( $request->get_param( 'parent' ) );
		$hasParent      = self::requestHasParam( $request, 'parent' );
		$taxonomy       = sanitize_key( (string) ( $request->get_param( 'taxonomy' ) ?: 'category' ) );
		$termId         = absint( $request->get_param( 'term_id' ) );
		$hideEmpty      = null === $hideEmptyParam ? true : rest_sanitize_boolean( $hideEmptyParam );

		// Client-declared page size, hard-capped at 100.
		$limitParam = absint( $request->get_param( 'limit' ) );
		$limit      = $limitParam > 0 ? min( 100, $limitParam ) : 100;
		$lang       = self::normalizeLang( (string) $request->get_param( 'lang' ) );

		if ( ! in_array( $source, [ 'post', 'page', 'category' ], true ) ) {
			return $this->sendItems( [] );
		}

		// Whitelist post types.
		$allowedTypes = self::sanitizeKeyList( apply_filters( 'hd_form_allowed_post_types', [ 'post', 'page', 'product' ] ) );
		if ( 'post' === $source && ! in_array( $postType, $allowedTypes, true ) ) {
			return $this->sendItems( [] );
		}

		// Whitelist taxonomies.
		$allowedTax = self::sanitizeKeyList( apply_filters( 'hd_form_allowed_taxonomies', [ 'category', 'product_cat', 'post_tag' ] ) );
		if ( in_array( $source, [ 'post', 'category' ], true ) && ! in_array( $taxonomy, $allowedTax, true ) ) {
			return $this->sendItems( [] );
		}

		$cacheKey = self::cacheKey(
			[
				'source'     => $source,
				'ids'        => $ids,
				'post_type'  => $postType,
				'parent'     => $hasParent ? $parentId : null,
				'taxonomy'   => $taxonomy,
				'term_id'    => $termId,
				'hide_empty' => $hideEmpty,
				'limit'      => $limit,
				'lang'       => $lang,
			]
		);
		if ( array_key_exists( $cacheKey, self::$runtimeCache ) ) {
			return $this->sendItems( self::$runtimeCache[ $cacheKey ] );
		}

		$cached = wp_cache_get( $cacheKey, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			self::$runtimeCache[ $cacheKey ] = $cached;

			return $this->sendItems( $cached );
		}

		$items = match ( $source ) {
			'page'     => $this->queryPages( $ids, $parentId, $limit, $lang ),
			'post'     => $this->queryPosts( $postType, $ids, $taxonomy, $termId, $limit, $lang ),
			'category' => $this->queryTerms( $taxonomy, $hideEmpty, $hasParent ? $parentId : null, $limit, $lang ),
			default    => [],
		};

		self::$runtimeCache[ $cacheKey ] = $items;
		wp_cache_set( $cacheKey, $items, self::CACHE_GROUP, self::CACHE_TTL );

		return $this->sendItems( $items );
	}

	/**
	 * Query pages by IDs or parent.
	 *
	 * @param array  $ids      Comma-separated IDs.
	 * @param int    $parentId Parent page ID.
	 * @param int    $limit    Maximum items to return.
	 * @param string $lang     Language slug for multilingual queries.
	 *
	 * @return array
	 */
	private function queryPages( array $ids, int $parentId, int $limit, string $lang ): array {
		$args = [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		];

		if ( '' !== $lang ) {
			$args['lang'] = $lang;
		}

		if ( $ids ) {
			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		} elseif ( $parentId > 0 ) {
			$args['post_parent'] = $parentId;
		}

		return $this->runQuery( $args );
	}

	/**
	 * Query posts by type, IDs, or taxonomy term.
	 *
	 * @param string $postType Post type slug.
	 * @param array  $ids      Comma-separated IDs.
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $termId   Term ID for filtering.
	 * @param int    $limit    Maximum items to return.
	 * @param string $lang     Language slug for multilingual queries.
	 *
	 * @return array
	 */
	private function queryPosts( string $postType, array $ids, string $taxonomy, int $termId, int $limit, string $lang ): array {
		$args = [
			'post_type'      => $postType,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		];

		if ( '' !== $lang ) {
			$args['lang'] = $lang;
		}

		if ( $ids ) {
			$args['post__in'] = $ids;
			$args['orderby']  = 'post__in';
		} elseif ( $termId > 0 ) {
			$args['tax_query'] = [
				[
					'taxonomy' => $taxonomy,
					'terms'    => $termId,
				],
			];
		}

		return $this->runQuery( $args );
	}

	/**
	 * Query taxonomy terms.
	 *
	 * @param string     $taxonomy  Taxonomy slug.
	 * @param bool       $hideEmpty Hide empty terms.
	 * @param int|null   $parentId  Parent term ID.
	 * @param int        $limit     Maximum items to return.
	 * @param string     $lang      Language slug for multilingual queries.
	 *
	 * @return array
	 */
	private function queryTerms( string $taxonomy, bool $hideEmpty, ?int $parentId = null, int $limit = 100, string $lang = '' ): array {
		$args = [
			'taxonomy'   => $taxonomy,
			'hide_empty' => $hideEmpty,
			'number'     => $limit,
		];

		if ( '' !== $lang ) {
			$args['lang'] = $lang;
		}

		if ( null !== $parentId ) {
			$args['parent'] = $parentId;
		}

		$terms = get_terms( $args );

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		return array_map(
			static fn( \WP_Term $term ) => [
				'id'    => $term->term_id,
				'title' => $term->name,
			],
			$terms
		);
	}

	/**
	 * Execute WP_Query and format results.
	 *
	 * @param array $args WP_Query args.
	 *
	 * @return array
	 */
	private function runQuery( array $args ): array {
		$query = new \WP_Query( $args );
		$items = [];

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $query->posts, false, true );
		}

		foreach ( $query->posts as $postId ) {
			$items[] = [
				'id'    => (int) $postId,
				'title' => get_the_title( $postId ),
			];
		}

		return $items;
	}

	/**
	 * Validate a client-supplied language slug (e.g. "en", "pt-br", "zh_CN").
	 *
	 * Anything that does not look like a locale is dropped so the query
	 * falls back to the site default instead of failing.
	 *
	 * @param string $lang Raw language param.
	 *
	 * @return string Normalized lowercase slug, or '' when absent/invalid.
	 */
	private static function normalizeLang( string $lang ): string {
		$lang = trim( $lang );
		if ( '' === $lang || ! preg_match( '/^[a-zA-Z]{2,3}(?:[_-][a-zA-Z0-9]{2,8}){0,2}$/', $lang ) ) {
			return '';
		}

		return strtolower( $lang );
	}

	/**
	 * @return array<int, int>
	 */
	private static function parseIds( string $ids ): array {
		$parsed = array_map( 'absint', explode( ',', $ids ) );
		$parsed = array_values( array_unique( array_filter( $parsed ) ) );

		return array_slice( $parsed, 0, 100 );
	}

	/**
	 * @return array<int, string>
	 */
	private static function sanitizeKeyList( mixed $values ): array {
		if ( ! is_array( $values ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static fn( mixed $value ): string => sanitize_key( (string) $value ),
					$values
				)
			)
		);
	}

	private static function requestHasParam( \WP_REST_Request $request, string $param ): bool {
		return method_exists( $request, 'has_param' )
			? $request->has_param( $param )
			: null !== $request->get_param( $param );
	}

	private static function cacheKey( array $context ): string {
		return 'dynamic_' . md5( wp_json_encode( $context ) );
	}
}
