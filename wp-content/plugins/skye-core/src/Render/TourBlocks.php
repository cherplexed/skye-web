<?php
declare(strict_types=1);

namespace Skye\Render;

use Skye\Fields;
use Skye\Units;

/**
 * Server-rendered blocks of a Tour page and of the tours listing. Each takes the API object of the tour
 * (Api\Collection::item, with its `offer` for the market) and returns escaped HTML, or '' when empty.
 * Placed by the Bricks templates with [skye_tour part="…"] and [skye_tours].
 */
final class TourBlocks {

	public const PARTS = [ 'breadcrumb', 'cover', 'eyebrow', 'summary', 'offer', 'departure', 'itinerary', 'accommodation', 'booking', 'includes', 'faq', 'verification' ];

	private const LANGUAGES = [ 'en' => 'English', 'es' => 'Spanish', 'it' => 'Italian' ];
	private const CITIES    = [ 'edinburgh' => 'Edinburgh', 'glasgow' => 'Glasgow' ];

	public static function render( string $part, array $t, string $market ): string {
		return match ( $part ) {
			'breadcrumb'   => self::breadcrumb( $t ),
			'cover'        => self::cover( $t ),
			'eyebrow'      => self::eyebrow( $t ),
			'summary'      => self::summary( $t ),
			'offer'        => self::offer( $t, $market ),
			'booking'      => self::booking( $t ),
			'departure'    => self::departure( $t ),
			'itinerary'    => self::itinerary( $t ),
			'accommodation' => self::accommodation( $t ),
			'includes'     => self::includes( $t ),
			'faq'          => self::faq( $t ),
			'verification' => PlaceBlocks::verification( $t ),
			default        => '',
		};
	}

	public static function breadcrumb( array $t ): string {
		$links = $t['_links'] ?? [];
		$item  = static fn( string $label, ?string $url ): string => $url ? sprintf( '<a href="%s">%s</a>', Html::e( $url ), Html::e( $label ) ) : Html::e( $label );
		$trail = [ $item( Html::t( 'Home' ), $links['home'] ?? null ), $item( Html::t( 'Tours' ), $links['tours'] ?? null ), '<span aria-current="page">' . Html::e( $t['title'] ?? '' ) . '</span>' ];
		return '<nav class="skye-breadcrumb" aria-label="' . Html::e( Html::t( 'Breadcrumb' ) ) . '">' . implode( ' <span aria-hidden="true">›</span> ', $trail ) . '</nav>';
	}

	/** Cover plus up to two photos of the tour's gallery, laid out like a Place: one large, two small. */
	public static function cover( array $t ): string {
		return PlaceBlocks::gallery( $t );
	}

	/** "3 days · 2 nights" */
	public static function length( array $t ): string {
		$days   = (int) ( $t['days_count'] ?? 0 );
		$nights = (int) ( $t['nights_count'] ?? 0 );
		$parts  = [];
		if ( $days > 0 ) {
			$parts[] = 1 === $days ? Html::t( '1 day' ) : sprintf( Html::t( '%d days' ), $days );
		}
		if ( $nights > 0 ) {
			$parts[] = 1 === $nights ? Html::t( '1 night' ) : sprintf( Html::t( '%d nights' ), $nights );
		}
		return implode( ' · ', $parts );
	}

	/** "FROM EDINBURGH · 3 DAYS · 2 NIGHTS" above the title. */
	public static function eyebrow( array $t ): string {
		$city  = Html::t( self::CITIES[ (string) ( $t['departure_city'] ?? '' ) ] ?? '' );
		$parts = array_filter( [ '' !== $city ? sprintf( Html::t( 'From %s' ), $city ) : '', self::length( $t ) ] );
		return $parts ? '<span class="skye-eyebrow">' . Html::e( implode( ' · ', $parts ) ) . '</span>' : '';
	}

	public static function summary( array $t ): string {
		return ! empty( $t['summary'] ) ? '<div class="skye-summary"><p class="skye-summary__text">' . Html::e( $t['summary'] ) . '</p></div>' : '';
	}

	/** "From £399" — null when Bókun gave no price. */
	public static function price( array $t, string $market ): ?string {
		$offer = $t['offer'] ?? null;
		if ( ! is_array( $offer ) || ! isset( $offer['price_from'], $offer['currency'] ) ) {
			return null;
		}
		return Units::format_money( (float) $offer['price_from'], (string) $offer['currency'], $market );
	}

