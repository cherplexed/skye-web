/* Our own checkout (issue #33). Talks to /skye/v1/checkout; Revolut's script collects the card. */
( function () {
	'use strict';
	var root = document.querySelector( '[data-skye-checkout]' );
	if ( ! root ) {
		return;
	}
	var cfg = JSON.parse( root.getAttribute( 'data-skye-checkout' ) );
	var T = cfg.strings;
	var state = { cal: null, date: null, people: {}, contact: {}, answers: {}, agree: false, hold: null, busy: false, error: '', fieldError: {}, month: null, done: null, quote: null, quoting: 0, resuming: false, notice: '' };
	var KEY = 'skye_co_hold';

	function t( key, a ) {
		var s = T[ key ] || key;
		return a === undefined ? s : s.replace( '%s', a );
	}
	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( k === 'class' ) {
				n.className = v;
			} else if ( k === 'text' ) {
				n.textContent = v;
			} else if ( k.slice( 0, 2 ) === 'on' ) {
				n.addEventListener( k.slice( 2 ), v );
			} else if ( v === true ) {
				n.setAttribute( k, '' );
			} else if ( v !== false && v !== null && v !== undefined ) {
				n.setAttribute( k, v );
			}
		} );
		( kids || [] ).forEach( function ( c ) {
			if ( c ) {
				n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
			}
		} );
		return n;
	}
	function money( amount ) {
		try {
			return new Intl.NumberFormat( cfg.locale, { style: 'currency', currency: state.cal.currency } ).format( amount );
		} catch ( e ) {
			return amount.toFixed( 2 ) + ' ' + state.cal.currency;
		}
	}
	function day( iso, long ) {
		var d = new Date( iso + 'T00:00:00Z' );
		return new Intl.DateTimeFormat( cfg.locale, { weekday: long ? 'long' : 'short', day: 'numeric', month: long ? 'long' : 'short', year: 'numeric', timeZone: 'UTC' } ).format( d );
	}
	function api( path, body ) {
		var url = cfg.api + path + ( body ? '' : '?tour=' + cfg.tour + '&lang=' + encodeURIComponent( cfg.lang ) );
		var opt = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( Object.assign( { tour: cfg.tour, lang: cfg.lang }, body ) ) } : {};
		return fetch( url, opt ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok || ! j.data ) {
					throw j.error || { reason: 'unavailable' };
				}
				return j.data;
			} );
		} );
	}
	function count() {
		return Object.keys( state.people ).reduce( function ( n, k ) { return n + state.people[ k ]; }, 0 );
	}
	function total() {
		return Object.keys( state.people ).reduce( function ( n, k ) { return n + state.people[ k ] * ( state.date.prices[ k ] || 0 ); }, 0 );
	}
	function catTitle( c ) {
		return cfg.categories[ c.title ] || c.title;
	}
	function fail( e ) {
		state.busy = false;
		state.fieldError = {};
		if ( e && e.field ) {
			state.fieldError[ e.field ] = e.message || t( 'required' );
			state.error = t( 'check' );
		} else {
			state.error = t( e && T[ 'err_' + e.reason ] ? 'err_' + e.reason : 'err_unavailable' );
		}
		render();
	}

	// The questions depend on who travels (the bed for a double, a warning for tour only), so they are asked for each choice.
	function requote() {
		var ticket = ++state.quoting;
		state.quote = null;
		if ( ! state.date || ! count() ) {
			return;
		}
		setTimeout( function () {
			if ( ticket !== state.quoting ) {
				return;
			}
			api( '/quote', { date: state.date.date, start_time_id: state.date.start_time_id, rate_id: state.cal.rate_id, passengers: state.people } ).then( function ( q ) {
				if ( ticket === state.quoting ) {
					state.quote = q;
					state.error = '';
					render();
				}
			} ).catch( function ( e ) {
				if ( ticket === state.quoting ) {
					fail( e );
				}
			} );
		}, 350 );
	}

	function step( n, title, body ) {
		return el( 'section', { class: 'skye-co__step' }, [ el( 'h3', { class: 'skye-co__title' }, [ el( 'span', { class: 'skye-co__n', text: String( n ) } ), title ] ) ].concat( body ) );
	}

	function ym( iso ) {
		return iso.slice( 0, 7 );
	}
	function shortMoney( amount ) {
		try {
			return new Intl.NumberFormat( cfg.locale, { style: 'currency', currency: state.cal.currency, currencyDisplay: 'narrowSymbol', minimumFractionDigits: 0, maximumFractionDigits: amount % 1 ? 2 : 0 } ).format( amount );
		} catch ( e ) {
			return money( amount );
		}
	}
	function fromPrice( d ) {
		return Math.min.apply( null, Object.keys( d.prices ).map( function ( k ) { return d.prices[ k ]; } ) );
	}
	function pick( d ) {
		state.date = d;
		Object.keys( state.people ).forEach( function ( k ) { if ( ! ( k in d.prices ) ) { delete state.people[ k ]; } } );
		state.error = '';
		state.notice = '';
		requote();
		render();
		var on = root.querySelector( '.skye-co__day.is-on' );
		if ( on ) {
			on.focus( { preventScroll: true } );
		}
	}
	function turn( delta ) {
		var p = state.month.split( '-' );
		var d = new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1 + delta, 1 ) );
		state.month = d.toISOString().slice( 0, 7 );
		render();
	}

	// A month at a time, Monday first: the days with a departure are buttons with their lowest price.
	function dates() {
		var all = state.cal.dates;
		var first = ym( all[ 0 ].date );
		var last = ym( all[ all.length - 1 ].date );
		var open = {};
		all.forEach( function ( d ) { if ( ! open[ d.date ] ) { open[ d.date ] = d; } } );
		var p = state.month.split( '-' );
		var y = +p[ 0 ];
		var m = +p[ 1 ] - 1;
		var title = new Intl.DateTimeFormat( cfg.locale, { month: 'long', year: 'numeric', timeZone: 'UTC' } ).format( new Date( Date.UTC( y, m, 1 ) ) );
		var head = el( 'div', { class: 'skye-co__calhead' }, [
			el( 'button', { type: 'button', class: 'skye-co__nav', 'aria-label': t( 'prev_month' ), disabled: state.month <= first, onclick: function () { turn( -1 ); }, text: '‹' } ),
			el( 'strong', { class: 'skye-co__month', 'aria-live': 'polite', text: title.charAt( 0 ).toUpperCase() + title.slice( 1 ) } ),
			el( 'button', { type: 'button', class: 'skye-co__nav', 'aria-label': t( 'next_month' ), disabled: state.month >= last, onclick: function () { turn( 1 ); }, text: '›' } ),
		] );
		var cells = [];
		var i;
		for ( i = 0; i < 7; i++ ) {
			// 1 January 2024 was a Monday.
			cells.push( el( 'span', { class: 'skye-co__dow', 'aria-hidden': 'true', text: new Intl.DateTimeFormat( cfg.locale, { weekday: 'narrow', timeZone: 'UTC' } ).format( new Date( Date.UTC( 2024, 0, 1 + i ) ) ).toUpperCase() } ) );
		}
		var lead = ( new Date( Date.UTC( y, m, 1 ) ).getUTCDay() + 6 ) % 7;
		for ( i = 0; i < lead; i++ ) {
			cells.push( el( 'span', { class: 'skye-co__day is-blank', 'aria-hidden': 'true' } ) );
		}
		var days = new Date( Date.UTC( y, m + 1, 0 ) ).getUTCDate();
		var some = false;
		for ( i = 1; i <= days; i++ ) {
			var iso = state.month + '-' + ( i < 10 ? '0' : '' ) + i;
			var d = open[ iso ];
			if ( ! d ) {
				cells.push( el( 'span', { class: 'skye-co__day is-off', 'aria-hidden': 'true' }, [ el( 'b', { text: String( i ) } ) ] ) );
				continue;
			}
			some = true;
			var on = state.date && state.date.date === iso;
			var few = d.seats <= 6;
			cells.push( el( 'button', {
				type: 'button',
				class: 'skye-co__day' + ( on ? ' is-on' : '' ) + ( few ? ' is-few' : '' ),
				'aria-pressed': on ? 'true' : 'false',
				'aria-label': day( iso, true ) + ', ' + t( 'from', money( fromPrice( d ) ) ) + ( few ? ', ' + t( 'few', d.seats ) : '' ),
				onclick: ( function ( x ) { return function () { pick( x ); }; }( d ) ),
			}, [ el( 'b', { text: String( i ) } ), el( 'span', { text: shortMoney( fromPrice( d ) ) } ) ] ) );
		}
		var legend = el( 'p', { class: 'skye-co__legend' }, [
			el( 'span', {}, [ el( 'i', { class: 'skye-co__key' } ), t( 'price_legend' ) ] ),
			el( 'span', {}, [ el( 'i', { class: 'skye-co__key is-few' } ), t( 'few_legend' ) ] ),
		] );
		var chosen = state.date ? el( 'p', { class: 'skye-co__chosen', role: 'status' }, [
			el( 'strong', { text: day( state.date.date, true ) + ' · ' + state.date.time } ),
			state.date.seats <= 6 ? el( 'em', { text: ' ' + t( 'few', state.date.seats ) } ) : null,
		] ) : null;
		return step( 1, t( 'when' ), [ el( 'div', { class: 'skye-co__cal' }, [ head, el( 'div', { class: 'skye-co__grid7' + ( some ? '' : ' is-empty' ) }, cells ) ] ), legend, chosen ] );
	}

	function people() {
		var rows = state.cal.categories.filter( function ( c ) { return c.id in state.date.prices; } ).map( function ( c ) {
			var n = state.people[ c.id ] || 0;
			function set( delta ) {
				return function () {
					var next = Math.max( 0, n + delta );
					if ( delta > 0 && count() >= Math.min( cfg.max, state.date.seats ) ) {
						return;
					}
					if ( next ) { state.people[ c.id ] = next; } else { delete state.people[ c.id ]; }
					state.error = '';
					requote();
					render();
				};
			}
			return el( 'div', { class: 'skye-co__row' }, [
				el( 'div', { class: 'skye-co__cat' }, [ el( 'strong', { text: catTitle( c ) } ), el( 'span', { text: t( 'per_person', money( state.date.prices[ c.id ] ) ) } ) ] ),
				el( 'button', { type: 'button', class: 'skye-co__pm', 'aria-label': t( 'less' ) + ': ' + catTitle( c ), disabled: n === 0, onclick: set( -1 ), text: '−' } ),
				el( 'output', { class: 'skye-co__qty', text: String( n ) } ),
				el( 'button', { type: 'button', class: 'skye-co__pm', 'aria-label': t( 'more' ) + ': ' + catTitle( c ), onclick: set( 1 ), text: '+' } ),
			] );
		} );
		return step( 2, t( 'who' ), [ el( 'p', { class: 'skye-co__hint', text: t( 'who_hint' ) } ) ].concat( rows ) );
	}

	function field( id, label, type, store, required, help ) {
		var err = state.fieldError[ id ];
		var input;
		var common = { id: 'skye-co-' + id, name: id, required: !! required, 'aria-invalid': err ? 'true' : null };
		if ( type === 'BOOLEAN' ) {
			input = el( 'div', { class: 'skye-co__yn' }, [ 'true', 'false' ].map( function ( v ) {
				return el( 'label', {}, [ el( 'input', { type: 'radio', name: id, value: v, checked: store[ id ] === v, onchange: function () { store[ id ] = v; } } ), ' ' + t( v === 'true' ? 'yes' : 'no' ) ] );
			} ) );
		} else if ( type === 'CHECKBOX_TOGGLE' ) {
			return el( 'label', { class: 'skye-co__check' }, [ el( 'input', Object.assign( common, { type: 'checkbox', checked: store[ id ] === 'true', onchange: function ( e ) { store[ id ] = e.target.checked ? 'true' : ''; } } ) ), el( 'span', { text: label + ( required ? ' *' : '' ) } ) ] );
		} else if ( type === 'LONG_TEXT' ) {
			input = el( 'textarea', Object.assign( common, { rows: '3', oninput: function ( e ) { store[ id ] = e.target.value; } } ) );
			input.value = store[ id ] || '';
		} else {
			input = el( 'input', Object.assign( common, { type: type, value: store[ id ] || '', autocomplete: { firstName: 'given-name', lastName: 'family-name', email: 'email', phoneNumber: 'tel' }[ id ] || 'off', oninput: function ( e ) { store[ id ] = e.target.value; } } ) );
		}
		return el( 'div', { class: 'skye-co__field' }, [ el( 'label', { for: 'skye-co-' + id, text: label + ( required ? ' *' : '' ) } ), input, help ? el( 'small', { text: help } ) : null, err ? el( 'small', { class: 'skye-co__err', role: 'alert', text: err } ) : null ] );
	}

	function details() {
		var c = state.contact;
		var qs = state.quote.questions.map( function ( q ) {
			return field( q.id, q.label, q.type === 'SHORT_TEXT' ? 'text' : q.type, state.answers, q.required, q.help );
		} );
		return step( 3, t( 'details' ), [
			el( 'div', { class: 'skye-co__grid' }, [ field( 'firstName', t( 'first' ), 'text', c, true ), field( 'lastName', t( 'last' ), 'text', c, true ), field( 'email', t( 'email' ), 'email', c, true ), field( 'phoneNumber', t( 'phone' ), 'tel', c, true, t( 'phone_help' ) ) ] ),
		].concat( qs ) );
	}

	function summary() {
		var lines = state.cal.categories.filter( function ( c ) { return state.people[ c.id ]; } ).map( function ( c ) {
			return el( 'div', { class: 'skye-co__line' }, [ el( 'span', { text: state.people[ c.id ] + ' × ' + catTitle( c ) } ), el( 'span', { text: money( state.people[ c.id ] * state.date.prices[ c.id ] ) } ) ] );
		} );
		return el( 'div', { class: 'skye-co__summary' }, [ el( 'strong', { text: cfg.title } ), el( 'span', { text: day( state.date.date, true ) + ' · ' + state.date.time } ) ].concat( lines, [ el( 'div', { class: 'skye-co__line skye-co__total' }, [ el( 'span', { text: t( 'total' ) } ), el( 'span', { text: money( state.hold ? state.hold.amount : ( state.quote ? state.quote.amount : total() ) ) } ) ] ) ] ) );
	}

	function missing() {
		var need = [ 'firstName', 'lastName', 'email', 'phoneNumber' ].filter( function ( k ) { return ! ( state.contact[ k ] || '' ).trim(); } );
		state.quote.questions.forEach( function ( q ) { if ( q.required && ! state.answers[ q.id ] ) { need.push( q.id ); } } );
		return need;
	}

	function reserve() {
		var need = missing();
		state.fieldError = {};
		if ( need.length || ! state.agree ) {
			need.forEach( function ( k ) { state.fieldError[ k ] = t( 'required' ); } );
			state.error = need.length ? t( 'check' ) : t( 'agree_first' );
			return render();
		}
		state.busy = true;
		state.error = '';
		render();
		var answers = {};
		state.quote.questions.forEach( function ( q ) { if ( state.answers[ q.id ] ) { answers[ q.id ] = state.answers[ q.id ]; } } );
		api( '/reserve', { date: state.date.date, start_time_id: state.date.start_time_id, rate_id: state.cal.rate_id, passengers: state.people, contact: state.contact, answers: answers } ).then( function ( hold ) {
			state.hold = hold;
			state.busy = false;
			remember( { reference: hold.reference, confirmation_code: hold.confirmation_code, email: state.contact.email, tour: cfg.tour, at: Date.now() } );
			render();
			mountCard();
		} ).catch( fail );
	}

	function loadSdk() {
		return new Promise( function ( ok, ko ) {
			if ( window.RevolutCheckout ) {
				return ok();
			}
			var s = el( 'script', { src: cfg.embed, async: true } );
			s.onload = ok;
			s.onerror = ko;
			document.head.appendChild( s );
		} );
	}
	var card = null;
	var payments = null;
	var shownView = null;
	// The hold is remembered so that a customer who pays in the Revolut app and comes back to a fresh page still gets the result.
	function remember( v ) {
		try {
			if ( v ) {
				window.localStorage.setItem( KEY, JSON.stringify( v ) );
			} else {
				window.localStorage.removeItem( KEY );
			}
		} catch ( e ) {
			void e;
		}
	}
	function remembered() {
		try {
			var v = JSON.parse( window.localStorage.getItem( KEY ) || 'null' );
			return v && v.tour === cfg.tour && Date.now() - v.at < 45 * 60000 ? v : null;
		} catch ( e ) {
			return null;
		}
	}
	function dropWallets() {
		if ( payments ) {
			try { payments.destroy(); } catch ( e ) { void e; }
			payments = null;
		}
	}
	function paid() {
		state.busy = true;
		state.error = '';
		paintPay();
		finish( 0 );
	}
	function payError( key ) {
		return function ( message ) {
			var text = message && message.message ? message.message : message;
			state.busy = false;
			state.error = t( key ) + ( text ? ' (' + String( text ).slice( 0, 140 ) + ')' : '' );
			paintPay();
		};
	}
	function mountCard() {
		loadSdk().then( function () {
			mountWallets();
			return cfg.mode === 'sandbox' ? window.RevolutCheckout( state.hold.order_token, 'sandbox' ) : window.RevolutCheckout( state.hold.order_token );
		} ).then( function ( rc ) {
			card = rc.createCardField( {
				target: document.getElementById( 'skye-co-card' ),
				locale: cfg.lang,
				onSuccess: function () { finish( 0 ); },
				onError: payError( 'err_card' ),
				onCancel: function () { state.busy = false; paintPay(); },
			} );
		} ).catch( function () { fail( { reason: 'payment_unavailable' } ); } );
	}

	// Apple Pay or Google Pay (whichever the device offers) and Revolut Pay, above the card. They pay the same
	// order as the card, and none of them is needed: if one cannot be drawn, the card is still there.
	function mountWallets() {
		var box = document.getElementById( 'skye-co-wallets' );
		if ( ! box || ! cfg.public || typeof window.RevolutCheckout.payments !== 'function' ) {
			return;
		}
		var hold = state.hold;
		var amount = Math.round( hold.amount * 100 );
		var order = function () { return Promise.resolve( { publicId: hold.order_token } ); };
		var show = function () { box.hidden = false; };
		Promise.resolve( window.RevolutCheckout.payments( { publicToken: cfg.public, mode: cfg.mode, locale: cfg.lang } ) ).then( function ( p ) {
			if ( state.hold !== hold ) {
				return p.destroy();
			}
			payments = p;
			try {
				var back = window.location.origin + window.location.pathname;
				p.revolutPay.mount( document.getElementById( 'skye-co-rpay' ), {
					currency: hold.currency,
					totalAmount: amount,
					createOrder: order,
					buttonStyle: { height: '48px', radius: 'small', size: 'large', variant: 'dark', action: 'pay' },
					mobileRedirectUrls: { success: back + '?skye_pay=ok', failure: back + '?skye_pay=no', cancel: back + '?skye_pay=no' },
				} );
				p.revolutPay.on( 'payment', function ( ev ) {
					if ( ev.type === 'success' ) {
						paid();
					} else if ( ev.type === 'error' ) {
						payError( 'err_pay' )( ev.error );
					}
				} );
				show();
			} catch ( e ) {
				void e;
			}
			try {
				var target = document.getElementById( 'skye-co-wallet' );
				var pr = p.paymentRequest( target, {
					currency: hold.currency,
					amount: amount,
					createOrder: order,
					onSuccess: paid,
					onError: payError( 'err_pay' ),
					buttonStyle: { height: '48px', radius: 'small', size: 'large', variant: 'dark', action: 'pay' },
				} );
				pr.canMakePayment().then( function ( method ) {
					if ( method ) {
						target.hidden = false;
						show();
						return pr.render();
					}
					pr.destroy();
				} ).catch( function () {} );
			} catch ( e ) {
				void e;
			}
		} ).catch( function () {} );
	}
	function pay() {
		if ( ! card || state.busy ) {
			return;
		}
		state.busy = true;
		state.error = '';
		paintPay();
		card.submit( { name: ( state.contact.firstName + ' ' + state.contact.lastName ).trim(), email: state.contact.email, phone: state.contact.phoneNumber } );
	}
	function finish( tries ) {
		tries = typeof tries === 'number' ? tries : 0;
		api( '/complete', { reference: state.hold.reference } ).then( function ( r ) {
			if ( r.status === 'pending' && tries < 15 ) {
				return setTimeout( function () { finish( tries + 1 ); }, 2000 );
			}
			state.busy = false;
			state.resuming = false;
			state.done = r;
			remember( null );
			dropWallets();
			render();
		} ).catch( function () {
			if ( tries < 15 ) {
				return setTimeout( function () { finish( tries + 1 ); }, 2000 );
			}
			state.busy = false;
			state.resuming = false;
			state.done = { status: 'pending', confirmation_code: state.hold.confirmation_code };
			render();
		} );
	}
	function back() {
		var ref = state.hold.reference;
		state.hold = null;
		card = null;
		dropWallets();
		remember( null );
		state.error = '';
		render();
		api( '/cancel', { reference: ref } ).catch( function () {} );
	}

	// Only the pay button and the message change while the card field is mounted: a full render would destroy it.
	function paintPay() {
		var b = document.getElementById( 'skye-co-pay' );
		var m = document.getElementById( 'skye-co-msg' );
		if ( b ) {
			b.disabled = state.busy;
			b.textContent = state.busy ? t( 'paying' ) : t( 'pay', money( state.hold.amount ) );
		}
		if ( m ) {
			m.textContent = state.error;
			m.hidden = ! state.error;
		}
	}

	function render() {
		var kids;
		if ( state.done ) {
			var ok = state.done.status === 'confirmed';
			kids = [ el( 'div', { class: 'skye-co__done' + ( ok ? '' : ' is-warn' ), role: 'status' }, [
				el( 'h3', { text: ok ? t( 'done_title' ) : ( state.done.status === 'failed' ? t( 'failed_title' ) : t( 'pending_title' ) ) } ),
				el( 'p', { text: ok ? t( 'done_text', state.done.email || state.contact.email || state.hold.email ) : ( state.done.status === 'failed' ? t( 'failed_text' ) : t( 'pending_text' ) ) } ),
				state.done.confirmation_code ? el( 'p', { class: 'skye-co__code' }, [ t( 'code' ) + ' ', el( 'strong', { text: state.done.confirmation_code } ) ] ) : null,
				ok && state.date ? summary() : null,
			] ) ];
		} else if ( state.resuming ) {
			kids = [ el( 'p', { class: 'skye-co__loading', role: 'status', text: t( 'paying' ) } ) ];
		} else if ( ! state.cal ) {
			kids = [ el( 'p', { class: 'skye-co__loading', text: state.error || t( 'loading' ) } ) ];
		} else if ( ! state.cal.dates.length ) {
			kids = [ el( 'p', { text: t( 'no_dates' ) } ) ];
		} else if ( state.hold ) {
			kids = [ summary(), step( 4, t( 'payment' ), [
				el( 'p', { class: 'skye-co__hint', text: t( 'held' ) } ),
				el( 'div', { id: 'skye-co-wallets', class: 'skye-co__wallets', hidden: true }, [
					el( 'div', { id: 'skye-co-wallet', class: 'skye-co__wallet', hidden: true } ),
					el( 'div', { id: 'skye-co-rpay', class: 'skye-co__wallet' } ),
					el( 'p', { class: 'skye-co__or' }, [ el( 'span', { text: t( 'or_card' ) } ) ] ),
				] ),
				el( 'div', { id: 'skye-co-card', class: 'skye-co__card' } ),
				el( 'p', { id: 'skye-co-msg', class: 'skye-co__err', role: 'alert', hidden: ! state.error, text: state.error } ),
				el( 'button', { id: 'skye-co-pay', type: 'button', class: 'skye-btn skye-btn--primary skye-co__go', onclick: pay, text: t( 'pay', money( state.hold.amount ) ) } ),
				el( 'button', { type: 'button', class: 'skye-co__more', onclick: back, text: t( 'change' ) } ),
				el( 'p', { class: 'skye-co__small', text: t( 'secure' ) + ( cfg.mode === 'sandbox' ? ' ' + t( 'sandbox' ) : '' ) } ),
			] ) ];
		} else {
			kids = [ state.notice ? el( 'p', { class: 'skye-co__err', role: 'alert', text: state.notice } ) : null, dates() ];
			if ( state.date ) {
				kids.push( people() );
			}
			if ( state.date && count() && ! state.quote ) {
				kids.push( state.error ? el( 'p', { class: 'skye-co__err', role: 'alert', text: state.error } ) : el( 'p', { class: 'skye-co__loading', text: t( 'pricing' ) } ) );
			}
			if ( state.date && count() && state.quote ) {
				kids.push( details(), summary(),
					el( 'label', { class: 'skye-co__check' }, [ el( 'input', { type: 'checkbox', checked: state.agree, onchange: function ( e ) { state.agree = e.target.checked; } } ), el( 'span', { text: t( 'agree' ) + ' *' } ) ] ),
					state.error ? el( 'p', { class: 'skye-co__err', role: 'alert', text: state.error } ) : null,
					el( 'button', { type: 'button', class: 'skye-btn skye-btn--primary skye-co__go', disabled: state.busy, onclick: reserve, text: state.busy ? t( 'reserving' ) : t( 'continue', money( state.quote.amount ) ) } ),
					el( 'p', { class: 'skye-co__small', text: t( 'no_charge_yet' ) } ) );
			}
		}
		root.replaceChildren.apply( root, kids.filter( Boolean ) );
		// Moving to the payment or to the result replaces a long form with a short block: without this
		// the window is left looking at whatever comes after the checkout.
		var view = state.done ? 'done' : ( state.resuming ? 'wait' : ( state.hold ? 'pay' : 'form' ) );
		if ( view !== shownView ) {
			if ( shownView !== null ) {
				( root.closest( '.skye-booking' ) || root ).scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
			shownView = view;
		}
	}

	function calendar() {
		api( '/calendar' ).then( function ( cal ) {
			state.cal = cal;
			if ( cal.dates.length ) {
				state.month = ym( cal.dates[ 0 ].date );
			}
			render();
		} ).catch( function () { state.error = t( 'err_unavailable' ); render(); } );
	}

	// Back from paying with Revolut Pay on a phone: the page is new, the hold is the one remembered.
	var came = new URLSearchParams( window.location.search ).get( 'skye_pay' );
	var kept = came ? remembered() : null;
	if ( came ) {
		window.history.replaceState( null, '', window.location.pathname + '#' + ( ( root.closest( '.skye-booking' ) || root ).id || '' ) );
	}
	if ( kept && came === 'ok' ) {
		state.hold = kept;
		state.resuming = true;
		render();
		( root.closest( '.skye-booking' ) || root ).scrollIntoView( { block: 'start' } );
		finish( 0 );
		return;
	}
	if ( kept ) {
		remember( null );
		api( '/cancel', { reference: kept.reference } ).catch( function () {} );
		state.notice = t( 'failed_text' );
	}
	render();
	calendar();
}() );
