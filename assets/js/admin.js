/* global SDI, jQuery */
/**
 * Saayeh Demo Importer – admin UI.
 * Drives the AJAX step-by-step import / removal with progress, log and retry.
 */
( function ( $ ) {
	'use strict';

	if ( typeof SDI === 'undefined' ) {
		return;
	}

	var t = SDI.i18n;
	var busy = false;

	function fa( value ) {
		return String( value ).replace( /\d/g, function ( d ) {
			return '۰۱۲۳۴۵۶۷۸۹'.charAt( +d );
		} );
	}

	/* ------------------------------------------------------------ modal */

	function confirmDialog( title, text, okLabel, danger ) {
		var $m = $( '.sdi-modal' );
		var $ok = $m.find( '.sdi-modal__ok' );
		var lastFocus = document.activeElement;
		$m.find( '.sdi-modal__title' ).text( title );
		$m.find( '.sdi-modal__text' ).text( text );
		$ok.text( okLabel ).toggleClass( 'sdi-danger', !! danger );
		$m.prop( 'hidden', false );
		$ok.trigger( 'focus' );

		return new Promise( function ( resolve ) {
			function close( result ) {
				$m.prop( 'hidden', true );
				$m.off( '.sdi' );
				$( document ).off( 'keydown.sdimodal' );
				if ( lastFocus ) {
					lastFocus.focus();
				}
				resolve( result );
			}
			$m.on( 'click.sdi', '[data-sdi-close]', function () {
				close( false );
			} );
			$m.on( 'click.sdi', '.sdi-modal__ok', function () {
				close( true );
			} );
			$( document ).on( 'keydown.sdimodal', function ( e ) {
				if ( e.key === 'Escape' ) {
					close( false );
				}
			} );
		} );
	}

	/* -------------------------------------------------------------- ajax */

	function post( action, data ) {
		return $.ajax( {
			url: SDI.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: 300000,
			data: $.extend( { action: action, nonce: SDI.nonce }, data || {} )
		} );
	}

	/* --------------------------------------------------------- card view */

	function Card( $card ) {
		this.$card = $card;
		this.theme = $card.data( 'theme' );
		this.$progress = $card.find( '.sdi-progress' );
		this.$bar = $card.find( '.sdi-progress__bar' );
		this.$track = $card.find( '.sdi-progress__track' );
		this.$pct = $card.find( '.sdi-progress__pct' );
		this.$label = $card.find( '.sdi-progress__label' );
		this.$log = $card.find( '.sdi-log' );
		this.$retry = $card.find( '.sdi-retry-btn' );
		this.$stop = $card.find( '.sdi-stop-btn' );
		this.$view = $card.find( '.sdi-view-btn' );
		this.stopRequested = false;
		this.failures = 0;
	}

	Card.prototype.show = function () {
		this.$progress.prop( 'hidden', false );
		this.$card.addClass( 'is-busy' );
		this.$card.find( '.sdi-import-btn, .sdi-remove-btn, .sdi-options' ).prop( 'disabled', true );
	};

	Card.prototype.idle = function () {
		this.$card.removeClass( 'is-busy' );
		this.$stop.prop( 'hidden', true );
		this.$card.find( '.sdi-remove-btn, .sdi-options' ).prop( 'disabled', false );
		this.$card.find( '.sdi-import-btn' ).prop( 'disabled', this.$card.find( '.sdi-options' ).length === 0 );
		busy = false;
	};

	Card.prototype.progress = function ( pct, label ) {
		pct = Math.max( 0, Math.min( 100, pct | 0 ) );
		this.$bar.css( 'width', pct + '%' );
		this.$track.attr( 'aria-valuenow', pct );
		this.$pct.text( fa( pct ) + '٪' );
		if ( label !== undefined ) {
			this.$label.text( label );
		}
	};

	Card.prototype.log = function ( entries ) {
		var $log = this.$log;
		$.each( entries || [], function ( i, e ) {
			$( '<li/>' ).addClass( 'is-' + ( e.level || 'info' ) ).text( e.message ).appendTo( $log );
		} );
		$log.scrollTop( $log[ 0 ].scrollHeight );
	};

	Card.prototype.message = function ( text, level ) {
		this.log( [ { message: text, level: level || 'info' } ] );
	};

	Card.prototype.stateLabel = function ( s ) {
		if ( s.done ) {
			return t.done;
		}
		var label = s.label || '';
		if ( s.steps && s.steps.length ) {
			label = t.step + ' ' + fa( s.index + 1 ) + ' ' + t.of + ' ' + fa( s.steps.length ) + ': ' + label;
		}
		if ( s.total ) {
			label += ' (' + fa( s.cursor ) + '/' + fa( s.total ) + ')';
		}
		return label;
	};

	/* ------------------------------------------------------------ import */

	Card.prototype.startImport = function ( groups, resume ) {
		var self = this;
		busy = true;
		self.show();
		self.stopRequested = false;
		self.$retry.prop( 'hidden', true );
		self.$view.prop( 'hidden', true );
		self.$stop.text( t.stop ).prop( 'hidden', false );
		if ( ! resume ) {
			self.$log.empty();
			self.progress( 0, t.importing );
		}
		post( 'sdi_import_start', { theme: self.theme, groups: groups, resume: resume ? 1 : 0 } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					self.fail( res && res.data ? res.data : {} );
					return;
				}
				self.log( res.data.log );
				self.progress( res.data.percent, self.stateLabel( res.data ) );
				self.next();
			} )
			.fail( function ( xhr ) {
				self.fail( xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : { message: t.network } );
			} );
	};

	Card.prototype.next = function () {
		var self = this;
		if ( self.stopRequested ) {
			self.message( t.stopped, 'warning' );
			self.$retry.text( t.retry ).prop( 'hidden', false );
			self.idle();
			return;
		}
		post( 'sdi_import_step' )
			.done( function ( res ) {
				if ( ! res ) {
					self.networkError();
					return;
				}
				if ( ! res.success ) {
					var d = res.data || {};
					if ( d.log ) {
						self.log( d.log );
					}
					if ( d.code === 'sdi_locked' && self.failures < 10 ) {
						self.failures++;
						setTimeout( function () {
							self.next();
						}, 3000 );
						return;
					}
					self.fail( d );
					return;
				}
				self.failures = 0;
				var s = res.data;
				self.log( s.log );
				self.progress( s.percent, self.stateLabel( s ) );
				if ( s.done ) {
					self.complete();
				} else {
					self.next();
				}
			} )
			.fail( function () {
				self.networkError();
			} );
	};

	Card.prototype.networkError = function () {
		var self = this;
		self.failures++;
		if ( self.failures <= 3 ) {
			self.message( t.network + ' ' + t.retrying, 'warning' );
			setTimeout( function () {
				self.next();
			}, 2000 * self.failures );
			return;
		}
		self.fail( { message: t.network } );
	};

	Card.prototype.fail = function ( data ) {
		this.message( ( data && data.message ) || t.error, 'error' );
		this.$retry.text( t.retry ).prop( 'hidden', false );
		this.idle();
	};

	Card.prototype.complete = function () {
		this.progress( 100, t.done );
		this.$view.prop( 'hidden', false );
		this.idle();
		this.$card.find( '.sdi-status' ).addClass( 'is-imported' ).text( t.importedNow );
		if ( ! this.$card.find( '.sdi-remove-btn' ).length ) {
			$( '<button type="button" class="button button-link-delete sdi-remove-btn"/>' )
				.text( t.removeTitle )
				.appendTo( this.$card.find( '.sdi-actions' ) );
		}
	};

	/* ------------------------------------------------------------ remove */

	Card.prototype.startRemove = function () {
		var self = this;
		busy = true;
		self.show();
		self.$log.empty();
		self.$retry.prop( 'hidden', true );
		self.$view.prop( 'hidden', true );
		self.progress( 0, t.removing );
		self.initialRemaining = 0;
		self.removeNext();
	};

	Card.prototype.removeNext = function () {
		var self = this;
		post( 'sdi_remove', { theme: self.theme } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					self.removeFail( res && res.data ? res.data : {} );
					return;
				}
				var d = res.data;
				self.log( d.log );
				if ( d.done ) {
					self.progress( 100, t.removed );
					setTimeout( function () {
						window.location.reload();
					}, 1500 );
					return;
				}
				var total = Math.max( self.initialRemaining, d.remaining + d.removed );
				self.initialRemaining = total;
				self.progress( ( ( total - d.remaining ) / total ) * 100, t.removing + ' ' + fa( d.remaining ) + ' ' + t.remaining );
				self.removeNext();
			} )
			.fail( function ( xhr ) {
				self.removeFail( xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : { message: t.network } );
			} );
	};

	Card.prototype.removeFail = function ( data ) {
		this.message( ( data && data.message ) || t.error, 'error' );
		this.$retry.text( t.retry ).prop( 'hidden', false ).data( 'mode', 'remove' );
		this.idle();
	};

	/* ------------------------------------------------------------ wiring */

	function cardFor( el ) {
		var $c = $( el ).closest( '.sdi-card' );
		if ( ! $c.data( 'sdiCard' ) ) {
			$c.data( 'sdiCard', new Card( $c ) );
		}
		return $c.data( 'sdiCard' );
	}

	$( document ).on( 'click', '.sdi-import-btn', function () {
		if ( busy ) {
			return;
		}
		var card = cardFor( this );
		var groups = card.$card.find( 'input[name="sdi_groups[]"]:checked' ).map( function () {
			return this.value;
		} ).get();
		if ( ! groups.length ) {
			card.$progress.prop( 'hidden', false );
			card.message( t.noGroups, 'warning' );
			return;
		}
		confirmDialog( t.confirmTitle, t.confirmText, t.confirmYes ).then( function ( ok ) {
			if ( ok ) {
				$( '.sdi-resume' ).prop( 'hidden', true );
				card.startImport( groups, false );
			}
		} );
	} );

	$( document ).on( 'click', '.sdi-remove-btn', function () {
		if ( busy ) {
			return;
		}
		var card = cardFor( this );
		confirmDialog( t.removeTitle, t.removeText, t.removeYes, true ).then( function ( ok ) {
			if ( ok ) {
				$( '.sdi-resume' ).prop( 'hidden', true );
				card.startRemove();
			}
		} );
	} );

	$( document ).on( 'click', '.sdi-retry-btn', function () {
		var card = cardFor( this );
		var $btn = $( this );
		$btn.prop( 'hidden', true );
		card.failures = 0;
		if ( $btn.data( 'mode' ) === 'remove' ) {
			$btn.removeData( 'mode' );
			busy = true;
			card.show();
			card.removeNext();
			return;
		}
		card.startImport( [], true );
	} );

	$( document ).on( 'click', '.sdi-stop-btn', function () {
		var card = cardFor( this );
		card.stopRequested = true;
		$( this ).prop( 'disabled', true );
		setTimeout( function () {
			card.$stop.prop( 'disabled', false );
		}, 1500 );
	} );

	/* ------------------------------------------------------------ resume */

	$( function () {
		var run = SDI.run;
		if ( ! run || run.done ) {
			return;
		}
		var $card = $( '.sdi-card' ).filter( function () {
			return $( this ).data( 'theme' ) === run.theme;
		} );
		if ( ! $card.length ) {
			return;
		}
		var card = cardFor( $card );
		card.$progress.prop( 'hidden', false );
		card.log( run.log );
		card.progress( run.percent, card.stateLabel( run ) );
		if ( $card.find( '.sdi-import-btn' ).is( ':disabled' ) ) {
			return;
		}
		$( '.sdi-resume' ).prop( 'hidden', false );
		$( '.sdi-resume-btn' ).on( 'click', function () {
			$( '.sdi-resume' ).prop( 'hidden', true );
			card.startImport( [], true );
		} );
		$( '.sdi-discard-btn' ).on( 'click', function () {
			post( 'sdi_import_cancel' ).always( function () {
				$( '.sdi-resume' ).prop( 'hidden', true );
				card.$progress.prop( 'hidden', true );
			} );
		} );
	} );
}( jQuery ) );