	/** Who runs the tour in this market and in which language; the audioguide when the visitor reads another one. */
	private static function language_note( array $t ): string {
		$offer = $t['offer'] ?? [];
		$code  = (string) ( $offer['brand']['tour_language'] ?? '' );
		if ( ! isset( self::LANGUAGES[ $code ] ) ) {
			return '';
		}
		$note = sprintf( Html::t( 'Guided in %s.' ), Html::t( self::LANGUAGES[ $code ] ) );
		$page = substr( strtolower( (string) ( $offer['market'] ?? '' ) ), 0, 2 );
		if ( ! empty( $t['includes_audioguide'] ) ) {
			$note .= ' ' . ( '' !== $page && $page !== $code ? Html::t( 'Audioguide in your language included.' ) : Html::t( 'Audioguide included.' ) );
		}
		return $note;
	}

	/** The booking card: price, next departure, language and the button to the operator's booking page. */
	public static function offer( array $t, string $market ): string {
		$offer = $t['offer'] ?? null;
		if ( ! is_array( $offer ) ) {
			return '';
		}
		$out   = '';
		$price = self::price( $t, $market );
		if ( null !== $price ) {
			$out .= sprintf( '<p class="skye-offer__price"><span class="skye-offer__from">%s</span> <strong>%s</strong> <span class="skye-offer__per">%s</span></p>', Html::e( Html::t( 'From' ) ), Html::e( $price ), Html::e( Html::t( 'per person' ) ) );
		}
		$next = Html::date( $offer['next_available'] ?? null );
		if ( '' !== $next ) {
			$out .= '<p class="skye-offer__next">' . Html::e( sprintf( Html::t( 'Next departure: %s' ), $next ) ) . '</p>';
		}
		$note = self::language_note( $t );
		if ( '' !== $note ) {
			$out .= '<p class="skye-offer__note">' . Html::e( $note ) . '</p>';
		}
		$here = ! empty( $offer['booking_widget'] ) || ! empty( $t['_checkout'] );
		if ( $here ) {
			$out .= sprintf( '<a class="skye-btn skye-btn--primary" href="#%s">%s</a>', self::BOOKING_ID, Html::e( Html::t( 'See dates and book' ) ) );
		} elseif ( ! empty( $offer['booking_url'] ) ) {
			$out .= sprintf( '<a class="skye-btn skye-btn--primary" href="%s" rel="noopener">%s</a>', Html::e( $offer['booking_url'] ), Html::e( Html::t( 'See dates and book' ) ) );
		}
		if ( ! empty( $offer['brand']['title'] ) ) {
			$line = $here ? Html::t( 'Operated by %s. You book and pay on this site.' ) : Html::t( 'Operated by %s. You book directly with the operator.' );
			$out .= '<p class="skye-offer__brand">' . Html::e( sprintf( $line, $offer['brand']['title'] ) ) . '</p>';
		}
		return '' === $out ? '' : '<section class="skye-card skye-offer">' . $out . '</section>';
	}

	/** Anchor of the booking block; the offer card and the listing cards link to it. */
	public const BOOKING_ID = 'booking';

	private const BOKUN_WIDGETS = 'https://widgets.bokun.io';

