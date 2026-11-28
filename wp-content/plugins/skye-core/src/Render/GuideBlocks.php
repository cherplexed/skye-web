<?php
declare(strict_types=1);

namespace Skye\Render;

/**
 * Practical information (CONTRACTS.md §7b): the listing of guides and the page of one. Pure:
 * takes the items built by Frontend. The questions shown are the ones the FAQPage data repeats.
 */
final class GuideBlocks {

	/** @return list<array{question:string, answer:string}> */
	public static function faq_items( array $g ): array {
		return TourBlocks::faq_items( $g );
	}

	private static function breadcrumb( array $links, ?string $title = null ): string {
		$trail = [];
		if ( ! empty( $links['home'] ) ) {
			$trail[] = sprintf( '<a href="%s">%s</a>', Html::e( $links['home'] ), Html::e( Html::t( 'Home' ) ) );
		}
		$name = Html::e( Html::t( 'Practical information' ) );
		if ( null === $title ) {
			$trail[] = '<span aria-current="page">' . $name . '</span>';
		} else {
			$trail[] = ! empty( $links['guides'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $links['guides'] ), $name ) : $name;
			$trail[] = '<span aria-current="page">' . Html::e( $title ) . '</span>';
		}
		return '<nav class="skye-breadcrumb" aria-label="' . Html::e( Html::t( 'Breadcrumb' ) ) . '">' . implode( ' <span aria-hidden="true">›</span> ', $trail ) . '</nav>';
	}

	/** One guide: summary, text, questions, sources and who checked it. */
	public static function page( array $g ): string {
		$title = (string) ( $g['title'] ?? '' );
		$html  = self::breadcrumb( $g['_links'] ?? [], $title )
			. '<header class="skye-listing__intro"><h1 class="skye-listing__title">' . Html::e( $title ) . '</h1>'
			. ( ! empty( $g['summary'] ) ? '<p class="skye-listing__lead">' . Html::e( $g['summary'] ) . '</p>' : '' ) . '</header>';
		if ( ! empty( $g['description_html'] ) ) {
			// The text is the post's own content, already filtered by WordPress.
			$html .= '<div class="skye-guide__text">' . $g['description_html'] . '</div>';
		}
		$faq = '';
		foreach ( self::faq_items( $g ) as $item ) {
			$paragraphs = implode( '', array_map( static fn( $p ) => '<p>' . Html::e( $p ) . '</p>', preg_split( '/\R{2,}/', $item['answer'] ) ?: [] ) );
			$faq       .= sprintf( '<details class="skye-faq__item" open><summary>%s</summary><div class="skye-faq__answer">%s</div></details>', Html::e( $item['question'] ), $paragraphs );
		}
		if ( '' !== $faq ) {
			$html .= Html::block( 'faq', Html::t( 'Frequently asked questions' ), '<div class="skye-faq__list">' . $faq . '</div>' );
		}
		$html .= PlaceBlocks::sources( $g );
		if ( 'verified' !== ( $g['verification']['status'] ?? '' ) ) {
			$html .= '<p class="skye-mh__small">' . Html::e( Html::t( 'Not yet checked by our team. Timetables and services change: confirm with the source before you travel.' ) ) . '</p>';
		}
		return '<article class="skye-listing skye-guide">' . $html . '</article>';
	}

	/** @param array{guides:list<array<string,mixed>>, links?:array<string,?string>} $v */
	public static function listing( array $v ): string {
		$cards = '';
		foreach ( $v['guides'] as $g ) {
			if ( empty( $g['url'] ) ) {
				continue;
			}
			$n      = count( self::faq_items( $g ) );
			$cards .= sprintf( '<a class="skye-card skye-mhroute" href="%s"><h2>%s</h2>', Html::e( $g['url'] ), Html::e( $g['title'] ?? '' ) )
				. ( ! empty( $g['summary'] ) ? '<p>' . Html::e( $g['summary'] ) . '</p>' : '' )
				/* translators: %d: number of questions answered on the page. */
				. ( $n > 0 ? '<p class="skye-guide__count">' . Html::e( sprintf( 1 === $n ? Html::t( '%d question answered' ) : Html::t( '%d questions answered' ), $n ) ) . '</p>' : '' )
				. '</a>';
		}
		return '<div class="skye-listing skye-guides">' . self::breadcrumb( $v['links'] ?? [] )
			. '<header class="skye-listing__intro"><span class="skye-eyebrow">' . Html::e( Html::t( 'Before you go' ) ) . '</span>'
			. '<h1 class="skye-listing__title">' . Html::e( Html::t( 'Practical information for the Isle of Skye' ) ) . '</h1>'
			. '<p class="skye-listing__lead">' . Html::e( Html::t( 'How to get there, how to move around, what the weather does and who to call: the answers that do not belong to any one place.' ) ) . '</p></header>'
			. ( '' !== $cards ? '<div class="skye-advice">' . $cards . '</div>' : '' ) . '</div>';
	}
}
