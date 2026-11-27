<?php
declare(strict_types=1);

namespace Skye;

/**
 * Front-end glue for the Bricks templates: the shortcodes that place the server-rendered pieces
 * ([skye_place part="…"], [skye_header], [skye_footer]) and the language/market of the current view.
 */
final class Frontend {

	public static function register(): void {
		add_shortcode( 'skye_place', [ self::class, 'place_shortcode' ] );
		add_shortcode( 'skye_tour', [ self::class, 'tour_shortcode' ] );
		add_shortcode( 'skye_tours', [ self::class, 'tours_shortcode' ] );
		add_shortcode( 'skye_route', [ self::class, 'route_shortcode' ] );
		add_shortcode( 'skye_routes', [ self::class, 'routes_shortcode' ] );
		add_shortcode( 'skye_places', [ self::class, 'places_shortcode' ] );
		add_shortcode( 'skye_home', [ self::class, 'home_shortcode' ] );
		add_shortcode( 'skye_header', [ self::class, 'header_shortcode' ] );
		add_shortcode( 'skye_footer', [ self::class, 'footer_shortcode' ] );
		add_filter( 'wp_robots', [ self::class, 'robots' ] );
		add_filter( 'document_title_parts', [ self::class, 'title_parts' ] );
		add_action( 'after_setup_theme', static fn() => register_nav_menus( [ 'skye_primary' => __( 'Skye primary navigation', 'skye-core' ) ] ) );
	}

	/** @return array{lang:string, market:string} Language from WPML when present; market from the language (§9). */
	public static function context(): array {
		$lang = (string) apply_filters( 'wpml_current_language', null );
		if ( '' === $lang ) {
			$lang = substr( get_locale(), 0, 2 );
		}
		$lang = strtolower( $lang );
		return [ 'lang' => $lang, 'market' => (string) apply_filters( 'skye_market', Units::market_for_lang( $lang ), $lang ) ];
	}

	/** Archive URL of a content type, or null when it has none. */
	private static function archive( string $post_type ): ?string {
		$url = get_post_type_archive_link( $post_type );
		return $url ? (string) $url : null;
	}

	/**
	 * [skye_place part="facts"]: one block of the current Place (or of `id`). Parts: see Render\PlaceBlocks::PARTS.
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function place_shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'part' => 'facts', 'id' => '' ], is_array( $atts ) ? $atts : [], 'skye_place' );
		$post = get_post( '' !== $atts['id'] ? (int) $atts['id'] : get_the_ID() );
		if ( ! $post || PostTypes::PLACE !== $post->post_type || ! in_array( $atts['part'], Render\PlaceBlocks::PARTS, true ) ) {
			return '';
		}
		[ 'lang' => $lang, 'market' => $market ] = self::context();

		static $items = [];
		$key = $post->ID . '|' . $lang . '|' . $market;
		if ( ! isset( $items[ $key ] ) ) {
			$items[ $key ] = self::with_markup( Api\Places::item( $post, $lang, $market ) );
		}
		return Render\PlaceBlocks::render( $atts['part'], $items[ $key ], $market );
	}

	/** Adds what only the page needs: site links, and responsive <img> markup for every photo shown. */
	private static function with_markup( array $item ): array {
		$item['_links'] = [ 'home' => home_url( '/' ), 'places' => self::archive( PostTypes::PLACE ), 'tours' => self::archive( PostTypes::TOUR ), 'zone' => ! empty( $item['zone']['slug'] ) ? self::zone_url( (string) $item['zone']['slug'] ) : null ];

		$img = static function ( ?array $image, string $sizes, bool $eager = false ): ?array {
			if ( $image && ! empty( $image['id'] ) ) {
				$image['html'] = wp_get_attachment_image( (int) $image['id'], 'large', false, [ 'sizes' => $sizes, 'loading' => $eager ? 'eager' : 'lazy', 'fetchpriority' => $eager ? 'high' : 'auto', 'decoding' => 'async' ] );
			}
			return $image;
		};
		$single        = empty( $item['gallery'] );
		$item['image'] = $img( $item['image'] ?? null, $single ? '(min-width: 1264px) 1200px, 100vw' : '(min-width: 1264px) 800px, 100vw', true );
		foreach ( $item['gallery'] ?? [] as $i => $g ) {
			$item['gallery'][ $i ] = $img( $g, '(min-width: 1264px) 400px, 50vw' );
		}
		foreach ( $item['nearby_places'] ?? [] as $i => $n ) {
			$item['nearby_places'][ $i ]['image'] = $img( Api\PlaceSerializer::image_by_id( Lang::thumbnail_id( (int) $n['id'] ) ), '(min-width: 800px) 260px, 60vw' );
		}
		return $item;
	}

