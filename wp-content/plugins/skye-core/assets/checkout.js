/* Our own checkout (issue #33). Talks to /skye/v1/checkout; Revolut's script collects the card. */
( function () {
	'use strict';
	var root = document.querySelector( '[data-skye-checkout]' );
	if ( ! root ) {
		return;
	}
	var cfg = JSON.parse( root.getAttribute( 'data-skye-checkout' ) );
	var T = cfg.strings;
	var state = { cal: null, date: null, people: {}, contact: {}, answers: {}, agree: false, hold: null, busy: false, error: '', fieldError: {}, shown: 8, done: null };

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

	function step( n, title, body ) {
		return el( 'section', { class: 'skye-co__step' }, [ el( 'h3', { class: 'skye-co__title' }, [ el( 'span', { class: 'skye-co__n', text: String( n ) } ), title ] ) ].concat( body ) );
	}

	function dates() {
		var list = state.cal.dates.slice( 0, state.shown ).map( function ( d ) {
			var from = Math.min.apply( null, Object.keys( d.prices ).map( function ( k ) { return d.prices[ k ]; } ) );
			var on = state.date && state.date.date === d.date;
			return el( 'button', { type: 'button', class: 'skye-co__date' + ( on ? ' is-on' : '' ), 'aria-pressed': on ? 'true' : 'false', onclick: function () {
				state.date = d;
				Object.keys( state.people ).forEach( function ( k ) { if ( ! ( k in d.prices ) ) { delete state.people[ k ]; } } );
				render();
			} }, [ el( 'strong', { text: day( d.date ) } ), el( 'span', { text: t( 'from', money( from ) ) } ), d.seats <= 6 ? el( 'em', { text: t( 'few', d.seats ) } ) : null ] );
		} );
		var more = state.cal.dates.length > state.shown ? el( 'button', { type: 'button', class: 'skye-co__more', text: t( 'more_dates' ), onclick: function () { state.shown += 12; render(); } } ) : null;
		return step( 1, t( 'when' ), [ el( 'div', { class: 'skye-co__dates' }, list ), more ] );
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
		var qs = state.cal.questions.map( function ( q ) {
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
		return el( 'div', { class: 'skye-co__summary' }, [ el( 'strong', { text: cfg.title } ), el( 'span', { text: day( state.date.date, true ) + ' · ' + state.date.time } ) ].concat( lines, [ el( 'div', { class: 'skye-co__line skye-co__total' }, [ el( 'span', { text: t( 'total' ) } ), el( 'span', { text: money( state.hold ? state.hold.amount : total() ) } ) ] ) ] ) );
	}

	function missing() {
		var need = [ 'firstName', 'lastName', 'email', 'phoneNumber' ].filter( function ( k ) { return ! ( state.contact[ k ] || '' ).trim(); } );
		state.cal.questions.forEach( function ( q ) { if ( q.required && ! state.answers[ q.id ] ) { need.push( q.id ); } } );
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
		api( '/reserve', { date: state.date.date, start_time_id: state.date.start_time_id, rate_id: state.cal.rate_id, passengers: state.people, contact: state.contact, answers: state.answers } ).then( function ( hold ) {
			state.hold = hold;
			state.busy = false;
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
	function mountCard() {
		loadSdk().then( function () {
			return cfg.mode === 'sandbox' ? window.RevolutCheckout( state.hold.order_token, 'sandbox' ) : window.RevolutCheckout( state.hold.order_token );
		} ).then( function ( rc ) {
			card = rc.createCardField( {
				target: document.getElementById( 'skye-co-card' ),
				locale: cfg.lang,
				onSuccess: finish,
				onError: function ( message ) { state.busy = false; state.error = t( 'err_card' ) + ( message ? ' (' + String( message ).slice( 0, 140 ) + ')' : '' ); paintPay(); },
				onCancel: function () { state.busy = false; paintPay(); },
			} );
		} ).catch( function () { fail( { reason: 'payment_unavailable' } ); } );
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
			state.done = r;
			render();
		} ).catch( function () {
			if ( tries < 15 ) {
				return setTimeout( function () { finish( tries + 1 ); }, 2000 );
			}
			state.busy = false;
			state.done = { status: 'pending', confirmation_code: state.hold.confirmation_code };
			render();
		} );
	}
	function back() {
		var ref = state.hold.reference;
		state.hold = null;
		card = null;
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
				el( 'p', { text: ok ? t( 'done_text', state.done.email || state.contact.email ) : ( state.done.status === 'failed' ? t( 'failed_text' ) : t( 'pending_text' ) ) } ),
				state.done.confirmation_code ? el( 'p', { class: 'skye-co__code' }, [ t( 'code' ) + ' ', el( 'strong', { text: state.done.confirmation_code } ) ] ) : null,
				ok ? summary() : null,
			] ) ];
		} else if ( ! state.cal ) {
			kids = [ el( 'p', { class: 'skye-co__loading', text: state.error || t( 'loading' ) } ) ];
		} else if ( ! state.cal.dates.length ) {
			kids = [ el( 'p', { text: t( 'no_dates' ) } ) ];
		} else if ( state.hold ) {
			kids = [ summary(), step( 4, t( 'payment' ), [
				el( 'p', { class: 'skye-co__hint', text: t( 'held' ) } ),
				el( 'div', { id: 'skye-co-card', class: 'skye-co__card' } ),
				el( 'p', { id: 'skye-co-msg', class: 'skye-co__err', role: 'alert', hidden: ! state.error, text: state.error } ),
				el( 'button', { id: 'skye-co-pay', type: 'button', class: 'skye-btn skye-btn--primary skye-co__go', onclick: pay, text: t( 'pay', money( state.hold.amount ) ) } ),
				el( 'button', { type: 'button', class: 'skye-co__more', onclick: back, text: t( 'change' ) } ),
				el( 'p', { class: 'skye-co__small', text: t( 'secure' ) + ( cfg.mode === 'sandbox' ? ' ' + t( 'sandbox' ) : '' ) } ),
			] ) ];
		} else {
			kids = [ dates() ];
			if ( state.date ) {
				kids.push( people() );
			}
			if ( state.date && count() ) {
				kids.push( details(), summary(),
					el( 'label', { class: 'skye-co__check' }, [ el( 'input', { type: 'checkbox', checked: state.agree, onchange: function ( e ) { state.agree = e.target.checked; } } ), el( 'span', { text: t( 'agree' ) + ' *' } ) ] ),
					state.error ? el( 'p', { class: 'skye-co__err', role: 'alert', text: state.error } ) : null,
					el( 'button', { type: 'button', class: 'skye-btn skye-btn--primary skye-co__go', disabled: state.busy, onclick: reserve, text: state.busy ? t( 'reserving' ) : t( 'continue', money( total() ) ) } ),
					el( 'p', { class: 'skye-co__small', text: t( 'no_charge_yet' ) } ) );
			}
		}
		root.replaceChildren.apply( root, kids.filter( Boolean ) );
	}

	render();
	api( '/calendar' ).then( function ( cal ) { state.cal = cal; render(); } ).catch( function ( e ) { state.error = t( 'err_unavailable' ); render(); void e; } );
}() );
