/* Lumaha Simple Event Tickets: photo gallery viewer.
 * Each thumbnail links to the full photo, so it works without JavaScript; this adds a
 * full-screen viewer with previous/next, arrow keys, swipe and Escape. */
( function () {
	'use strict';
	var T = window.lsetGalleryText || {};

	function fmt( s, a, b ) {
		return String( s || '%1$d / %2$d' ).replace( '%1$d', a ).replace( '%2$d', b );
	}

	function button( cls, label, text ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'lset-viewer__' + cls;
		b.setAttribute( 'aria-label', label || '' );
		b.textContent = text;
		return b;
	}

	document.querySelectorAll( '.lset-gallery' ).forEach( function ( gallery ) {
		var links = Array.prototype.slice.call( gallery.querySelectorAll( '.lset-gallery__link' ) );
		if ( ! links.length || typeof HTMLDialogElement === 'undefined' ) {
			return;
		}
		var dialog = document.createElement( 'dialog' );
		dialog.className = 'lset-viewer';
		var img = document.createElement( 'img' );
		img.className = 'lset-viewer__img';
		var caption = document.createElement( 'p' );
		caption.className = 'lset-viewer__caption';
		var count = document.createElement( 'p' );
		count.className = 'lset-viewer__count';
		count.setAttribute( 'aria-live', 'polite' );
		var close = button( 'close', T.close, '\u2715' );
		var prev = button( 'prev', T.prev, '\u2039' );
		var next = button( 'next', T.next, '\u203A' );
		var figure = document.createElement( 'figure' );
		figure.className = 'lset-viewer__figure';
		figure.appendChild( img );
		figure.appendChild( caption );
		[ close, prev, figure, next, count ].forEach( function ( n ) {
			dialog.appendChild( n );
		} );
		document.body.appendChild( dialog );

		var at = 0;
		function show( i ) {
			at = ( i + links.length ) % links.length;
			var a = links[ at ];
			var thumb = a.querySelector( 'img' );
			img.src = a.href;
			img.alt = thumb ? thumb.alt : '';
			caption.textContent = a.getAttribute( 'data-caption' ) || '';
			caption.hidden = ! caption.textContent;
			count.textContent = fmt( T.count, at + 1, links.length );
			prev.hidden = next.hidden = links.length < 2;
		}

		links.forEach( function ( a, i ) {
			a.addEventListener( 'click', function ( ev ) {
				ev.preventDefault();
				show( i );
				dialog.showModal();
			} );
		} );
		close.addEventListener( 'click', function () {
			dialog.close();
		} );
		prev.addEventListener( 'click', function () {
			show( at - 1 );
		} );
		next.addEventListener( 'click', function () {
			show( at + 1 );
		} );
		// Click on the dark background closes.
		dialog.addEventListener( 'click', function ( ev ) {
			if ( ev.target === dialog ) {
				dialog.close();
			}
		} );
		dialog.addEventListener( 'keydown', function ( ev ) {
			if ( 'ArrowLeft' === ev.key ) {
				show( at - 1 );
			} else if ( 'ArrowRight' === ev.key ) {
				show( at + 1 );
			}
		} );
		var startX = null;
		dialog.addEventListener( 'touchstart', function ( ev ) {
			startX = ev.touches[ 0 ].clientX;
		}, { passive: true } );
		dialog.addEventListener( 'touchend', function ( ev ) {
			if ( null === startX ) {
				return;
			}
			var dx = ev.changedTouches[ 0 ].clientX - startX;
			startX = null;
			if ( Math.abs( dx ) > 40 ) {
				show( at + ( dx < 0 ? 1 : -1 ) );
			}
		}, { passive: true } );
		dialog.addEventListener( 'close', function () {
			links[ at ].focus();
		} );
	} );
}() );
