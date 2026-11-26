<?php
declare(strict_types=1);

namespace Skye;

/**
 * CONTRACTS.md §10. Every content item records who verified it, when, until when it is valid,
 * and a log of later changes. Shown publicly ("Checked by X on date") and in JSON-LD.
 */
final class Verification {

	public const VERIFIED_BY = 'skye_verified_by';
	public const VERIFIED_AT = 'skye_verified_at';
	public const VALID_UNTIL = 'skye_valid_until';
	public const CHANGE_LOG  = 'skye_change_log';
	public const VALID_MONTHS = 12;
	private const MAX_LOG     = 50;

	/** Meta keys that never count as a content change. */
	private const IGNORED_META = [ '_edit_lock', '_edit_last', self::CHANGE_LOG, '_wp_old_slug', '_wp_old_date' ];

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'metabox' ] );
		add_action( 'save_post', [ self::class, 'on_save' ], 20, 3 );
		add_action( 'admin_notices', [ self::class, 'expired_notice' ] );
		foreach ( PostTypes::all() as $type ) {
			add_filter( "manage_{$type}_posts_columns", [ self::class, 'column' ] );
			add_action( "manage_{$type}_posts_custom_column", [ self::class, 'column_value' ], 10, 2 );
		}
	}

	/** @return array{verified_by:?array{id:int,name:string}, verified_at:?string, valid_until:?string, updated_at:?string, status:string} */
	public static function get( int $post_id ): array {
		$by    = (int) get_post_meta( $post_id, self::VERIFIED_BY, true );
		$at    = (string) get_post_meta( $post_id, self::VERIFIED_AT, true ) ?: null;
		$until = (string) get_post_meta( $post_id, self::VALID_UNTIL, true ) ?: null;
		$log   = self::log( $post_id );
		$last  = $log ? $log[0]['at'] : null;
		$user  = $by ? get_userdata( $by ) : null;

		$status = 'unverified';
		if ( $at ) {
			$status = ( $until && strtotime( $until ) < time() ) ? 'expired' : 'verified';
		}

		return [
			'verified_by' => $user ? [ 'id' => $by, 'name' => $user->display_name ] : null,
			'verified_at' => $at,
			'valid_until' => $until,
			'updated_at'  => ( $last && $at && strtotime( $last ) > strtotime( $at ) ) ? $last : null,
			'status'      => $status,
		];
	}

	/** @return list<array{at:string, by:int, fields:list<string>}> newest first */
	public static function log( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::CHANGE_LOG, true );
		$log = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $log ) ? $log : [];
	}

	public static function mark_verified( int $post_id, int $user_id, ?string $date = null ): void {
		$date = $date ?: current_time( 'Y-m-d' );
		update_post_meta( $post_id, self::VERIFIED_BY, $user_id );
		update_post_meta( $post_id, self::VERIFIED_AT, $date );
		update_post_meta( $post_id, self::VALID_UNTIL, gmdate( 'Y-m-d', strtotime( $date . ' +' . self::VALID_MONTHS . ' months' ) ) );
	}

	/** @param list<string> $fields */
	public static function log_change( int $post_id, int $user_id, array $fields ): void {
		$log = self::log( $post_id );
		array_unshift( $log, [ 'at' => current_time( 'c' ), 'by' => $user_id, 'fields' => array_values( array_unique( $fields ) ) ] );
		update_post_meta( $post_id, self::CHANGE_LOG, wp_json_encode( array_slice( $log, 0, self::MAX_LOG ) ) );
	}

	// ---- admin -------------------------------------------------------------------------------

	public static function metabox(): void {
		foreach ( PostTypes::all() as $type ) {
			add_meta_box( 'skye_verification', __( 'Verification', 'skye-core' ), [ self::class, 'render_metabox' ], $type, 'side', 'high' );
		}
	}

	public static function render_metabox( \WP_Post $post ): void {
		$v = self::get( $post->ID );
		wp_nonce_field( 'skye_verification', 'skye_verification_nonce' );
		echo '<p><strong>' . esc_html__( 'Status', 'skye-core' ) . ':</strong> ' . esc_html( $v['status'] ) . '</p>';
		if ( $v['verified_by'] ) {
			printf(
				'<p>%s <strong>%s</strong> · %s · %s %s</p>',
				esc_html__( 'Checked by', 'skye-core' ),
				esc_html( $v['verified_by']['name'] ),
				esc_html( (string) $v['verified_at'] ),
				esc_html__( 'valid until', 'skye-core' ),
				esc_html( (string) $v['valid_until'] )
			);
		}
		echo '<p><label><input type="checkbox" name="skye_mark_verified" value="1"> ' . esc_html__( 'I have checked this item today (horarios, precios, estado)', 'skye-core' ) . '</label></p>';
		$log = self::log( $post->ID );
		if ( $log ) {
			echo '<details><summary>' . esc_html__( 'Change log', 'skye-core' ) . ' (' . count( $log ) . ')</summary><ul style="font-size:11px">';
			foreach ( array_slice( $log, 0, 10 ) as $e ) {
				$u = get_userdata( (int) $e['by'] );
				printf( '<li>%s · %s · %s</li>', esc_html( substr( $e['at'], 0, 16 ) ), esc_html( $u ? $u->display_name : '#' . $e['by'] ), esc_html( implode( ', ', $e['fields'] ) ) );
			}
			echo '</ul></details>';
		}
	}

	public static function on_save( int $post_id, \WP_Post $post, bool $update ): void {
		if ( ! in_array( $post->post_type, PostTypes::all(), true ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$user = get_current_user_id();
		if ( ! $user ) {
			return;
		}
		if ( isset( $_POST['skye_verification_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['skye_verification_nonce'] ) ), 'skye_verification' ) && ! empty( $_POST['skye_mark_verified'] ) ) {
			self::mark_verified( $post_id, $user );
		}
		if ( $update ) {
			// Which fields changed since the previous revision? Compare meta snapshot stored on last save.
			$changed  = self::diff_since_snapshot( $post_id );
			if ( $changed ) {
				self::log_change( $post_id, $user, $changed );
			}
		}
		self::snapshot( $post_id );
	}

	private const SNAPSHOT = 'skye_meta_snapshot';

	private static function snapshot( int $post_id ): void {
		$all = get_post_meta( $post_id );
		$snap = [];
		foreach ( $all as $k => $vals ) {
			if ( in_array( $k, self::IGNORED_META, true ) || self::SNAPSHOT === $k || str_starts_with( $k, '_' ) && '_thumbnail_id' !== $k ) {
				continue;
			}
			$snap[ $k ] = md5( (string) wp_json_encode( $vals ) );
		}
		$p = get_post( $post_id );
		$snap['post_title']   = md5( $p->post_title );
		$snap['post_content'] = md5( $p->post_content );
		$snap['post_excerpt'] = md5( $p->post_excerpt );
		update_post_meta( $post_id, self::SNAPSHOT, wp_json_encode( $snap ) );
	}

	/** @return list<string> */
	private static function diff_since_snapshot( int $post_id ): array {
		$prev = json_decode( (string) get_post_meta( $post_id, self::SNAPSHOT, true ), true ) ?: [];
		if ( ! $prev ) {
			return [];
		}
		$all = get_post_meta( $post_id );
		$p   = get_post( $post_id );
		$now = [ 'post_title' => md5( $p->post_title ), 'post_content' => md5( $p->post_content ), 'post_excerpt' => md5( $p->post_excerpt ) ];
		foreach ( $all as $k => $vals ) {
			if ( in_array( $k, self::IGNORED_META, true ) || self::SNAPSHOT === $k || ( str_starts_with( $k, '_' ) && '_thumbnail_id' !== $k ) ) {
				continue;
			}
			$now[ $k ] = md5( (string) wp_json_encode( $vals ) );
		}
		$changed = [];
		foreach ( $now as $k => $h ) {
			if ( ( $prev[ $k ] ?? null ) !== $h ) {
				$changed[] = $k;
			}
		}
		// Verification meta itself is not a content change.
		return array_values( array_diff( $changed, [ self::VERIFIED_BY, self::VERIFIED_AT, self::VALID_UNTIL ] ) );
	}

	public static function expired_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'edit' !== $screen->base || ! in_array( $screen->post_type, PostTypes::all(), true ) ) {
			return;
		}
		$n = (int) ( new \WP_Query(
			[
				'post_type'      => $screen->post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [ [ 'key' => self::VALID_UNTIL, 'value' => current_time( 'Y-m-d' ), 'compare' => '<', 'type' => 'DATE' ] ],
			]
		) )->found_posts;
		if ( $n ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( sprintf( _n( '%d item needs re-verification.', '%d items need re-verification.', $n, 'skye-core' ), $n ) ) );
		}
	}

	public static function column( array $cols ): array {
		$cols['skye_verified'] = __( 'Verified', 'skye-core' );
		return $cols;
	}

	public static function column_value( string $col, int $post_id ): void {
		if ( 'skye_verified' !== $col ) {
			return;
		}
		$v = self::get( $post_id );
		$icon = match ( $v['status'] ) { 'verified' => '✅', 'expired' => '⚠️', default => '—' };
		echo esc_html( $icon . ' ' . ( $v['verified_at'] ?? '' ) );
	}
}
