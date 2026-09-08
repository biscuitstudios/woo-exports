( function ( $ ) {
	'use strict';

	if ( 'undefined' === typeof window.wooexAdmin ) {
		return;
	}

	var W = window.wooexAdmin;

	// Filter sections each report type should show.
	var FILTERS_PER_TYPE = {
		products:  [ 'cats', 'tags' ],
		orders:    [ 'dates', 'statuses', 'customers', 'products', 'cats', 'tags', 'parents' ],
		customers: [ 'dates', 'statuses', 'customers' ],
		attendees: [ 'dates', 'statuses', 'customers', 'products', 'cats', 'tags', 'parents' ],
	};

	// =====================================================================
	// selectWoo init
	// =====================================================================

	function initLocal( $el ) {
		var fn = $.fn.selectWoo || $.fn.select2;
		if ( ! $el.length || ! fn ) return;
		if ( $el.data( 'select2' ) ) return;
		fn.call( $el, {
			multiple:    true,
			placeholder: $el.data( 'placeholder' ) || 'Select…',
			allowClear:  true,
			width:       '100%',
		} );
	}

	function initAjax( $el, action, security ) {
		var fn = $.fn.selectWoo || $.fn.select2;
		if ( ! $el.length || ! fn ) return;
		if ( $el.data( 'select2' ) ) return;
		fn.call( $el, {
			multiple: true,
			placeholder: $el.data( 'placeholder' ) || 'Type at least 3 characters…',
			minimumInputLength: 3,
			allowClear: true,
			width: '100%',
			ajax: {
				url: W.ajax_url,
				dataType: 'json',
				delay: 250,
				cache: true,
				data: function ( params ) {
					return { action: action, security: security, term: params.term };
				},
				processResults: function ( data ) {
					if ( data && Array.isArray( data.results ) ) return { results: data.results };
					var out = [];
					if ( data && 'object' === typeof data ) {
						Object.keys( data ).forEach( function ( id ) {
							out.push( { id: id, text: data[ id ] } );
						} );
					}
					return { results: out };
				},
			},
		} );
	}

	// =====================================================================
	// Flash notices
	// =====================================================================

	function flash( type, message ) {
		var $area = $( '.wooex-notice-area' );
		var cls   = 'success' === type ? 'notice-success' : 'notice-error';
		var $n    = $( '<div class="notice ' + cls + ' is-dismissible"><p></p></div>' );
		$n.find( 'p' ).text( message );
		$area.append( $n );
		if ( 'success' === type ) {
			autoDismiss( $n );
		}
	}

	function autoDismiss( $n ) {
		setTimeout( function () {
			$n.fadeOut( 400, function () { $( this ).remove(); } );
		}, 5000 );
	}

	// Module scope: the builder, the email dialog and the review pane all put
	// server-supplied text through .html(), and one copy means one place where
	// the escaping can be wrong.
	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[ c ];
		} );
	}

	// =====================================================================
	// EMAIL EXPORT DIALOG — shared by the list page and the builder
	// =====================================================================

	// The two surfaces mean different things by "this export", so the dialog
	// holds no opinion about where the data comes from. Each opener hands it a
	// `source` and a `payload()` that builds the rest of the request. That is
	// also what keeps the builder's send on the same window as the preview
	// above it, without the dialog needing to know a window exists.
	var emailDialog = null;

	function initEmailDialog() {
		var $dlg = $( '#wooex-email-dialog' );
		if ( ! $dlg.length ) {
			return null;
		}

		var dlg      = $dlg[0];
		var $title   = $dlg.find( '.wooex-dialog-title' );
		var $summary = $dlg.find( '.wooex-dialog-summary' );
		var $recips  = $dlg.find( '.wooex-dialog-recipients' );
		var $err     = $dlg.find( '.wooex-dialog-error' );
		var $send    = $dlg.find( '.wooex-dialog-send' );

		var current    = null;
		var openToken  = 0;   // guards a late prefill landing in a later dialog
		var prefilled  = '';  // what open() put in the field, to detect typing

		function showError( msg ) {
			$err.html( '<p>' + escapeHtml( msg ) + '</p>' ).show();
		}

		function close() {
			current = null;
			if ( dlg.close ) {
				dlg.close();
			} else {
				dlg.removeAttribute( 'open' );
			}
			$dlg.removeClass( 'is-fallback-open' );
		}

		function open( opts ) {
			current   = opts;
			openToken = openToken + 1;

			var initial = opts.recipients || W.current_user_email || '';
			prefilled   = initial;

			$title.text( opts.title || 'Email Export' );
			$summary.text( opts.summary || '' ).toggle( !! opts.summary );
			$recips.val( initial );
			$err.hide().empty();
			$send.prop( 'disabled', false ).text( 'Send Export' );

			// showModal gives the backdrop, focus trap and Esc handling. The
			// fallback is for a browser without it and only has to be usable.
			if ( dlg.showModal ) {
				dlg.showModal();
			} else {
				dlg.setAttribute( 'open', '' );
				$dlg.addClass( 'is-fallback-open' );
			}

			$recips.trigger( 'focus' );
			return openToken;
		}

		// Late-arriving saved recipients. Only replaces the field when nothing
		// has been typed into it, and only for the dialog that asked.
		function prefill( token, value ) {
			if ( token !== openToken || ! current ) { return; }
			if ( $recips.val() !== prefilled ) { return; }
			prefilled = value;
			$recips.val( value );
		}

		$dlg.on( 'click', '.wooex-dialog-cancel', close );

		// Native Esc fires `cancel`; clear state so a reopen starts clean.
		$dlg.on( 'cancel', function () {
			current = null;
		} );

		// Click on the backdrop. The dialog element's own box is the card, so a
		// click whose target IS the dialog landed outside it.
		$dlg.on( 'click', function ( e ) {
			if ( e.target === dlg ) { close(); }
		} );

		$dlg.on( 'click', '.wooex-dialog-send', function () {
			if ( ! current ) { return; }

			$err.hide().empty();

			var raw = ( $recips.val() || '' ).trim();
			if ( ! raw ) {
				showError( W.i18n.send_recipients_required );
				return;
			}

			var emails  = raw.split( /[\s,;]+/ ).filter( Boolean );
			var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			for ( var i = 0; i < emails.length; i++ ) {
				if ( ! emailRe.test( emails[ i ] ) ) {
					showError( W.i18n.invalid_email );
					return;
				}
			}

			var max = parseInt( W.max_recipients, 10 ) || 10;
			if ( emails.length > max ) {
				showError( String( W.i18n.send_too_many ).replace( '%d', max ) );
				return;
			}

			var payload = $.extend(
				{
					action:      'wooex_send_export',
					_ajax_nonce: W.nonce,
					source:      current.source,
					recipients:  raw,
				},
				current.payload ? current.payload() : {}
			);

			$send.prop( 'disabled', true ).text( W.i18n.sending );

			$.post( W.ajax_url, payload )
				.done( function ( res ) {
					if ( res && res.success ) {
						// Close first: the toast area is a normal fixed element,
						// so it would render behind the modal's backdrop.
						close();
						flash( 'success', ( res.data && res.data.message ) || 'Export emailed.' );
						return;
					}
					showError( ( res && res.data && res.data.message ) || 'Send failed.' );
				} )
				.fail( function ( xhr ) {
					var msg = 'Network error.';
					var parsed = xhr && xhr.responseJSON ? xhr.responseJSON : null;
					if ( parsed && parsed.data && parsed.data.message ) {
						msg = parsed.data.message;
					} else if ( xhr && 0 === xhr.status ) {
						msg = 'Request timed out. Try a narrower date range.';
					} else if ( xhr && xhr.status >= 500 ) {
						msg = 'Server error — check the PHP error log for [WooExports] entries.';
					}
					showError( msg );
				} )
				.always( function () {
					$send.prop( 'disabled', false ).text( 'Send Export' );
				} );
		} );

		return { open: open, prefill: prefill, close: close };
	}

	// =====================================================================
	// LIST PAGE
	// =====================================================================

	function initListPage() {
		// "Select all" header checkbox toggles every row checkbox.
		$( document ).on( 'change', '#wooex-cb-select-all', function () {
			$( '.wooex-table input[type="checkbox"][name="report[]"]' ).prop( 'checked', this.checked );
		} );

		// Row meatball menu — toggle on button click, close on outside click / Esc.
		// The menu uses position:fixed so it can escape the table scroller's
		// overflow clipping; we anchor it to the button via getBoundingClientRect.
		$( document ).on( 'click', '.wooex-rowmenu-toggle', function ( e ) {
			e.stopPropagation();
			var $btn  = $( this );
			var $menu = $btn.next( '.wooex-rowmenu-list' );
			var open  = ! $menu.prop( 'hidden' );
			closeAllRowMenus();
			if ( ! open ) {
				positionRowMenu( $btn, $menu );
				$menu.prop( 'hidden', false );
				$btn.attr( 'aria-expanded', 'true' );
				$btn.parent().attr( 'aria-expanded', 'true' );
			}
		} );
		$( document ).on( 'click', '.wooex-rowmenu-list a', closeAllRowMenus );
		$( document ).on( 'click', closeAllRowMenus );
		$( document ).on( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) closeAllRowMenus();
		} );
		// Fixed-position menus don't track page/table scroll — close them so
		// they don't drift away from their button.
		$( window ).on( 'scroll resize', closeAllRowMenus );
		$( document ).on( 'scroll', '.wooex-table-scroller', closeAllRowMenus );

		function positionRowMenu( $btn, $menu ) {
			var rect = $btn[0].getBoundingClientRect();
			$menu.css( {
				top:   ( rect.bottom + 4 ) + 'px',
				right: ( window.innerWidth - rect.right ) + 'px',
				left:  'auto',
			} );
		}

		function closeAllRowMenus() {
			$( '.wooex-rowmenu-list' ).prop( 'hidden', true );
			$( '.wooex-rowmenu-toggle' ).attr( 'aria-expanded', 'false' );
			$( '.wooex-rowmenu' ).attr( 'aria-expanded', 'false' );
		}

		// Email Export (row action). Generates the saved report against the
		// clock right now — the same window Download and Run Now produce.
		$( document ).on( 'click', '.wooex-email-export-action', function ( e ) {
			e.preventDefault();
			if ( ! emailDialog ) { return; }

			var $a  = $( this );
			var id  = String( $a.data( 'report-id' ) || '' );
			var name = String( $a.data( 'report-name' ) || '' );

			var token = emailDialog.open( {
				source:  'saved',
				title:   'Email Export',
				summary: name
					? '\u201C' + name + '\u201D will be generated from live data now and emailed as an attachment.'
					: 'This export will be generated from live data now and emailed as an attachment.',
				payload: function () { return { id: id }; },
			} );

			// Prefill with the report's own recipients, fetched rather than
			// printed into every row's markup — the list would otherwise carry
			// every client's address in the page source.
			$.post( W.ajax_url, {
				action:      'wooex_get_report',
				_ajax_nonce: W.nonce,
				id:          id,
			} ).done( function ( res ) {
				if ( res && res.success && res.data && res.data.recipients_text ) {
					emailDialog.prefill( token, res.data.recipients_text );
				}
			} );
		} );

		// Run Now (row hover action) — synchronous run + flash, then reload.
		$( document ).on( 'click', '.wooex-run-now-action', function ( e ) {
			e.preventDefault();
			var $a = $( this );
			if ( $a.data( 'pending' ) ) return;
			$a.data( 'pending', true ).text( W.i18n.running );

			var id = $a.data( 'report-id' );
			$.post( W.ajax_url, { action: 'wooex_run_now', _ajax_nonce: W.nonce, id: id } )
				.done( function ( res ) {
					if ( res && res.success ) {
						var msg = 'Run complete (' + res.data.status + ')'
							+ ( res.data.message ? ': ' + res.data.message : '' );
						sessionStorage.setItem( 'wooex_flash', JSON.stringify( {
							type: 'success' === res.data.status ? 'success' : 'error',
							message: msg,
						} ) );
					} else {
						sessionStorage.setItem( 'wooex_flash', JSON.stringify( {
							type: 'error',
							message: ( res && res.data && res.data.message ) || 'Run failed.',
						} ) );
					}
					window.location.reload();
				} )
				.fail( function () {
					flash( 'error', 'Network error.' );
					$a.data( 'pending', false ).text( 'Run Now' );
				} );
		} );

		// Active toggle — AJAX then reload so the row's schedule/next-run cells reflect new state.
		$( document ).on( 'change', '.wooex-toggle-active', function () {
			var $cb    = $( this );
			var id     = $cb.data( 'report-id' );
			var active = $cb.is( ':checked' ) ? 1 : 0;

			$cb.prop( 'disabled', true );
			$.post( W.ajax_url, {
				action:      'wooex_toggle_active',
				_ajax_nonce: W.nonce,
				id:          id,
				active:      active,
			} )
				.done( function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
					} else {
						$cb.prop( 'checked', ! active ).prop( 'disabled', false );
					}
				} )
				.fail( function () {
					$cb.prop( 'checked', ! active ).prop( 'disabled', false );
				} );
		} );

		// Cross-reload flash messages (from Run Now).
		var pending = sessionStorage.getItem( 'wooex_flash' );
		if ( pending ) {
			sessionStorage.removeItem( 'wooex_flash' );
			try {
				var f = JSON.parse( pending );
				flash( f.type || 'success', f.message || '' );
			} catch ( e ) {}
		}
	}

	// =====================================================================
	// BUILDER PAGE
	// =====================================================================

	function initBuilderPage() {
		var $f    = $( '#wooex-report-form' );
		var $err  = $f.find( '.wooex-form-error' );

		initLocal( $( '#wooex-field-statuses' ) );
		initLocal( $( '#wooex-field-product-cat-ids' ) );
		initLocal( $( '#wooex-field-product-tag-ids' ) );
		initAjax( $( '#wooex-field-customer-ids' ), 'wooex_search_customers', W.nonce_war );
		initAjax( $( '#wooex-field-product-ids' ), 'wooex_search_products', W.nonce_war );
		initAjax( $( '#wooex-field-parent-post-ids' ), 'wooex_search_parent_posts', W.nonce_war );

		function updateFilterVisibility() {
			var type = $f.find( 'select[name="type"]' ).val();
			var allowed = FILTERS_PER_TYPE[ type ] || [];
			$f.find( '[data-filter]' ).each( function () {
				var key = $( this ).data( 'filter' );
				$( this ).toggle( allowed.indexOf( key ) !== -1 );
			} );
			// Hide the cats+tags row wrapper when both children are hidden.
			$f.find( '[data-filter-pair="cats-tags"]' ).each( function () {
				var anyVisible = allowed.indexOf( 'cats' ) !== -1 || allowed.indexOf( 'tags' ) !== -1;
				$( this ).toggle( anyVisible );
			} );
		}

		function updateCustomDates() {
			var v = $( '#wooex-field-date-range' ).val();
			$f.find( '.wooex-custom-dates' ).css( 'display', 'custom' === v ? 'inline-flex' : 'none' );
		}

		// Week, month and year ranges always run midnight to midnight, so the
		// boundary control is hidden for them rather than left sitting there
		// doing nothing. The value stays in the form while hidden, so switching
		// away and back does not lose what was set.
		function updateDayStart() {
			var v      = $( '#wooex-field-date-range' ).val();
			var ranges = W.day_start_ranges || [];
			var show   = $.inArray( v, ranges ) !== -1;
			$f.find( '.wooex-day-start-col, .wooex-day-start-help' ).toggle( show );
		}

		// The resolved-window note stays server-side, because working out what
		// "Yesterday at 19:00" means involves the site timezone, DST and
		// start_of_week. Reimplementing that here would give a second answer free
		// to drift from the one the export actually uses. So rather than
		// recomputing it in the browser, or marking it stale until the next save,
		// we ask PHP again on every change.
		var rangeNoteTimer = null;
		var rangeNoteReq   = null;

		function refreshRangeNote() {
			var $note = $f.find( '.wooex-range-note' );
			if ( ! $note.length ) { return; }

			$note.addClass( 'is-refreshing' );
			clearTimeout( rangeNoteTimer );

			// Debounced, and the in-flight request is aborted rather than left to
			// land. Without the abort a slow earlier response can arrive after a
			// fast later one and overwrite the newer answer.
			rangeNoteTimer = setTimeout( function () {
				if ( rangeNoteReq ) { rangeNoteReq.abort(); }

				rangeNoteReq = $.post( W.ajax_url, {
					action:      'wooex_resolve_range',
					_ajax_nonce: W.nonce,
					active:      isScheduled() ? 1 : 0,
					filters:     collectFilters(),
					schedule:    collectSchedule(),
				} )
					.done( function ( res ) {
						if ( ! res || ! res.success ) {
							$note.show().html( escapeHtml( W.i18n.range_note_error ) );
							return;
						}
						// All Time has no window to describe, so the note goes away
						// rather than sitting there empty.
						if ( ! res.data.range ) {
							$note.hide().empty();
							return;
						}
						$note.show().html(
							escapeHtml( res.data.intro ) + ': <strong>'
							+ escapeHtml( res.data.range ) + '</strong>'
						);
					} )
					.fail( function ( xhr, status ) {
						if ( 'abort' === status ) { return; }
						$note.show().html( escapeHtml( W.i18n.range_note_error ) );
					} )
					.always( function () {
						$note.removeClass( 'is-refreshing' );
					} );
			}, 250 );
		}

		function updateScheduleFields() {
			var freq = $( '#wooex-field-frequency' ).val();
			$f.find( '.wooex-field-weekly' ).toggle( 'weekly' === freq );
			$f.find( '.wooex-field-monthly' ).toggle( 'monthly' === freq );
		}

		function updateScheduleGate() {
			var active = $( '#wooex-field-active' ).is( ':checked' );
			var $gated = $f.find( '.wooex-schedule-gated' );
			$gated.toggleClass( 'wooex-disabled', ! active );
			$gated.find( 'input, select, textarea' ).prop( 'disabled', ! active );
		}

		updateFilterVisibility();
		updateCustomDates();
		updateDayStart();
		updateScheduleFields();
		updateScheduleGate();

		$f.find( 'select[name="type"]' ).on( 'change', updateFilterVisibility );
		$f.find( '#wooex-field-date-range' ).on( 'change', updateCustomDates );
		$f.find( '#wooex-field-date-range' ).on( 'change', updateDayStart );
		// The window moves with the range and the boundary. The moment it is
		// evaluated at moves with the schedule, because a scheduled report is
		// described at its next run. So both sets of fields refresh the note.
		$f.find( '#wooex-field-date-range, #wooex-field-day-start, input[name="date_from"], input[name="date_to"]' )
			.on( 'change', refreshRangeNote );
		$f.find( '#wooex-field-frequency, #wooex-field-time, #wooex-field-dom, #wooex-field-active' )
			.on( 'change', refreshRangeNote );
		$f.on( 'change', 'input[name="days[]"]', refreshRangeNote );
		$f.find( '#wooex-field-frequency' ).on( 'change', updateScheduleFields );
		$f.find( '#wooex-field-active' ).on( 'change', updateScheduleGate );

		// ============================================================
		// Dirty-form tracking — warn on navigate if unsaved changes
		// ============================================================
		var isDirty = false;
		var WARN_MSG = 'Changes you made may not be saved.';

		function markDirty() { isDirty = true; }
		function clearDirty() { isDirty = false; }

		// Bind after the current tick so selectWoo's init-time change events
		// (firing as it hydrates preselected options) don't dirty the form.
		setTimeout( function () {
			$f.on( 'change input', 'input, textarea, select', markDirty );
			$f.on( 'select2:select select2:unselect select2:clear', 'select', markDirty );
		}, 0 );

		$( window ).on( 'beforeunload.warDirty', function () {
			if ( isDirty ) return WARN_MSG;
		} );

		$( '.wooex-builder-header' ).on( 'click', '.wooex-back, .wooex-cancel-btn', function ( e ) {
			if ( isDirty && ! window.confirm( WARN_MSG + '\n\nLeave this page?' ) ) {
				e.preventDefault();
			}
		} );

		function showError( msg ) {
			// msg may come from server response (e.g. res.data.message) — escape
			// to prevent DOM XSS via PHP error text or other attacker-influenced
			// content reaching .html().
			$err.html( '<p>' + escapeHtml( msg ) + '</p>' ).show();
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		}

		function clientValidate() {
			$err.hide().empty();

			var name = $f.find( 'input[name="name"]' ).val().trim();
			if ( ! name ) { showError( W.i18n.name_required ); return false; }

			var active     = $( '#wooex-field-active' ).is( ':checked' );
			var recipients = $f.find( 'textarea[name="recipients"]' ).val().trim();

			if ( active ) {
				if ( ! recipients ) { showError( W.i18n.recipients_required ); return false; }
				var emails  = recipients.split( /[\s,;]+/ ).filter( Boolean );
				var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
				for ( var i = 0; i < emails.length; i++ ) {
					if ( ! emailRe.test( emails[ i ] ) ) { showError( W.i18n.invalid_email ); return false; }
				}
			}

			if ( 'custom' === $( '#wooex-field-date-range' ).val() ) {
				var df = $f.find( 'input[name="date_from"]' ).val();
				var dt = $f.find( 'input[name="date_to"]' ).val();
				if ( ! df || ! dt ) { showError( W.i18n.custom_range_required ); return false; }
				if ( dt < df ) { showError( W.i18n.custom_range_order ); return false; }
			}

			if ( active && 'weekly' === $( '#wooex-field-frequency' ).val() ) {
				if ( 0 === $f.find( 'input[name="days[]"]:checked' ).length ) {
					showError( W.i18n.days_required );
					return false;
				}
			}

			return true;
		}

		// ============================================================
		// Review Report — inline preview of the data layer query
		// ============================================================

		var $previewDl = $f.find( '.wooex-preview-download-btn' );

		// The last preview that is still valid for what is on the form. The
		// Email Export dialog repeats it, so you are not sending a count you
		// have not seen. Null means no preview, or one the form has since
		// invalidated — either way the dialog says the count is unknown rather
		// than quoting a stale one.
		var lastPreview = null;

		$f.on( 'click', '.wooex-review-btn', function () {
			var $btn = $( this );
			var $out = $f.find( '.wooex-review-results' );
			var orig = $btn.text();

			$btn.prop( 'disabled', true ).text( 'Running…' );
			$previewDl.hide();
			$out.show().html( '<p><em>Running query…</em></p>' );

			var payload = {
				action:      'wooex_review_report',
				_ajax_nonce: W.nonce,
				type:        $f.find( 'select[name="type"]' ).val(),
				filters:     collectFilters(),
				// The schedule is what sets the clock the date range resolves
				// against, so the preview lands on the same window as the note
				// above it, unsaved edits included.
				active:      isScheduled() ? 1 : 0,
				schedule:    collectSchedule(),
			};

			$.post( W.ajax_url, payload )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						lastPreview = null;
						var errMsg = res && res.data && res.data.message ? res.data.message : 'Preview failed.';
						$out.html( '<div class="notice notice-error inline"><p>' + escapeHtml( errMsg ) + '</p></div>' );
						return;
					}
					lastPreview = {
						count: res.data.count || 0,
						label: res.data.label || 'records',
					};
					renderReviewResults( $out, res.data );
					if ( ( res.data.count || 0 ) > 0 ) {
						$previewDl.show();
					}
				} )
				.fail( function ( xhr ) {
					lastPreview = null;
					var msg = 'Network error.';
					// Try to pull a useful message out of the response body —
					// the shutdown handler returns JSON for fatals, but if PHP
					// already streamed headers we'd get raw text instead.
					var body = xhr && xhr.responseText ? xhr.responseText : '';
					try {
						var parsed = body ? JSON.parse( body ) : null;
						if ( parsed && parsed.data && parsed.data.message ) {
							msg = parsed.data.message;
						}
					} catch ( e ) {
						if ( body && body.length < 500 ) {
							// Probably an HTML fatal — first 200 chars of plain text.
							msg = 'Server error: ' + body.replace( /<[^>]*>/g, '' ).slice( 0, 200 );
						} else if ( xhr && xhr.status >= 500 ) {
							msg = 'Server error — check the PHP error log for [WooExports] entries. The dataset for this range may be too large.';
						} else if ( xhr && 0 === xhr.status ) {
							msg = 'Request timed out. Try a narrower date range.';
						}
					}
					$out.html( '<div class="notice notice-error inline"><p>' + escapeHtml( msg ) + '</p></div>' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( orig );
				} );
		} );

		// Re-edit invalidates the previewed data — force a fresh preview before
		// the user can download.
		//
		// The schedule fields are in here as well as the filters, because for a
		// scheduled report the window is resolved at the next run: changing the
		// send time moves the window without touching a single filter. The
		// Download button could go stale that way too, and quietly did.
		$f.on(
			'change input',
			'select[name="type"], [data-filter] :input, [data-filter-pair] :input, '
				+ '#wooex-field-frequency, #wooex-field-time, #wooex-field-dom, '
				+ '#wooex-field-active, input[name="days[]"]',
			function () {
				$previewDl.hide();
				lastPreview = null;
			}
		);

		// Email Export (builder). Sends the export as configured on this page,
		// saved or not, resolved against the same moment the preview uses. So
		// the file that arrives holds the rows the preview showed.
		$f.on( 'click', '.wooex-email-export-btn', function () {
			if ( ! emailDialog ) { return; }

			$err.hide().empty();

			// The one filter mistake that changes what leaves the building: an
			// incomplete Custom range resolves to every order the site has ever
			// taken. Refused here and again server-side.
			if ( 'custom' === $( '#wooex-field-date-range' ).val() ) {
				var df = $f.find( 'input[name="date_from"]' ).val();
				var dt = $f.find( 'input[name="date_to"]' ).val();
				if ( ! df || ! dt ) { showError( W.i18n.custom_range_required ); return; }
				if ( dt < df ) { showError( W.i18n.custom_range_order ); return; }
			}

			// Repeat the resolved window in the dialog. It is the sentence the
			// page already computed in PHP, so there is no second answer here.
			var $note   = $f.find( '.wooex-range-note' );
			var summary = 'The export as configured on this page will be generated and emailed as an attachment.';
			if ( $note.length && $note.is( ':visible' ) ) {
				summary += ' ' + $note.text().replace( /\s+/g, ' ' ).trim();
			}

			// Say what the last preview counted, so the dialog is the
			// look-before-you-send step rather than the button being hidden
			// behind one. A zero count is the point of the feature, so it is
			// reported like any other number.
			if ( lastPreview ) {
				summary += ' The last preview of this window found '
					+ lastPreview.count + ' '
					+ String( lastPreview.label ).toLowerCase() + '.';
			} else {
				summary += ' No preview has been run for these settings yet.';
			}

			emailDialog.open( {
				source:  'builder',
				title:   'Email Export',
				summary: summary,
				// Saved recipients are right here on the form, so there is
				// nothing to fetch. Falls back to your own address.
				recipients: ( $f.find( 'textarea[name="recipients"]' ).val() || '' ).trim(),
				payload: function () {
					return {
						name:     $f.find( 'input[name="name"]' ).val(),
						type:     $f.find( 'select[name="type"]' ).val(),
						format:   $f.find( 'select[name="format"]' ).val(),
						active:   isScheduled() ? 1 : 0,
						filters:  collectFilters(),
						schedule: collectSchedule(),
					};
				},
			} );
		} );

		// Download the preview as XLSX via a one-shot POST to admin-post.php.
		// Building the form on the fly avoids nesting inside #wooex-report-form.
		$f.on( 'click', '.wooex-preview-download-btn', function () {
			var $form = $( '<form>', { method: 'post', action: W.admin_post_url } );
			$form.append( hidden( 'action', 'wooex_download_preview' ) );
			$form.append( hidden( '_wpnonce', W.nonce_preview_dl ) );
			$form.append( hidden( 'type', $f.find( 'select[name="type"]' ).val() ) );
			$form.append( hidden( 'active', isScheduled() ? 1 : 0 ) );
			appendFilterFields( $form, 'filters', collectFilters() );
			appendFilterFields( $form, 'schedule', collectSchedule() );
			$form.appendTo( 'body' ).trigger( 'submit' ).remove();
		} );

		function hidden( name, value ) {
			return $( '<input>', { type: 'hidden', name: name, value: value == null ? '' : value } );
		}

		function appendFilterFields( $form, prefix, obj ) {
			$.each( obj, function ( key, val ) {
				var name = prefix + '[' + key + ']';
				if ( $.isArray( val ) ) {
					if ( val.length === 0 ) return; // skip empty arrays
					val.forEach( function ( v ) {
						$form.append( hidden( name + '[]', v ) );
					} );
				} else {
					$form.append( hidden( name, val ) );
				}
			} );
		}

		function renderReviewResults( $out, data ) {
			var rows  = data.rows || [];
			var count = data.count || 0;
			var time  = data.time;

			// PHP sends the label already pluralized against the true total
			// ("Orders", "Attendee"). Lowercased here because it lands
			// mid-sentence. The fallback covers an older cached script or a
			// response that predates the field.
			var label = String( data.label || 'records' ).toLowerCase();

			var html = '<p><strong>' + count + '</strong> ' + escapeHtml( label );
			if ( 'undefined' !== typeof time ) html += ' in ' + time + 's';
			html += '.</p>';

			// Say which window produced these rows. For a scheduled report that
			// is the next run's window, not this minute's, so name the moment
			// too rather than leaving the reader to guess.
			if ( data.range ) {
				html += '<p class="wooex-review-range">'
					+ ( data.range_at
						? 'At the next run (' + escapeHtml( data.range_at ) + ') this covers: '
						: 'Covering: ' )
					+ '<strong>' + escapeHtml( data.range ) + '</strong></p>';
			}

			if ( ! rows.length ) {
				$out.html( html );
				return;
			}

			var headers = Object.keys( rows[0] );
			html += '<div class="wooex-review-table-wrap"><table class="widefat striped"><thead><tr>';
			headers.forEach( function ( h ) { html += '<th>' + escapeHtml( h ) + '</th>'; } );
			html += '</tr></thead><tbody>';
			rows.forEach( function ( r ) {
				html += '<tr>';
				headers.forEach( function ( h ) {
					html += '<td>' + escapeHtml( r[ h ] != null ? String( r[ h ] ) : '' ) + '</td>';
				} );
				html += '</tr>';
			} );
			html += '</tbody></table></div>';
			if ( count > rows.length ) {
				html += '<p><em>Showing first ' + rows.length + ' of ' + count + ' ' + escapeHtml( label ) + '.</em></p>';
			}
			$out.html( html );
		}

		// The schedule sets the moment a report's window is evaluated at, so the
		// range note, the preview and the save all need it and all need the same
		// reading of it. One collector rather than three inline copies.
		function collectSchedule() {
			return {
				frequency:    $( '#wooex-field-frequency' ).val(),
				time:         $( '#wooex-field-time' ).val(),
				days:         $f.find( 'input[name="days[]"]:checked' ).map( function () { return $( this ).val(); } ).get(),
				day_of_month: $( '#wooex-field-dom' ).val(),
			};
		}

		function isScheduled() {
			return $f.find( '#wooex-field-active' ).is( ':checked' );
		}

		function collectFilters() {
			return {
				date_range:      $( '#wooex-field-date-range' ).val(),
				date_from:       $f.find( 'input[name="date_from"]' ).val(),
				date_to:         $f.find( 'input[name="date_to"]' ).val(),
				day_start:       $f.find( 'input[name="day_start"]' ).val(),
				statuses:        collectArray( 'statuses' ),
				customer_ids:    collectArray( 'customer_ids' ),
				product_ids:     collectArray( 'product_ids' ),
				product_cat_ids: collectArray( 'product_cat_ids' ),
				product_tag_ids: collectArray( 'product_tag_ids' ),
				parent_post_ids: collectArray( 'parent_post_ids' ),
			};
		}

		function collectArray( name ) {
			var $el = $f.find( '[name="' + name + '[]"]' );
			var v = $el.val() || [];
			return Array.isArray( v ) ? v : [ v ];
		}

		$f.on( 'submit', function ( e ) {
			e.preventDefault();
			if ( ! clientValidate() ) return;

			var $btns = $( '.wooex-submit-btn' );
			var orig  = $btns.first().text();
			$btns.prop( 'disabled', true ).text( W.i18n.saving );

			var payload = {
				action:      'wooex_save_report',
				_ajax_nonce: W.nonce,
				id:          $f.find( 'input[name="id"]' ).val(),
				name:        $f.find( 'input[name="name"]' ).val(),
				type:        $f.find( 'select[name="type"]' ).val(),
				format:      $f.find( 'select[name="format"]' ).val(),
				active:      isScheduled() ? 1 : 0,
				recipients:  $f.find( 'textarea[name="recipients"]' ).val(),
				filters:     collectFilters(),
				schedule:    collectSchedule(),
			};

			$.post( W.ajax_url, payload )
				.done( function ( res ) {
					if ( res && res.success ) {
						var wasNew = ! payload.id;
						$f.find( 'input[name="id"]' ).val( res.data.id );
						flash( 'success', res.data.message || 'Saved.' );
						clearDirty();

						if ( wasNew && window.history && window.history.replaceState ) {
							var url = new URL( window.location.href );
							url.searchParams.set( 'wooex_view', 'edit' );
							url.searchParams.set( 'id', res.data.id );
							window.history.replaceState( {}, '', url.toString() );
							$( '.wooex-builder-header h1' ).text( 'Edit Export' );
						}
					} else {
						showError( ( res && res.data && res.data.message ) || 'Save failed.' );
					}
				} )
				.fail( function () { showError( 'Network error — try again.' ); } )
				.always( function () { $btns.prop( 'disabled', false ).text( orig ); } );
		} );
	}

	// =====================================================================
	// Boot
	// =====================================================================

	// Align the toast container's left edge with #wpcontent (which moves as the
	// admin menu folds/unfolds). CSS alone can't track this because plugins /
	// themes may shift the sidebar's width.
	function positionToastArea() {
		var $area      = $( '.wooex-notice-area' );
		var $wpcontent = $( '#wpcontent' );
		if ( ! $area.length || ! $wpcontent.length ) return;
		var left = Math.round( $wpcontent[0].getBoundingClientRect().left );
		$area.css( 'left', ( left + 20 ) + 'px' );
	}

	$( function () {
		positionToastArea();
		$( window ).on( 'resize', positionToastArea );
		// WP's collapse button toggles body.folded with a CSS transition.
		$( document ).on( 'click', '#collapse-button, #collapse-menu', function () {
			setTimeout( positionToastArea, 350 );
		} );
		// Catch programmatic class changes (e.g. auto-fold breakpoint).
		if ( window.MutationObserver ) {
			new MutationObserver( positionToastArea ).observe(
				document.body,
				{ attributes: true, attributeFilter: [ 'class' ] }
			);
		}

		// Auto-dismiss any server-rendered success toast.
		$( '.wooex-notice-area .notice-success' ).each( function () {
			autoDismiss( $( this ) );
		} );

		// Before the page initialisers — both of their openers reach for it.
		emailDialog = initEmailDialog();

		if ( $( '.wooex-reports-list' ).length ) {
			initListPage();
		}
		if ( $( '.wooex-report-builder' ).length ) {
			initBuilderPage();
		}
	} );

} )( jQuery );