	/**
	 * Dates and booking: the Bókun calendar of the product this market's brand sells, on our own
	 * booking channel. The calendar is the one thing on the page that needs JavaScript, so without
	 * it the block falls back to a plain link. Empty when the brand has no channel or no product.
	 */
	/** @return array<string, string> Every text of the checkout, for its script. */
	public static function checkout_strings(): array {
		return [
			'loading' => Html::t( 'Loading dates…' ),
			'no_dates' => Html::t( 'There are no departures open for booking right now.' ),
			'when' => Html::t( 'When do you want to go?' ),
			'from' => Html::t( 'from %s' ),
			'few' => Html::t( '%s places left' ),
			'more_dates' => Html::t( 'Show more dates' ),
			'who' => Html::t( 'How do you want to travel?' ),
			'who_hint' => Html::t( 'Choose how many people travel in each option. The price is per person and includes the accommodation of that option.' ),
			'per_person' => Html::t( '%s per person' ),
			'less' => Html::t( 'One fewer' ),
			'more' => Html::t( 'One more' ),
			'details' => Html::t( 'Your details' ),
			'first' => Html::t( 'First name' ),
			'last' => Html::t( 'Last name' ),
			'email' => Html::t( 'Email' ),
			'phone' => Html::t( 'Mobile phone' ),
			'phone_help' => Html::t( 'With the country code, for example +34 600 000 000. The guide only uses it on the day of departure.' ),
			'yes' => Html::t( 'Yes' ),
			'no' => Html::t( 'No' ),
			'required' => Html::t( 'This field is required' ),
			'check' => Html::t( 'Check the fields marked in red.' ),
			'agree' => Html::t( 'I accept the booking conditions and the cancellation policy.' ),
			'agree_first' => Html::t( 'Accept the booking conditions to continue.' ),
			'total' => Html::t( 'Total' ),
			'continue' => Html::t( 'Continue to payment · %s' ),
			'reserving' => Html::t( 'Holding your places…' ),
			'no_charge_yet' => Html::t( 'Nothing is charged yet. Your places are held for 30 minutes while you pay.' ),
			'payment' => Html::t( 'Payment' ),
			'held' => Html::t( 'Your places are held for 30 minutes. Pay by card to confirm the booking.' ),
			'pay' => Html::t( 'Pay %s' ),
			'paying' => Html::t( 'Processing the payment…' ),
			'change' => Html::t( 'Change the booking' ),
			'secure' => Html::t( 'Your card details go straight to Revolut, encrypted. We never see or store them.' ),
			'sandbox' => Html::t( 'TEST MODE: no real money is charged.' ),
			'done_title' => Html::t( 'Booking confirmed' ),
			'done_text' => Html::t( 'We have sent the confirmation to %s.' ),
			'code' => Html::t( 'Booking reference:' ),
			'failed_title' => Html::t( 'The payment did not go through' ),
			'failed_text' => Html::t( 'Nothing has been charged and your places have been released. You can try again.' ),
			'pending_title' => Html::t( 'We are confirming your booking' ),
			'pending_text' => Html::t( 'We have received the payment, but the confirmation is taking longer than usual. Keep your booking reference: we will email you, and you do not need to pay again.' ),
			'err_unavailable' => Html::t( 'Booking is not available right now. Please try again in a few minutes.' ),
			'err_not_bookable' => Html::t( 'That departure can no longer be booked with those options. Choose another date or fewer places.' ),
			'err_payment_unavailable' => Html::t( 'The payment could not be started. Nothing has been charged. Please try again.' ),
			'err_too_many' => Html::t( 'Too many attempts. Please wait a few minutes.' ),
			'err_expired' => Html::t( 'The time to pay ran out and the places were released. Please start again.' ),
			'err_card' => Html::t( 'The card payment failed.' ),
		];
	}

	/** @return array<string, string> What Bókun calls each way of travelling => how we say it. */
	public static function checkout_categories(): array {
		return [
			'Only tour' => Html::t( 'Tour only, no accommodation' ),
			'Hostel' => Html::t( 'Hostel: bed in a shared room' ),
			'Medium (individual)' => Html::t( 'Standard B&B: single room' ),
			'People in Medium (double)' => Html::t( 'Standard B&B: double room' ),
			'People in Medium (triple)' => Html::t( 'Standard B&B: triple room' ),
			'Superior (individual)' => Html::t( 'Superior B&B: single room' ),
			'People in Superior (double)' => Html::t( 'Superior B&B: double room' ),
			'People in Superior (triple)' => Html::t( 'Superior B&B: triple room' ),
		];
	}

	/**
	 * Our own checkout: the shell the script fills, with a plain link for a browser without JavaScript.
	 *
	 * @param array{api:string, tour:int, lang:string, locale:string, mode:string, embed:string, max:int} $c
	 */
	public static function checkout( array $t, array $c ): string {
		$config   = $c + [ 'title' => (string) ( $t['title'] ?? '' ), 'strings' => self::checkout_strings(), 'categories' => self::checkout_categories() ];
		$fallback = Html::e( Html::t( 'Booking needs JavaScript.' ) );
		if ( ! empty( $t['offer']['booking_url'] ) ) {
			$fallback .= sprintf( ' <a href="%s" rel="noopener">%s</a>', Html::e( $t['offer']['booking_url'] ), Html::e( Html::t( 'Book on the operator\'s website' ) ) );
		}
		$json = (string) json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
		return sprintf(
			'<section class="skye-block skye-booking" id="%s"><h2 class="skye-block__title">%s</h2><div class="skye-co" data-skye-checkout="%s"><p class="skye-co__loading">%s</p></div><noscript><p class="skye-booking__fallback">%s</p></noscript></section>',
			self::BOOKING_ID,
			Html::e( Html::t( 'Dates and booking' ) ),
			Html::e( $json ),
			Html::e( Html::t( 'Loading dates…' ) ),
			$fallback
		);
	}

