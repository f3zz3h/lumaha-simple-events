/* Lumaha Simple Event Tickets: the event screen (on-sale options; ticket rows: add, remove, drag to reorder). */
( function () {
	'use strict';
	// Event-level "Tickets on sale": show the date or days input for the chosen option.
	document.querySelectorAll( '.lset-sale' ).forEach( function ( box ) {
		var s = box.querySelector( '.lset-sale-mode' );
		if ( s ) {
			s.addEventListener( 'change', function () {
				box.setAttribute( 'data-mode', s.value );
			} );
		}
	} );

	var table = document.querySelector( '.lset-tickets tbody' );
	var tpl = document.getElementById( 'lset-ticket-template' );
	if ( ! table || ! tpl ) {
		return;
	}
	var count = 0;

	function mode( row ) {
		var s = row.querySelector( '.lset-sale-mode' );
		row.setAttribute( 'data-mode', s ? s.value : '' );
	}
	table.querySelectorAll( '.lset-ticket' ).forEach( function ( row ) {
		mode( row );
		row.draggable = false;
	} );

	document.querySelector( '.lset-add-ticket' ).addEventListener( 'click', function () {
		var html = tpl.innerHTML.replace( /__i__/g, 'n' + ( ++count ) );
		var holder = document.createElement( 'tbody' );
		holder.innerHTML = html;
		var row = holder.firstElementChild;
		table.appendChild( row );
		mode( row );
		row.querySelector( 'input[type=text]' ).focus();
	} );

	table.addEventListener( 'change', function ( ev ) {
		if ( ev.target.classList.contains( 'lset-sale-mode' ) ) {
			mode( ev.target.closest( '.lset-ticket' ) );
		}
	} );

	table.addEventListener( 'click', function ( ev ) {
		if ( ! ev.target.classList.contains( 'lset-remove' ) ) {
			return;
		}
		var row = ev.target.closest( '.lset-ticket' );
		var flag = row.querySelector( '.lset-delete-flag' );
		var on = flag.value !== '1';
		flag.value = on ? '1' : '0';
		row.classList.toggle( 'is-deleted', on );
		ev.target.textContent = on ? '↺' : '✕';
	} );

	// Drag by the handle only, so text in the inputs can still be selected.
	var dragging = null;
	table.addEventListener( 'mousedown', function ( ev ) {
		var row = ev.target.closest( '.lset-ticket' );
		if ( row ) {
			row.draggable = ev.target.classList.contains( 'lset-handle' );
		}
	} );
	table.addEventListener( 'dragstart', function ( ev ) {
		dragging = ev.target.closest( '.lset-ticket' );
		if ( dragging ) {
			dragging.classList.add( 'is-dragging' );
			ev.dataTransfer.effectAllowed = 'move';
		}
	} );
	table.addEventListener( 'dragover', function ( ev ) {
		var over = ev.target.closest( '.lset-ticket' );
		if ( ! dragging || ! over || over === dragging ) {
			return;
		}
		ev.preventDefault();
		var r = over.getBoundingClientRect();
		table.insertBefore( dragging, ev.clientY > r.top + r.height / 2 ? over.nextSibling : over );
	} );
	table.addEventListener( 'dragend', function () {
		if ( dragging ) {
			dragging.classList.remove( 'is-dragging' );
			dragging.draggable = false;
		}
		dragging = null;
	} );
}() );
