/**
 * WooCommerce Product Health Check — Admin JS
 */

/* global wphcData, jQuery */

( function ( $, data ) {
	'use strict';

	var $runBtn      = $( '#wphc-run-scan' );
	var $clearBtn    = $( '#wphc-clear-cache' );
	var $spinner     = $( '#wphc-spinner' );
	var $status      = $( '#wphc-status' );
	var $summaryWrap = $( '#wphc-summary-wrap' );
	var $tableWrap   = $( '#wphc-table-wrap' );
	var $filterSel   = $( '#wphc-filter-type' );
	var $toggleAll   = $( '#wphc-toggle-all' );
	var $skuExport   = $( '#wphc-sku-export' );
	var $skuTextarea = $( '#wphc-sku-textarea' );
	var $skuCount    = $( '#wphc-sku-count' );
	var $copyBtn     = $( '#wphc-copy-skus' );
	var $copyFeedback = $( '#wphc-copy-feedback' );

	function setScanning( message ) {
		$spinner.show();
		$status.text( message );
		$runBtn.prop( 'disabled', true );
		$clearBtn.prop( 'disabled', true );
	}

	function setDone( message ) {
		$spinner.hide();
		$status.text( message );
		$runBtn.prop( 'disabled', false );
		$clearBtn.prop( 'disabled', false );
	}

	/**
	 * Collect selected check type values from checkboxes.
	 *
	 * @return {string[]}
	 */
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
			setDone( data.i18n.noChecksSelected );
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
				$summaryWrap.html( response.data.summary );
				$tableWrap.html( response.data.table );

				// Update SKU export section.
				if ( response.data.skuCount > 0 ) {
					$skuTextarea.val( response.data.skus );
					$skuCount.text( response.data.skuCount + ' ' + data.i18n.skus );
					$skuExport.show();
				} else {
					$skuExport.hide();
				}

				applyFilter( $filterSel.val() );

				var msg = response.data.total > 0
					? data.i18n.scanComplete
					: data.i18n.noIssues;

				setDone( msg );
			} else {
				var errMsg = ( response.data && response.data.message )
					? response.data.message
					: data.i18n.scanFailed;
				setDone( errMsg );
			}
		} )
		.fail( function () {
			setDone( data.i18n.scanFailed );
		} );
	}

	function applyFilter( type ) {
		var $tbody = $( '#wphc-issues-tbody' );
		if ( ! $tbody.length ) {
			return;
		}

		$tbody.find( 'tr' ).each( function () {
			var $row = $( this );
			if ( 'all' === type || $row.data( 'issue-type' ) === type ) {
				$row.removeClass( 'wphc-hidden' );
			} else {
				$row.addClass( 'wphc-hidden' );
			}
		} );
	}

	/* Toggle all checkboxes */
	$toggleAll.on( 'click', function () {
		var $checks   = $( '.wphc-check' );
		var allChecked = $checks.filter( ':checked' ).length === $checks.length;

		$checks.prop( 'checked', ! allChecked );
		$( this ).text( allChecked ? data.i18n.selectAll : data.i18n.deselectAll );
	} );

	/* Keep toggle-all label in sync when individual boxes change */
	$( document ).on( 'change', '.wphc-check', function () {
		var $checks    = $( '.wphc-check' );
		var allChecked = $checks.filter( ':checked' ).length === $checks.length;
		$toggleAll.text( allChecked ? data.i18n.deselectAll : data.i18n.selectAll );
	} );

	/* Scan buttons */
	$runBtn.on( 'click', function () {
		runScan( false );
	} );

	$clearBtn.on( 'click', function () {
		runScan( true );
	} );

	/* Issue type filter */
	$filterSel.on( 'change', function () {
		applyFilter( $( this ).val() );
	} );

	/* Copy SKUs to clipboard */
	$copyBtn.on( 'click', function () {
		var text = $skuTextarea.val();
		if ( ! text ) {
			return;
		}

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( function () {
				showCopyFeedback();
			} );
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

	applyFilter( $filterSel.val() );

} )( jQuery, wphcData );