	public static function booking( array $t ): string {
		if ( ! empty( $t['_checkout'] ) && is_array( $t['_checkout'] ) ) {
			return self::checkout( $t, $t['_checkout'] );
		}
		$offer  = $t['offer'] ?? null;
		$widget = is_array( $offer ) ? ( $offer['booking_widget'] ?? null ) : null;
		if ( ! is_array( $widget ) || empty( $widget['channel_uuid'] ) || empty( $widget['product_id'] ) ) {
			return '';
		}
		$channel = rawurlencode( (string) $widget['channel_uuid'] );
		$product = rawurlencode( (string) $widget['product_id'] );
		$src     = sprintf( '%s/online-sales/%s/experience-calendar/%s', self::BOKUN_WIDGETS, $channel, $product );
		$loader  = sprintf( '%s/assets/javascripts/apps/build/BokunWidgetsLoader.js?bookingChannelUUID=%s', self::BOKUN_WIDGETS, $channel );

		$fallback = Html::e( Html::t( 'The booking calendar needs JavaScript.' ) );
		if ( ! empty( $offer['booking_url'] ) ) {
			$fallback .= sprintf( ' <a href="%s" rel="noopener">%s</a>', Html::e( $offer['booking_url'] ), Html::e( Html::t( 'Book on the operator\'s website' ) ) );
		}
		if ( ! empty( $t['_loopback'] ) ) {
			// A development machine: Bókun refuses the embedded calendar there, so link to it instead of framing an error page.
			$body = '<p class="skye-booking__fallback">' . Html::e( Html::t( 'Bókun does not load the booking calendar on a local address (localhost). It will appear here on the test and live sites.' ) )
				. sprintf( ' <a href="%s" target="_blank" rel="noopener">%s</a>', Html::e( $src ), Html::e( Html::t( 'Open the calendar in a new tab' ) ) ) . '</p>';
			return sprintf( '<section class="skye-block skye-booking" id="%s"><h2 class="skye-block__title">%s</h2>%s</section>', self::BOOKING_ID, Html::e( Html::t( 'Dates and booking' ) ), $body );
		}
		$body = '<p class="skye-booking__lead">' . Html::e( Html::t( 'Choose a date to see availability, room options and prices. Payment is taken securely on this page.' ) ) . '</p>'
			. sprintf( '<div class="skye-booking__widget"><div class="bokunWidget" data-src="%s"></div></div>', Html::e( $src ) )
			. '<noscript><p class="skye-booking__fallback">' . $fallback . '</p></noscript>'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the loader must sit next to its widget and is only needed on this block.
			. sprintf( '<script src="%s" async></script>', Html::e( $loader ) );

		return sprintf( '<section class="skye-block skye-booking" id="%s"><h2 class="skye-block__title">%s</h2>%s</section>', self::BOOKING_ID, Html::e( Html::t( 'Dates and booking' ) ), $body );
	}

	/** Departure card: city, time and meeting point. */
	public static function departure( array $t ): string {
		$rows = [];
		$city = Html::t( self::CITIES[ (string) ( $t['departure_city'] ?? '' ) ] ?? '' );
		$time = trim( (string) ( $t['departure_time'] ?? '' ) );
		if ( '' !== $city ) {
			$rows[ Html::t( 'Departure' ) ] = '' !== $time ? sprintf( Html::t( '%1$s at %2$s' ), $city, $time ) : $city;
		}
		if ( ! empty( $t['meeting_point'] ) ) {
			$rows[ Html::t( 'Meeting point' ) ] = (string) $t['meeting_point'];
		}
		$length = self::length( $t );
		if ( '' !== $length ) {
			$rows[ Html::t( 'Length' ) ] = $length;
		}
		if ( ! $rows ) {
			return '';
		}
		$html = '';
		foreach ( $rows as $label => $value ) {
			$html .= sprintf( '<div class="skye-fact"><dt>%s</dt><dd>%s</dd></div>', Html::e( $label ), Html::e( $value ) );
		}
		return sprintf( '<section class="skye-card skye-facts"><h2 class="skye-card__title">%s</h2><dl class="skye-facts__list">%s</dl></section>', Html::e( Html::t( 'Practical information' ) ), $html );
	}

