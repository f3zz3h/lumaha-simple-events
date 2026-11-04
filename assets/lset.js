/* Lumaha Simple Event Tickets: booking box.
 * Fetches live availability (the page itself may be cached), then draws one set of
 * attendee fields per ticket chosen. The server checks everything again. */
( function () {
	'use strict';

	var T = window.lsetText || {};

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 ), i = 0;
		return String( s ).replace( /%(\d+\$)?[ds]/g, function ( m, pos ) {
			var v = pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
			return v === undefined ? '' : v;
		} );
	}

	function el( tag, attrs, text ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( attrs[ k ] === true ) {
				n.setAttribute( k, '' );
			} else if ( attrs[ k ] !== false && attrs[ k ] !== null && attrs[ k ] !== undefined ) {
				n.setAttribute( k, attrs[ k ] );
			}
		} );
		if ( text !== undefined ) {
			n.textContent = text;
		}
		return n;
	}

	function fieldInput( f, name, id, value ) {
		var wrap = el( 'p', { 'class': 'lset-field lset-field--' + f.type } );
		var label = el( 'label', { 'for': id }, f.label + ( f.required ? ' *' : '' ) );
		var input;
		if ( f.type === 'checkbox' ) {
			input = el( 'input', { type: 'checkbox', name: name, id: id, value: '1', required: f.required } );
			input.checked = !! value;
			label.textContent = '';
			label.appendChild( input );
			label.appendChild( document.createTextNode( ' ' + f.label + ( f.required ? ' *' : '' ) ) );
			wrap.appendChild( label );
			return wrap;
		}
		if ( f.type === 'textarea' ) {
			input = el( 'textarea', { name: name, id: id, rows: '2', placeholder: f.placeholder || null, required: f.required } );
			input.value = value || '';
		} else if ( f.type === 'select' ) {
			input = el( 'select', { name: name, id: id, required: f.required } );
			input.appendChild( el( 'option', { value: '' }, T.choose || 'Choose…' ) );
			( f.options || [] ).forEach( function ( o ) {
				var opt = el( 'option', { value: o }, o );
				if ( o === value ) {
					opt.selected = true;
				}
				input.appendChild( opt );
			} );
		} else {
			input = el( 'input', {
				type: f.type === 'number' ? 'number' : 'text',
				name: name,
				id: id,
				placeholder: f.placeholder || null,
				inputmode: f.type === 'number' ? 'numeric' : null,
				step: f.type === 'number' ? 'any' : null,
				required: f.required
			} );
			input.value = value || '';
		}
		wrap.appendChild( label );
		wrap.appendChild( input );
		return wrap;
	}

	function setup( box ) {
		var data;
		try {
			data = JSON.parse( box.getAttribute( 'data-lset' ) );
		} catch ( e ) {
			return;
		}
		var form = box.querySelector( '.lset-form' );
		var submit = box.querySelector( '.lset-add' );
		var status = box.querySelector( '.lset-status' );
		var old = data.old || {};

		// Values typed so far, so redrawing doesn't lose them.
		function current( ticket ) {
			var vals = {};
			ticket.querySelectorAll( '[name^="lset_att["]' ).forEach( function ( input ) {
				vals[ input.name ] = input.type === 'checkbox' ? ( input.checked ? '1' : '' ) : input.value;
			} );
			return vals;
		}

		function oldValue( tid, n, key, fid ) {
			var a = old.att && old.att[ tid ] && old.att[ tid ][ n ];
			if ( ! a ) {
				return '';
			}
			return fid ? ( a.fields && a.fields[ fid ] ) || '' : a[ key ] || '';
		}

		function draw( ticket ) {
			var tid = ticket.getAttribute( 'data-ticket' );
			var tname = ticket.getAttribute( 'data-name' );
			var qty = parseInt( ticket.querySelector( '.lset-qty' ).value, 10 ) || 0;
			var holder = ticket.querySelector( '.lset-attendees' );
			var keep = current( ticket );
			holder.innerHTML = '';
			for ( var n = 1; n <= qty; n++ ) {
				var set = el( 'fieldset', { 'class': 'lset-attendee' } );
				set.appendChild( el( 'legend', {}, fmt( T.attendee || 'Ticket %1$d: %2$s', n, tname ) ) );
				var base = 'lset_att[' + tid + '][' + n + ']';
				var idb = 'lset-a-' + tid + '-' + n;
				var pick = function ( name, fallback ) {
					return Object.prototype.hasOwnProperty.call( keep, name ) ? keep[ name ] : fallback;
				};
				if ( data.names !== 'off' ) {
					var req = data.names === 'required';
					var row = el( 'div', { 'class': 'lset-names' } );
					row.appendChild( fieldInput( { type: 'text', label: T.firstName || 'First name', required: req }, base + '[first_name]', idb + '-first', pick( base + '[first_name]', oldValue( tid, n, 'first_name' ) ) ) );
					row.appendChild( fieldInput( { type: 'text', label: T.lastName || 'Last name', required: req }, base + '[last_name]', idb + '-last', pick( base + '[last_name]', oldValue( tid, n, 'last_name' ) ) ) );
					set.appendChild( row );
				}
				( data.fields || [] ).forEach( function ( f ) {
					var name = base + '[f][' + f.id + ']';
					set.appendChild( fieldInput( f, name, idb + '-f' + f.id, pick( name, oldValue( tid, n, null, f.id ) ) ) );
				} );
				holder.appendChild( set );
			}
			refreshSubmit();
		}

		function refreshSubmit() {
			var any = false;
			box.querySelectorAll( '.lset-qty' ).forEach( function ( s ) {
				if ( ! s.disabled && parseInt( s.value, 10 ) > 0 ) {
					any = true;
				}
			} );
			submit.disabled = ! any;
		}

		function apply( av ) {
			box.querySelectorAll( '.lset-ticket' ).forEach( function ( ticket ) {
				var tid = ticket.getAttribute( 'data-ticket' );
				var select = ticket.querySelector( '.lset-qty' );
				var note = ticket.querySelector( '.lset-ticket__left' );
				var a = av ? av.tickets[ tid ] : null;
				note.textContent = '';
				ticket.classList.remove( 'is-off' );
				if ( av && ( ! a || a.state ) ) {
					select.value = '0';
					select.disabled = true;
					ticket.classList.add( 'is-off' );
					if ( a && a.state === 'sold_out' ) {
						note.textContent = T.soldOut || 'Sold out';
					} else if ( a && a.state === 'ended' ) {
						note.textContent = T.ended || 'Sales have ended';
					} else if ( a && a.message ) {
						note.textContent = a.message;
					}
					draw( ticket );
					return;
				}
				select.disabled = false;
				if ( a ) {
					Array.prototype.slice.call( select.options ).forEach( function ( o ) {
						var v = parseInt( o.value, 10 );
						if ( v > a.max ) {
							o.remove();
						}
					} );
					if ( a.left !== null && a.left !== undefined ) {
						note.textContent = fmt( T.left || '%d left', a.left );
					}
				}
				draw( ticket );
			} );
			if ( av && ! av.open ) {
				status.textContent = av.message || '';
				form.querySelector( '.lset-submit' ).hidden = true;
				var bf = form.querySelector( '.lset-booking-fields' );
				if ( bf ) {
					bf.hidden = true;
				}
			}
			refreshSubmit();
		}

		box.addEventListener( 'change', function ( ev ) {
			if ( ev.target.classList.contains( 'lset-qty' ) ) {
				draw( ev.target.closest( '.lset-ticket' ) );
			}
		} );

		form.addEventListener( 'submit', function ( ev ) {
			if ( submit.disabled ) {
				ev.preventDefault();
				status.textContent = T.pickOne || '';
				return;
			}
			if ( typeof form.reportValidity === 'function' && ! form.reportValidity() ) {
				ev.preventDefault();
				return;
			}
			submit.disabled = true; // No double adds.
		} );

		fetch( data.rest, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( r.status );
				}
				return r.json();
			} )
			.then( apply )
			.catch( function () {
				// The server still checks everything, so let people try.
				status.textContent = T.offline || '';
				apply( null );
			} );
	}

	function start() {
		document.querySelectorAll( '.lset-box[data-lset]' ).forEach( setup );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