	/** Responsive <img> markup (srcset) for an API image object. */
	private static function picture( ?array $image, string $sizes, bool $eager = false ): ?array {
		if ( $image && ! empty( $image['id'] ) ) {
			$image['html'] = wp_get_attachment_image( (int) $image['id'], 'large', false, [ 'sizes' => $sizes, 'loading' => $eager ? 'eager' : 'lazy', 'fetchpriority' => $eager ? 'high' : 'auto', 'decoding' => 'async' ] );
		}
		return $image;
	}

	/** A tour's API object for this page view, with site links, stop links and image markup. */
	private static function tour_item( \WP_Post $post, string $market, bool $full ): array {
		$item           = Api\Collection::item( $post, $market, $full );
		$item['_links'] = [ 'home' => home_url( '/' ), 'tours' => self::archive( PostTypes::TOUR ) ];
		$several        = $full && ! empty( $item['gallery'] );
		$item['image']  = self::picture( $item['image'] ?? null, $full ? ( $several ? '(min-width: 1264px) 800px, 100vw' : '(min-width: 1264px) 1200px, 100vw' ) : '(min-width: 800px) 600px, 100vw', $full );
		foreach ( $item['gallery'] ?? [] as $i => $g ) {
			$item['gallery'][ $i ] = self::picture( $g, '(min-width: 1264px) 400px, 50vw' );
		}
		foreach ( $item['days'] ?? [] as $d => $day ) {
			foreach ( $day['stops'] ?? [] as $i => $stop ) {
				if ( ! empty( $stop['place']['id'] ) ) {
					$id = (int) $stop['place']['id'];
					$item['days'][ $d ]['stops'][ $i ]['place']['url'] = get_permalink( $id );
					// Each stop shows the cover of its place: a photo is uploaded once and appears everywhere.
					$item['days'][ $d ]['stops'][ $i ]['place']['image'] = self::picture( Api\PlaceSerializer::image_by_id( Lang::thumbnail_id( $id ) ), '120px' );
				}
			}
		}
		return $item;
	}

	/** The tour as its page shows it, built once per request: the blocks and the JSON-LD read the same data. */
	public static function tour_view( \WP_Post $post ): array {
		static $items = [];
		[ 'market' => $market ] = self::context();
		$key = $post->ID . '|' . $market;
		$items[ $key ] ??= self::tour_item( $post, $market, true );
		return $items[ $key ];
	}

	/**
	 * [skye_tour part="offer"]: one block of the current Tour (or of `id`). Parts: see Render\TourBlocks::PARTS.
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function tour_shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'part' => 'offer', 'id' => '' ], is_array( $atts ) ? $atts : [], 'skye_tour' );
		$post = get_post( '' !== $atts['id'] ? (int) $atts['id'] : get_the_ID() );
		if ( ! $post || PostTypes::TOUR !== $post->post_type || ! in_array( $atts['part'], Render\TourBlocks::PARTS, true ) ) {
			return '';
		}
		[ 'market' => $market ] = self::context();
		return Render\TourBlocks::render( $atts['part'], self::tour_view( $post ), $market );
	}

	/** [skye_tours]: the tours listing (intro and one comparison card per published tour). */
	public static function tours_shortcode(): string {
		[ 'market' => $market ] = self::context();
		$tours = [];
		foreach ( get_posts( [ 'post_type' => PostTypes::TOUR, 'post_status' => 'publish', 'suppress_filters' => false, 'posts_per_page' => 20, 'orderby' => 'title', 'order' => 'ASC' ] ) as $post ) {
			$tours[] = self::tour_item( $post, $market, false );
		}
		return Render\TourBlocks::listing( $tours, $market );
	}