	/** One stop of a day: its name (linked when the place has a page), what kind of stop it is and for how long. */
	private static function stop( array $stop ): string {
		$place = is_array( $stop['place'] ?? null ) ? $stop['place'] : [];
		$title = trim( (string) ( $stop['title'] ?? '' ) );
		$title = '' !== $title ? $title : trim( (string) ( $place['title'] ?? '' ) );
		if ( '' === $title ) {
			return '';
		}
		$name    = ! empty( $place['url'] ) ? sprintf( '<a href="%s">%s</a>', Html::e( $place['url'] ), Html::e( $title ) ) : Html::e( $title );
		$type    = (string) ( $stop['stop_type'] ?? 'visit' );
		$choices = Fields::tour()['days']['sub']['stops']['sub']['stop_type']['choices'];
		$meta    = [ Html::t( $choices[ $type ] ?? $choices['visit'] ) ];
		$minutes = (int) ( $stop['duration_min'] ?? 0 );
		if ( $minutes > 0 ) {
			$meta[] = Units::format_duration( $minutes );
		}
		$note  = trim( (string) ( $stop['note'] ?? '' ) );
		$img   = is_array( $place['image'] ?? null ) ? $place['image'] : null;
		$photo = '';
		if ( $img ) {
			$photo = ! empty( $img['html'] ) ? (string) $img['html'] : sprintf( '<img src="%s" alt="%s" loading="lazy" decoding="async">', Html::e( $img['url'] ?? '' ), Html::e( $img['alt'] ?? '' ) );
			$photo = '<div class="skye-tourstop__photo">' . $photo . '</div>';
		}
		return sprintf(
			'<li class="skye-tourstop skye-tourstop--%s"><div class="skye-tourstop__text"><div class="skye-tourstop__head"><span class="skye-tourstop__name">%s</span><span class="skye-tourstop__meta">%s</span></div>%s</div>%s</li>',
			Html::e( $type ),
			$name,
			Html::e( implode( ' · ', $meta ) ),
			'' !== $note ? '<p class="skye-tourstop__note">' . Html::e( $note ) . '</p>' : '',
			$photo
		);
	}

	/** Day by day: title, what the day covers, and every stop in order with the time allowed at each. */
	public static function itinerary( array $t ): string {
		$html  = '';
		$timed = false;
		foreach ( $t['days'] ?? [] as $i => $day ) {
			$title   = trim( (string) ( $day['title'] ?? '' ) );
			$summary = trim( (string) ( $day['summary'] ?? '' ) );
			$stops   = '';
			foreach ( $day['stops'] ?? [] as $stop ) {
				$stops .= is_array( $stop ) ? self::stop( $stop ) : '';
				$timed  = $timed || ( is_array( $stop ) && (int) ( $stop['duration_min'] ?? 0 ) > 0 );
			}
			if ( '' === $title && '' === $summary && '' === $stops ) {
				continue;
			}
			$html .= '<li class="skye-day">';
			$html .= '<h3 class="skye-day__title"><span class="skye-day__n">' . Html::e( sprintf( Html::t( 'Day %d' ), $i + 1 ) ) . '</span>' . ( '' !== $title ? ' ' . Html::e( $title ) : '' ) . '</h3>';
			$html .= '' !== $summary ? '<p>' . Html::e( $summary ) . '</p>' : '';
			$html .= '' !== $stops ? '<ol class="skye-tourstops" aria-label="' . Html::e( Html::t( 'Stops' ) ) . '">' . $stops . '</ol>' : '';
			$html .= '</li>';
		}
		if ( '' === $html ) {
			return '';
		}
		$small = $timed ? '<p class="skye-itinerary__small">' . Html::e( Html::t( 'The length of each stop is an estimate: it will depend on the weather, the traffic and other factors.' ) ) . '</p>' : '';
		return Html::block( 'itinerary', Html::t( 'Itinerary' ), '<ol class="skye-days">' . $html . '</ol>' . $small );
	}

	/** One sentence per line in the editor, or sentences in a row: both become a list. @return list<string> */
	private static function lines( string $text ): array {
		$parts = preg_split( '/\R+|(?<=[.!?])\s+(?=\p{Lu})/u', trim( $text ) ) ?: [];
		return array_values( array_filter( array_map( static fn( $s ) => trim( $s ), $parts ) ) );
	}

