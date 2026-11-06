/* Lumaha Simple Event Tickets: the event's "Photo gallery" box (pick from the media library, drag to reorder, remove). */
( function ( $ ) {
	'use strict';
	var box = document.getElementById( 'lset-gallery' );
	if ( ! box || ! window.wp || ! wp.media ) {
		return;
	}
	var T = window.lsetGalleryAdmin || {};
	var input = box.querySelector( '.lset-gallery-ids' );
	var list = box.querySelector( '.lset-gallery-admin' );
	var clear = box.querySelector( '.lset-gallery-clear' );
	var frame;

	function sync() {
		var ids = [];
		list.querySelectorAll( 'li' ).forEach( function ( li ) {
			ids.push( li.getAttribute( 'data-id' ) );
		} );
		input.value = ids.join( ',' );
		clear.hidden = ! ids.length;
	}

	function add( att ) {
		if ( list.querySelector( 'li[data-id="' + att.id + '"]' ) ) {
			return;
		}
		var size = att.sizes && ( att.sizes.thumbnail || att.sizes.medium ) || att;
		var li = document.createElement( 'li' );
		li.setAttribute( 'data-id', att.id );
		var img = document.createElement( 'img' );
		img.src = size.url;
		img.alt = att.alt || '';
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'lset-gallery-remove';
		btn.setAttribute( 'aria-label', T.remove || 'Remove' );
		btn.textContent = '\u2715';
		li.appendChild( img );
		li.appendChild( btn );
		list.appendChild( li );
	}

	box.querySelector( '.lset-gallery-add' ).addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = wp.media( {
				title: T.title,
				button: { text: T.button },
				library: { type: 'image' },
				multiple: 'add'
			} );
			frame.on( 'select', function () {
				frame.state().get( 'selection' ).each( function ( m ) {
					add( m.toJSON() );
				} );
				sync();
			} );
		}
		frame.open();
	} );

	list.addEventListener( 'click', function ( ev ) {
		if ( ev.target.classList.contains( 'lset-gallery-remove' ) ) {
			ev.target.closest( 'li' ).remove();
			sync();
		}
	} );

	clear.addEventListener( 'click', function () {
		list.innerHTML = '';
		sync();
	} );

	$( list ).sortable( { items: 'li', tolerance: 'pointer', update: sync } );
}( window.jQuery ) );