	/** A route's API object for this page view: site links, stop links and photos, and its equivalent tour. */
	private static function route_item( \WP_Post $post, string $market, bool $full ): array {
		$item           = Api\Collection::item( $post, $market, $full );
		$item['_links'] = [ 'home' => home_url( '/' ), 'routes' => self::archive( PostTypes::ROUTE ) ];
		$item['image']  = self::picture( $item['image'] ?? null, $full ? '(min-width: 1264px) 1200px, 100vw' : '(min-width: 800px) 600px, 100vw', $full );
		if ( ! $full ) {
			return $item;
		}
		foreach ( $item['days'] ?? [] as $d => $day ) {
			foreach ( $day['stops'] ?? [] as $i => $stop ) {
				if ( empty( $stop['place']['id'] ) ) {
					continue;
				}
				$id = (int) $stop['place']['id'];
				$item['days'][ $d ]['stops'][ $i ]['place']['url']   = get_permalink( $id );
				$item['days'][ $d ]['stops'][ $i ]['place']['image'] = self::picture( Api\PlaceSerializer::image_by_id( Lang::thumbnail_id( $id ) ), '(min-width: 768px) 160px, 96px' );
			}
			if ( ! empty( $day['night_lodging']['id'] ) ) {
				$item['days'][ $d ]['night_lodging']['url'] = get_permalink( (int) $day['night_lodging']['id'] );
			}
		}
		$tour = ! empty( $item['equivalent_tour']['id'] ) ? get_post( (int) $item['equivalent_tour']['id'] ) : null;
		if ( $tour && 'publish' === $tour->post_status ) {
			$item['_tour'] = self::tour_item( $tour, $market, false );
		}
		return $item;
	}