	/**
	 * The questions people ask before booking, as written on the tour. The page shows them and the
	 * FAQPage structured data repeats them, so both read this list.
	 *
	 * @return list<array{question:string, answer:string}>
	 */
	public static function faq_items( array $t ): array {
		$items = [];
		foreach ( $t['faq'] ?? [] as $row ) {
			$question = trim( (string) ( $row['question'] ?? '' ) );
			$answer   = trim( (string) ( $row['answer'] ?? '' ) );
			if ( '' !== $question && '' !== $answer ) {
				$items[] = [ 'question' => $question, 'answer' => $answer ];
			}
		}
		return $items;
	}

	public static function faq( array $t ): string {
		$html = '';
		foreach ( self::faq_items( $t ) as $item ) {
			$paragraphs = implode( '', array_map( static fn( $p ) => '<p>' . Html::e( $p ) . '</p>', preg_split( '/\R{2,}/', $item['answer'] ) ?: [] ) );
			$html      .= sprintf( '<details class="skye-faq__item"><summary>%s</summary><div class="skye-faq__answer">%s</div></details>', Html::e( $item['question'] ), $paragraphs );
		}
		if ( '' === $html ) {
			return '';
		}
		return Html::block( 'faq', Html::t( 'Frequently asked questions' ), '<div class="skye-faq__list">' . $html . '</div>' ) . PlaceBlocks::sources( $t );
	}

