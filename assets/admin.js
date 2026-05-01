/**
 * WooCommerce Product Health Check — Admin JS
 */

/* global wphcData, jQuery */

( function ( $, data ) {
	'use strict';

	var $runBtn       = $( '#wphc-run-scan' );
	var $clearBtn     = $( '#wphc-clear-cache' );
	var $csvBtn       = $( '#wphc-export-csv' );
	var $spinner      = $( '#wphc-spinner' );
	var $status       = $( '#wphc-status' );
	var $summaryWrap  = $( '#wphc-summary-wrap' );
	var $tableWrap    = $( '#wphc-table-wrap' );
	var $filterSel    = $( '#wphc-filter-type' );
	var $toggleAll    = $( '#wphc-toggle-all' );
	var $skuExport    = $( '#wphc-sku-export' );
	var $skuTextarea  = $( '#wphc-sku-textarea' );
	var $skuCount     = $( '#wphc-sku-count' );
	var $copyBtn      = $( '#wphc-copy-skus' );
	var $copyFeedback = $( '#wphc-copy-feedback' );

	var pageSize    = parseInt( data.pageSize, 10 ) || 50;
	var currentPage = 1;

	/* -------------------------------------------------------------------------
	   Scan helpers
	------------------------------------------------------------------------- */

	function setScanning( message ) {
		$spinner.show();
		$status.text( message );
		$runBtn.prop( 'disabled', true );
		$clearBtn.prop( 'disabled', true );
		$csvBtn.prop( 'disabled', true );
	}

	function setDone( message, hasResults ) {
		$spinner.hide();
		$status.text( message );
		$runBtn.prop( 'disabled', false );
		$clearBtn.prop( 'disabled', false );
		$csvBtn.prop( 'disabled', ! hasResults );
	}

	function getSelectedChecks() {
		var checks = [];
		$( '.wphc-check:checked' ).each( function () {
			checks.push( $( this ).val() );
		} );
		return checks;
	}

	function runScan( force ) {
		var checks = getSelectedChecks();
		if ( ! checks.length ) {
			setDone( data.i18n.noChecksSelected, false );
			return;
		}

		setScanning( data.i18n.scanning );

		$.ajax( {
			url:    data.ajaxUrl,
			method: 'POST',
			data:   {
				action: data.action,
				nonce:  data.nonce,
				force:  force ? '1' : '0',
				checks: checks,
			},
		} )
		.done( function ( response ) {
			if ( response.success ) {
				// Refresh nonce so subsequent scans work even if the previous
				// one invalidated it (e.g. single-use nonce security plugins).
				if ( response.data.nonce ) {
					data.nonce = response.data.nonce;
				}

				$summaryWrap.html( response.data.summary );
				$tableWrap.html( response.data.table );

				currentPage = 1;
				applyFilter( $filterSel.val() );

				if ( response.data.skuCount > 0 ) {
					$skuTextarea.val( response.data.skus );
					$skuCount.text( response.data.skuCount + ' ' + data.i18n.skus );
					$skuExport.show();
				} else {
					$skuExport.hide();
				}

				var hasIssues = response.data.total > 0;
				setDone( hasIssues ? data.i18n.scanComplete : data.i18n.noIssues, hasIssues );
			} else {
				var errMsg = data.i18n.scanFailed;
				if ( response.data && response.data.message ) {
					errMsg = response.data.message;
					if ( response.data.file ) {
						errMsg += ' (' + response.data.file + ':' + response.data.line + ')';
					}
				}
				setDone( errMsg, false );
			}
		} )
		.fail( function () {
			setDone( data.i18n.scanFailed, false );
		} );
	}

	/* -------------------------------------------------------------------------
	   Filter
	------------------------------------------------------------------------- */

	function getVisibleRows() {
		return $( '#wphc-issues-tbody tr' ).not( '.wphc-filtered' );
	}

	function applyFilter( type ) {
		var $tbody = $( '#wphc-issues-tbody' );
		if ( ! $tbody.length ) {
			return;
		}

		$tbody.find( 'tr' ).each( function () {
			var $row = $( this );
			if ( 'all' === type || $row.data( 'issue-type' ) === type ) {
				$row.removeClass( 'wphc-filtered' );
			} else {
				$row.addClass( 'wphc-filtered' );
			}
		} );

		currentPage = 1;
		applyPagination();
	}

	/* -------------------------------------------------------------------------
	   Pagination
	------------------------------------------------------------------------- */

	function applyPagination() {
		var $rows      = getVisibleRows();
		var total      = $rows.length;
		var totalPages = Math.ceil( total / pageSize );
		var $pager     = $( '#wphc-pagination' );

		// Hide all visible rows, then show the current page slice.
		$rows.addClass( 'wphc-hidden' );
		$rows.slice( ( currentPage - 1 ) * pageSize, currentPage * pageSize ).removeClass( 'wphc-hidden' );

		if ( totalPages <= 1 ) {
			$pager.hide();
			return;
		}

		// Build pagination controls.
		var label = data.i18n.pageOf
			.replace( '%1$d', currentPage )
			.replace( '%2$d', totalPages );

		var prevDisabled = currentPage <= 1 ? ' disabled' : '';
		var nextDisabled = currentPage >= totalPages ? ' disabled' : '';

		$pager.html(
			'<button class="button wphc-page-prev"' + prevDisabled + '>&laquo; Prev</button>' +
			'<span class="wphc-page-label">' + label + '</span>' +
			'<button class="button wphc-page-next"' + nextDisabled + '>Next &raquo;</button>'
		).show();
	}

	$( document ).on( 'click', '.wphc-page-prev', function () {
		if ( currentPage > 1 ) {
			currentPage--;
			applyPagination();
		}
	} );

	$( document ).on( 'click', '.wphc-page-next', function () {
		var total = getVisibleRows().length;
		if ( currentPage < Math.ceil( total / pageSize ) ) {
			currentPage++;
			applyPagination();
		}
	} );

	/* -------------------------------------------------------------------------
	   CSV export
	------------------------------------------------------------------------- */

	$csvBtn.on( 'click', function () {
		// Check all boxes so the label state is consistent after export.
		$( '.wphc-check' ).prop( 'checked', true );
		$toggleAll.text( data.i18n.deselectAll );

		var url = data.ajaxUrl +
			'?action=' + encodeURIComponent( data.csvAction ) +
			'&nonce='  + encodeURIComponent( data.csvNonce );
		window.location.href = url;
	} );

	/* -------------------------------------------------------------------------
	   Toggle all checkboxes
	------------------------------------------------------------------------- */

	$toggleAll.on( 'click', function () {
		var $checks    = $( '.wphc-check' );
		var allChecked = $checks.filter( ':checked' ).length === $checks.length;
		$checks.prop( 'checked', ! allChecked );
		$( this ).text( allChecked ? data.i18n.selectAll : data.i18n.deselectAll );
	} );

	$( document ).on( 'change', '.wphc-check', function () {
		var $checks    = $( '.wphc-check' );
		var allChecked = $checks.filter( ':checked' ).length === $checks.length;
		$toggleAll.text( allChecked ? data.i18n.deselectAll : data.i18n.selectAll );
	} );

	/* -------------------------------------------------------------------------
	   Scan / clear buttons
	------------------------------------------------------------------------- */

	$runBtn.on( 'click', function () { runScan( false ); } );
	$clearBtn.on( 'click', function () { runScan( true ); } );

	$filterSel.on( 'change', function () {
		applyFilter( $( this ).val() );
	} );

	/* -------------------------------------------------------------------------
	   Copy SKUs
	------------------------------------------------------------------------- */

	$copyBtn.on( 'click', function () {
		var text = $skuTextarea.val();
		if ( ! text ) {
			return;
		}
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( showCopyFeedback );
		} else {
			$skuTextarea[0].select();
			document.execCommand( 'copy' );
			showCopyFeedback();
		}
	} );

	function showCopyFeedback() {
		$copyFeedback.show();
		setTimeout( function () {
			$copyFeedback.fadeOut( 400 );
		}, 1800 );
	}

	/* -------------------------------------------------------------------------
	   Init
	------------------------------------------------------------------------- */

	// Force all checkboxes checked on load — prevents browser from restoring a
	// previous unchecked state via form autocomplete.
	$( '.wphc-check' ).prop( 'checked', true );

	applyFilter( $filterSel.val() );

} )( jQuery, wphcData );