	/**
	 * [skye_route part="itinerary"]: one block of the current Route (or of `id`). Parts: see Render\RouteBlocks::PARTS.
	 *
	 * @param array<string, string>|string $atts
	 */
	public static function route_shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'part' => 'itinerary', 'id' => '' ], is_array( $atts ) ? $atts : [], 'skye_route' );
		$post = get_post( '' !== $atts['id'] ? (int) $atts['id'] : get_the_ID() );
		if ( ! $post || PostTypes::ROUTE !== $post->post_type || ! in_array( $atts['part'], Render\RouteBlocks::PARTS, true ) ) {
			return '';
		}
		[ 'market' => $market ] = self::context();
		static $items = [];
		$key = $post->ID . '|' . $market;
		$items[ $key ] ??= self::route_item( $post, $market, true );
		return Render\RouteBlocks::render( $atts['part'], $items[ $key ], $market );
	}

	/** [skye_routes]: the routes listing. */
	public static function routes_shortcode(): string {
		[ 'market' => $market ] = self::context();
		$routes = [];
		foreach ( get_posts( [ 'post_type' => PostTypes::ROUTE, 'post_status' => 'publish', 'suppress_filters' => false, 'posts_per_page' => 50, 'orderby' => 'title', 'order' => 'ASC' ] ) as $post ) {
			$routes[] = self::route_item( $post, $market, false );
		}
		return Render\RouteBlocks::listing( $routes, $market );
	}

	/** URL of a zone page, or null when the zone does not exist. */
	private static function zone_url( string $slug ): ?string {
		$id  = Lang::term_id( Taxonomies::ZONE, $slug );
		$url = $id ? get_term_link( $id, Taxonomies::ZONE ) : null;
		return is_string( $url ) ? $url : null;
	}

	/** Is this the places directory or one of the zone pages? */
	public static function is_directory(): bool {
		return is_post_type_archive( PostTypes::PLACE ) || is_tax( Taxonomies::ZONE );
	}

	/** The category/profile filters of the current request, as slugs ('' when not set). */
	private static function requested_filters(): array {
		$out = [];
		foreach ( [ 'category', 'profile' ] as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter of a public listing.
			$out[ $k ] = isset( $_GET[ $k ] ) && is_string( $_GET[ $k ] ) ? sanitize_title( wp_unslash( $_GET[ $k ] ) ) : '';
		}
		return $out;
	}

	/** Every published place of the language being viewed, as a summary with its photo. Built once per request. */
	private static function all_places(): array {
		static $places = null;
		if ( null === $places ) {
			[ 'lang' => $lang, 'market' => $market ] = self::context();
			$places = [];
			foreach ( get_posts( [ 'post_type' => PostTypes::PLACE, 'post_status' => 'publish', 'suppress_filters' => false, 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC' ] ) as $post ) {
				$p          = Api\PlaceSerializer::summary( $post, $lang, $market );
				$p['image'] = self::picture( $p['image'] ?? null, '(min-width: 1100px) 380px, (min-width: 700px) 50vw, 100vw' );
				$places[]   = $p;
			}
		}
		return $places;
	}

	/** Slugs of the places shown on the home page, in order. Change the list with the `skye_home_places` filter. */
	public const HOME_PLACES = [ 'old-man-of-storr', 'quiraing', 'fairy-pools', 'neist-point', 'dunvegan-castle', 'eilean-donan-castle' ];

	/** What the home page shows, all of it taken from content that exists: tours, routes, zones, places. */
	public static function home_view(): array {
		[ 'market' => $market ] = self::context();
		$places = self::all_places();
		$order  = Taxonomies::terms();
		$base   = (string) self::archive( PostTypes::PLACE );
		$none   = [ 'zone' => '', 'category' => '', 'profile' => '' ];

		$tours = [];
		foreach ( get_posts( [ 'post_type' => PostTypes::TOUR, 'post_status' => 'publish', 'suppress_filters' => false, 'posts_per_page' => 20, 'orderby' => 'title', 'order' => 'ASC' ] ) as $post ) {
			$tours[] = self::tour_item( $post, $market, false );
		}
		usort( $tours, static fn( $a, $b ) => (int) ( $a['days_count'] ?? 0 ) <=> (int) ( $b['days_count'] ?? 0 ) ); // shortest first
		$routes = [];
		foreach ( get_posts( [ 'post_type' => PostTypes::ROUTE, 'post_status' => 'publish', 'suppress_filters' => false, 'posts_per_page' => 50, 'orderby' => 'title', 'order' => 'ASC' ] ) as $post ) {
			$routes[] = self::route_item( $post, $market, false );
		}

		$zones = [];
		foreach ( Directory::options( $places, $none, 'zone', $order[ Taxonomies::ZONE ] ) as $z ) {
			$id      = Lang::term_id( Taxonomies::ZONE, $z['slug'] );
			$term    = $id ? get_term( $id, Taxonomies::ZONE ) : null;
			$url     = self::zone_url( $z['slug'] );
			$zones[] = [ 'name' => $z['name'], 'count' => $z['count'], 'url' => $url ?? $base, 'intro' => $term instanceof \WP_Term ? trim( wp_strip_all_tags( $term->description ) ) : '' ];
		}
		$profiles = [];
		foreach ( Directory::options( $places, $none, 'profile', $order[ Taxonomies::PROFILE ] ) as $p ) {
			$profiles[] = [ 'name' => $p['name'], 'count' => $p['count'], 'url' => (string) add_query_arg( 'profile', $p['slug'], $base ) ];
		}

		$by_slug  = array_column( $places, null, 'slug' );
		$featured = [];
		foreach ( (array) apply_filters( 'skye_home_places', self::HOME_PLACES ) as $slug ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$featured[] = $by_slug[ $slug ];
			}
		}
		$hero = null;
		foreach ( array_merge( $featured, $tours ) as $item ) {
			if ( ! empty( $item['image']['id'] ) ) {
				$hero = self::picture( Api\PlaceSerializer::image_by_id( (int) $item['image']['id'] ), '(min-width: 992px) 560px, 100vw', true );
				break;
			}
		}
		return [
			'links'      => [ 'tours' => self::archive( PostTypes::TOUR ), 'places' => self::archive( PostTypes::PLACE ), 'routes' => self::archive( PostTypes::ROUTE ) ],
			'hero_image' => $hero,
			'tours'      => $tours,
			'routes'     => $routes,
			'zones'      => $zones,
			'profiles'   => $profiles,
			'places'     => $featured,
		];
	}

	/** [skye_home]: the home page. */
	public static function home_shortcode(): string {
		[ 'market' => $market ] = self::context();
		return Render\Home::page( self::home_view(), $market );
	}

	/**
	 * Everything the directory (or a zone page) shows: places, groups, filters with their URLs.
	 * The zone is part of the path (/zone/trotternish/); category and profile are query parameters.
	 * Built once per request: the page and its JSON-LD read the same data.
	 */
	public static function places_view(): array {
		static $view = null;
		if ( null !== $view ) {
			return $view;
		}
		[ 'lang' => $lang, 'market' => $market ] = self::context();

		$term   = is_tax( Taxonomies::ZONE ) ? get_queried_object() : null;
		$zone   = $term instanceof \WP_Term ? Lang::term_slug( $term ) : '';
		$active = [ 'zone' => $zone ] + self::requested_filters();

		$places = self::all_places();

		$order = Taxonomies::terms();
		$base  = (string) self::archive( PostTypes::PLACE );
		$url   = static function ( array $f ) use ( $base ): string {
			$root = '' !== ( $f['zone'] ?? '' ) ? ( self::zone_url( $f['zone'] ) ?? $base ) : $base;
			return (string) add_query_arg( array_filter( [ 'category' => $f['category'] ?? '', 'profile' => $f['profile'] ?? '' ] ), $root );
		};

		$labels  = [
			'zone'     => [ __( 'Area', 'skye-core' ), __( 'All areas', 'skye-core' ), Taxonomies::ZONE ],
			'category' => [ __( 'Type', 'skye-core' ), __( 'All types', 'skye-core' ), Taxonomies::CATEGORY ],
			'profile'  => [ __( 'Good for', 'skye-core' ), __( 'Everything', 'skye-core' ), Taxonomies::PROFILE ],
		];
		$filters = [];
		foreach ( $labels as $filter => [ $label, $all, $taxonomy ] ) {
			$options = [];
			foreach ( Directory::options( $places, $active, $filter, $order[ $taxonomy ] ) as $o ) {
				// A second click on the active option clears it.
				$o['url']  = $url( [ $filter => $o['active'] ? '' : $o['slug'] ] + $active );
				$options[] = $o;
			}
			$filters[] = [ 'label' => $label, 'all' => [ 'label' => $all, 'url' => $url( [ $filter => '' ] + $active ), 'active' => '' === $active[ $filter ] ], 'options' => $options ];
		}

		$matches = Directory::match( $places, $active );
		$groups  = [];
		foreach ( Directory::by_zone( $matches, $order[ Taxonomies::ZONE ] ) as $g ) {
			$groups[] = [ 'name' => $g['name'], 'url' => '' !== $g['slug'] ? $url( [ 'zone' => $g['slug'] ] + $active ) : null, 'places' => $g['places'] ];
		}

		$view = [
			'filters'   => $filters,
			'groups'    => $groups,
			'places'    => $matches,
			'total'     => count( $matches ),
			'filtered'  => '' !== $active['category'] || '' !== $active['profile'],
			'reset_url' => $url( [ 'zone' => $zone ] ),
			'grouped'   => '' === $zone,
			'active'    => $active,
		];
		if ( $term instanceof \WP_Term ) {
			$view += [
				'eyebrow'    => __( 'What to see', 'skye-core' ),
				'title'      => Text::plain( $term->name ),
				'lead'       => trim( wp_strip_all_tags( $term->description ) ),
				'breadcrumb' => [ [ 'label' => __( 'Home', 'skye-core' ), 'url' => home_url( '/' ) ], [ 'label' => __( 'What to see', 'skye-core' ), 'url' => $base ], [ 'label' => Text::plain( $term->name ) ] ],
			];
		} else {
			$view += [
				'eyebrow' => __( 'The whole island', 'skye-core' ),
				'title'   => __( 'What to see on the Isle of Skye', 'skye-core' ),
				'lead'    => __( 'Every place worth a stop on Skye and on the way there, by area. Each one says how long it takes, what it costs and what to expect.', 'skye-core' ),
			];
		}
		return $view;
	}

	/** [skye_places]: the places directory, or the places of the zone being viewed. */
	public static function places_shortcode(): string {
		return Render\PlaceList::directory( self::places_view() );
	}

	/** Filtered views are for people, not for the index: only the directory and the zone pages are canonical. */
	public static function robots( array $robots ): array {
		if ( self::is_directory() && array_filter( self::requested_filters() ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
			// Links stay followable unless the whole site already says otherwise (a private or test site).
			if ( empty( $robots['nofollow'] ) ) {
				$robots['follow'] = true;
			}
		}
		return $robots;
	}

	/** Browser title of the directory and the zone pages: what a visitor would call them. */
	public static function title_parts( array $parts ): array {
		if ( is_post_type_archive( PostTypes::PLACE ) ) {
			$parts['title'] = __( 'What to see on the Isle of Skye', 'skye-core' );
		} elseif ( is_tax( Taxonomies::ZONE ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				/* translators: %s: name of an area of the island. */
				$parts['title'] = sprintf( __( 'What to see: %s', 'skye-core' ), Text::plain( $term->name ) );
			}
		}
		return $parts;
	}

	/** Main navigation: the "Skye primary" menu when one is assigned, else the archives that exist. */
	private static function nav_links(): array {
		$links     = [];
		$locations = get_nav_menu_locations();
		$items     = ! empty( $locations['skye_primary'] ) ? wp_get_nav_menu_items( (int) $locations['skye_primary'] ) : [];
		$here      = untrailingslashit( home_url( (string) ( $GLOBALS['wp']->request ?? '' ) ) );
		foreach ( $items ?: [] as $item ) {
			if ( 0 === (int) $item->menu_item_parent ) {
				$links[] = [ 'label' => (string) $item->title, 'url' => (string) $item->url, 'current' => untrailingslashit( (string) $item->url ) === $here ];
			}
		}
		if ( $links ) {
			return $links;
		}
		$defaults = [
			PostTypes::TOUR      => __( 'Tours from Edinburgh', 'skye-core' ),
			PostTypes::ROUTE     => __( 'Plan your route', 'skye-core' ),
			PostTypes::PLACE     => __( 'What to see', 'skye-core' ),
			PostTypes::LODGING   => __( 'Where to stay', 'skye-core' ),
			PostTypes::MOTORHOME => __( 'Car and motorhome', 'skye-core' ),
		];
		$current  = is_singular() ? (string) get_post_type() : ( is_tax( Taxonomies::ZONE ) ? PostTypes::PLACE : ( is_post_type_archive() ? (string) get_query_var( 'post_type' ) : '' ) );
		foreach ( $defaults as $type => $label ) {
			$url = self::archive( $type );
			if ( $url ) {
				$links[] = [ 'label' => $label, 'url' => $url, 'current' => $type === $current ];
			}
		}
		return $links;
	}

	/** @return list<array{code:string,label:string,url:string,active:bool}> From WPML; empty without it. */
	private static function languages(): array {
		$out = [];
		foreach ( (array) apply_filters( 'wpml_active_languages', [], [ 'skip_missing' => 0, 'orderby' => 'code' ] ) as $l ) {
			if ( is_array( $l ) && isset( $l['language_code'], $l['url'] ) ) {
				$out[] = [ 'code' => (string) $l['language_code'], 'label' => (string) ( $l['native_name'] ?? $l['language_code'] ), 'url' => (string) $l['url'], 'active' => ! empty( $l['active'] ) ];
			}
		}
		return $out;
	}

	public static function header_shortcode(): string {
		$tours = self::archive( PostTypes::TOUR );
		return Render\Chrome::header(
			[
				'home'      => home_url( '/' ),
				'links'     => self::nav_links(),
				'languages' => self::languages(),
				'cta'       => $tours ? [ 'label' => __( 'Book a tour', 'skye-core' ), 'url' => $tours ] : null,
			]
		);
	}

	public static function footer_shortcode(): string {
		$link = static fn( string $type, string $label ): array => ( $url = self::archive( $type ) ) ? [ [ 'label' => $label, 'url' => $url ] ] : [];
		return Render\Chrome::footer(
			[
				'home'      => home_url( '/' ),
				'tagline'   => __( 'Visitor information for the Isle of Skye.', 'skye-core' ),
				'columns'   => [
					[ 'title' => __( 'Explore', 'skye-core' ), 'links' => array_merge( $link( PostTypes::PLACE, __( 'What to see', 'skye-core' ) ), $link( PostTypes::ROUTE, __( 'Routes', 'skye-core' ) ) ) ],
					[ 'title' => __( 'Book', 'skye-core' ), 'links' => array_merge( $link( PostTypes::TOUR, __( 'Tours from Edinburgh', 'skye-core' ) ), $link( PostTypes::LODGING, __( 'Where to stay', 'skye-core' ) ), $link( PostTypes::MOTORHOME, __( 'Car and motorhome', 'skye-core' ) ) ) ],
				],
				'languages' => self::languages(),
				'brands'    => array_column( Brands::all(), 'title' ),
			]
		);
	}
}