	/** Where you sleep: the options offered when booking, and what to know before choosing. */
	public static function accommodation( array $t ): string {
		$options = '';
		foreach ( $t['accommodation_options'] ?? [] as $o ) {
			$name = trim( (string) ( $o['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$options .= '<div class="skye-lodgingopt"><dt>' . Html::e( $name ) . '</dt><dd>' . Html::e( trim( (string) ( $o['description'] ?? '' ) ) ) . '</dd></div>';
		}
		$intro = trim( (string) ( $t['accommodation_intro'] ?? '' ) );
		$notes = self::lines( (string) ( $t['accommodation_notes'] ?? '' ) );
		$html  = '' !== $intro ? '<p>' . Html::e( $intro ) . '</p>' : '';
		$html .= '' !== $options ? '<dl class="skye-lodgingopts">' . $options . '</dl>' : '';
		$html .= $notes ? '<ul class="skye-lodgingnotes">' . implode( '', array_map( static fn( $n ) => '<li>' . Html::e( $n ) . '</li>', $notes ) ) . '</ul>' : '';
		return Html::block( 'accommodation', Html::t( 'Accommodation' ), $html );
	}

	public static function includes( array $t ): string {
		$list = static function ( string $title, string $class, array $items ): string {
			if ( ! $items ) {
				return '';
			}
			return '<div class="skye-includes__col"><h3>' . Html::e( $title ) . '</h3><ul class="' . $class . '">' . implode( '', array_map( static fn( $i ) => '<li>' . Html::e( $i ) . '</li>', $items ) ) . '</ul></div>';
		};
		$html  = $list( Html::t( 'Included' ), 'skye-includes__yes', self::lines( (string) ( $t['includes'] ?? '' ) ) );
		$html .= $list( Html::t( 'Not included' ), 'skye-includes__no', self::lines( (string) ( $t['excludes'] ?? '' ) ) );
		return '' === $html ? '' : Html::block( 'includes', Html::t( 'What is included' ), '<div class="skye-includes__cols">' . $html . '</div>' );
	}

	/** Comparison card for the tours listing. */
	public static function card( array $t, string $market, bool $featured = false ): string {
		$img   = $t['image'] ?? null;
		$photo = $img ? ( ! empty( $img['html'] ) ? (string) $img['html'] : sprintf( '<img src="%s" alt="%s" loading="lazy" decoding="async">', Html::e( $img['url'] ?? '' ), Html::e( $img['alt'] ?? '' ) ) ) : '';
		$price = self::price( $t, $market );
		$days  = '';
		foreach ( $t['day_titles'] ?? array_column( $t['days'] ?? [], 'title' ) as $i => $title ) {
			if ( '' !== trim( (string) $title ) ) {
				$days .= '<div class="skye-tourcard__day"><strong>' . Html::e( sprintf( Html::t( 'Day %d' ), $i + 1 ) ) . '</strong><span>' . Html::e( $title ) . '</span></div>';
			}
		}
		$buttons = '';
		if ( ( ! empty( $t['offer']['booking_widget'] ) || ! empty( $t['_checkout'] ) ) && ! empty( $t['url'] ) ) {
			$buttons .= sprintf( '<a class="skye-btn skye-btn--primary" href="%s#%s">%s</a>', Html::e( $t['url'] ), self::BOOKING_ID, Html::e( Html::t( 'See dates and book' ) ) );
		} elseif ( ! empty( $t['offer']['booking_url'] ) ) {
			$buttons .= sprintf( '<a class="skye-btn skye-btn--primary" href="%s" rel="noopener">%s</a>', Html::e( $t['offer']['booking_url'] ), Html::e( Html::t( 'See dates and book' ) ) );
		}
		if ( ! empty( $t['url'] ) ) {
			$buttons .= sprintf( '<a class="skye-btn skye-btn--outline" href="%s">%s</a>', Html::e( $t['url'] ), Html::e( Html::t( 'Full itinerary' ) ) );
		}
		$note = self::language_note( $t );

		return '<article class="skye-card skye-tourcard' . ( $featured ? ' skye-tourcard--featured' : '' ) . '">'
			. '<div class="skye-tourcard__photo">' . $photo . '</div>'
			. '<div class="skye-tourcard__body">'
			. '<div class="skye-tourcard__head"><div>'
			. ( $featured ? '<span class="skye-eyebrow">' . Html::e( Html::t( 'The most complete' ) ) . '</span>' : '' )
			. '<h2>' . Html::e( $t['title'] ?? '' ) . '</h2><span class="skye-tourcard__meta">' . Html::e( self::length( $t ) ) . '</span></div>'
			. ( null !== $price ? '<div class="skye-tourcard__price"><span>' . Html::e( Html::t( 'From' ) ) . '</span><strong>' . Html::e( $price ) . '</strong><span>' . Html::e( Html::t( 'per person' ) ) . '</span></div>' : '' )
			. '</div>'
			. ( ! empty( $t['summary'] ) ? '<p class="skye-tourcard__summary">' . Html::e( $t['summary'] ) . '</p>' : '' )
			. ( '' !== $days ? '<div class="skye-tourcard__days">' . $days . '</div>' : '' )
			. ( '' !== $note ? '<p class="skye-tourcard__note">' . Html::e( $note ) . '</p>' : '' )
			. ( '' !== $buttons ? '<div class="skye-tourcard__actions">' . $buttons . '</div>' : '' )
			. '</div></article>';
	}

	/**
	 * The tours listing: intro and one card per tour, shortest first; the longest is the featured one.
	 *
	 * @param list<array<string, mixed>> $tours
	 */
	public static function listing( array $tours, string $market ): string {
		usort( $tours, static fn( $a, $b ) => (int) ( $a['days_count'] ?? 0 ) <=> (int) ( $b['days_count'] ?? 0 ) );
		$lengths = array_values( array_unique( array_filter( array_map( static fn( $t ) => (int) ( $t['days_count'] ?? 0 ), $tours ) ) ) );
		$title   = match ( count( $lengths ) ) {
			0       => Html::t( 'Isle of Skye tours' ),
			1       => sprintf( Html::t( 'Isle of Skye tours of %d days' ), $lengths[0] ),
			default => sprintf( Html::t( 'Isle of Skye tours of %1$s and %2$d days' ), implode( ', ', array_slice( $lengths, 0, -1 ) ), end( $lengths ) ),
		};
		$intro = '<header class="skye-listing__intro"><span class="skye-eyebrow">' . Html::e( Html::t( 'Departing from Edinburgh' ) ) . '</span>'
			. '<h1 class="skye-listing__title">' . Html::e( $title ) . '</h1>'
			. '<p class="skye-listing__lead">' . Html::e( Html::t( 'No car, no single-track roads, no hunting for a bed. We pick you up in the centre of Edinburgh and take you through the Highlands to Skye with a guide.' ) ) . '</p></header>';
		$cards = '';
		$last  = count( $tours ) - 1;
		foreach ( $tours as $i => $t ) {
			$cards .= self::card( $t, $market, $last > 0 && $i === $last );
		}
		return '<div class="skye-listing">' . $intro . ( '' !== $cards ? '<div class="skye-tourcards">' . $cards . '</div>' : '<p>' . Html::e( Html::t( 'No tours are on sale right now.' ) ) . '</p>' ) . '</div>';
	}
}
