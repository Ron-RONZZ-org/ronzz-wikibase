/**
 * AddSource "Search for authors" panel.
 *
 * The contributor pastes a bibliography author list into the Author names
 * field; this module calls action=authorresolve (all names in ONE request)
 * and renders one row per parsed name with a status flag:
 *
 *   yellow !  — local Person candidate(s) exist, none confirmed yet
 *   red    !  — no local Person found (create one)
 *   green  ✓  — the contributor confirmed a reuse/create/update decision
 *
 * Clicking a name opens an OOUI dialog with the local candidates. Every
 * decision is explicit — an exact label match is NEVER auto-accepted (common
 * names are ambiguous). The decisions are written to the hidden
 * wpauthorChoices field as JSON; the server re-validates them and creates
 * the new Person items before creating the Source.
 *
 * A group-of-humans item whose label is itself a person-name list (the
 * legacy "author list stored as a collective" pattern) is surfaced as a
 * warning with an "Add the individuals" shortcut.
 */
( function () {
	'use strict';

	function initPanel( panel ) {
		var button = document.getElementById( 'wb-' + panel + '-search' );
		var namesInput = document.querySelector( '[name="wp' + panel + 'Names"]' );
		var choicesInput = document.querySelector( '[name="wp' + panel + 'Choices"]' );
		var results = document.getElementById( 'wb-' + panel + '-results' );
		var harvestedInput = document.querySelector( '[name="wp' + panel + 'NamesHarvested"]' );
		if ( !button || !namesInput || !choicesInput || !results ) {
			return;
		}

		var api = new mw.Api();
		var decisions = {};
		var data = null;
		var windowManager = new OO.ui.WindowManager();

		$( document.body ).append( windowManager.$element );

		function syncChoices() {
			var list = Object.keys( decisions )
				.sort( function ( a, b ) { return Number( a ) - Number( b ); } )
				.map( function ( key ) { return decisions[ key ]; } );
			choicesInput.value = list.length ? JSON.stringify( list ) : '';
		}

		function reset() {
			decisions = {};
			data = null;
			results.textContent = '';
			if ( harvestedInput ) {
				harvestedInput.value = '';
			}
			syncChoices();
		}

		namesInput.addEventListener( 'input', reset );

		function flagState( row, index ) {
			var decision = decisions[ index ];
			if ( decision ) {
				return decision.action === 'skip' ? 'skipped' : 'green';
			}
			return row.candidates && row.candidates.length ? 'yellow' : 'red';
		}

		function statusText( row, index ) {
			var decision = decisions[ index ];
			if ( decision ) {
				if ( decision.action === 'skip' ) {
					return mw.msg( 'embeddablecontent-authorsearch-skipped' );
				}
				if ( decision.action === 'reuse' ) {
					return mw.msg( 'embeddablecontent-authorsearch-reused', decision.qid );
				}
				if ( decision.action === 'update' ) {
					return mw.msg( 'embeddablecontent-authorsearch-updated', decision.qid );
				}
				return mw.msg( 'embeddablecontent-authorsearch-created' );
			}
			if ( row.candidates && row.candidates.length ) {
				return mw.msg( 'embeddablecontent-authorsearch-candidates', row.candidates.length );
			}
			return mw.msg( 'embeddablecontent-authorsearch-missing' );
		}

		function render() {
			results.textContent = '';
			if ( !data ) {
				return;
			}
			( data.collectives || [] ).forEach( function ( collective ) {
				var banner = document.createElement( 'div' );
				banner.className = 'wb-author-collective';
				var text = document.createElement( 'span' );
				text.textContent = mw.msg(
					'embeddablecontent-authorsearch-collective',
					collective.qid,
					collective.label
				);
				var add = document.createElement( 'button' );
				add.type = 'button';
				add.className = 'mw-ui-button';
				add.textContent = mw.msg( 'embeddablecontent-authorsearch-addindividuals' );
				add.addEventListener( 'click', function () {
					namesInput.value = collective.names.join( '; ' );
					reset();
					search();
				} );
				banner.appendChild( text );
				banner.appendChild( add );
				results.appendChild( banner );
			} );

			( data.names || [] ).forEach( function ( row, index ) {
				var state = flagState( row, index );
				var wrapper = document.createElement( 'div' );
				wrapper.className = 'wb-author-row';

				var flag = document.createElement( 'span' );
				flag.className = 'wb-author-flag wb-author-flag-' + state;
				flag.textContent = state === 'green' ? '✓' : ( state === 'skipped' ? '–' : '!' );
				flag.title = statusText( row, index );

				var nameButton = document.createElement( 'button' );
				nameButton.type = 'button';
				nameButton.className = 'wb-author-name';
				nameButton.textContent = row.original;
				nameButton.addEventListener( 'click', function () {
					openDialog( row, index );
				} );

				var parsed = document.createElement( 'span' );
				parsed.className = 'wb-author-parsed';
				parsed.textContent = [ row.givenName, row.familyName ]
					.filter( Boolean ).join( ' ' );

				var status = document.createElement( 'span' );
				status.className = 'wb-author-status';
				status.textContent = statusText( row, index );

				wrapper.appendChild( flag );
				wrapper.appendChild( nameButton );
				wrapper.appendChild( parsed );
				wrapper.appendChild( status );
				results.appendChild( wrapper );
			} );
		}

		function candidateLabel( candidate ) {
			var parts = [ candidate.qid + ' — ' + candidate.label ];
			if ( candidate.description ) {
				parts.push( '(' + candidate.description + ')' );
			}
			var authority = candidate.authority || {};
			var ids = [];
			[ 'orcid', 'viaf', 'isni', 'wikidata' ].forEach( function ( key ) {
				if ( authority[ key ] ) {
					ids.push( key.toUpperCase() + ': ' + authority[ key ] );
				}
			} );
			if ( ids.length ) {
				parts.push( '[' + ids.join( ' · ' ) + ']' );
			}
			return parts.join( ' ' );
		}

		function splitCandidateName( candidate ) {
			var label = String( candidate.label || '' );
			var comma = label.indexOf( ',' );
			if ( comma > 0 ) {
				return {
					givenName: label.slice( comma + 1 ).trim(),
					familyName: label.slice( 0, comma ).trim()
				};
			}
			var tokens = label.split( /\s+/ ).filter( Boolean );
			if ( tokens.length <= 1 ) {
				return { givenName: '', familyName: tokens[ 0 ] || '' };
			}
			var family = tokens.pop();
			return { givenName: tokens.join( ' ' ), familyName: family };
		}

		function field( labelKey, value, placeholder ) {
			var input = new OO.ui.TextInputWidget( {
				value: value || '',
				placeholder: placeholder || ''
			} );
			return {
				input: input,
				layout: new OO.ui.FieldLayout( input, {
					label: mw.msg( labelKey ),
					align: 'top'
				} )
			};
		}

		function buildDetails( prefill ) {
			var given = field( 'embeddablecontent-authorsearch-given', prefill.givenName );
			var family = field( 'embeddablecontent-authorsearch-family', prefill.familyName );
			var description = field( 'embeddablecontent-authorsearch-description', prefill.description );
			var orcid = field( 'embeddablecontent-authorsearch-orcid', prefill.orcid, '0000-0000-0000-0000' );
			var viaf = field( 'embeddablecontent-authorsearch-viaf', prefill.viaf );
			var isni = field( 'embeddablecontent-authorsearch-isni', prefill.isni );
			var website = field( 'embeddablecontent-authorsearch-website', prefill.officialWebsite );
			var dob = field( 'embeddablecontent-authorsearch-dateofbirth', prefill.dateOfBirth, 'YYYY-MM-DD' );
			var dod = field( 'embeddablecontent-authorsearch-dateofdeath', prefill.dateOfDeath, 'YYYY-MM-DD' );
			var fields = [ given, family, description, orcid, viaf, isni, website, dob, dod ];
			var $body = $( '<div>' );
			fields.forEach( function ( item ) {
				$body.append( item.layout.$element );
			} );
			return {
				$body: $body,
				values: function () {
					var out = {};
					var pairs = {
						givenName: given.input.getValue(),
						familyName: family.input.getValue(),
						description: description.input.getValue(),
						orcid: orcid.input.getValue(),
						viafId: viaf.input.getValue(),
						isni: isni.input.getValue(),
						officialWebsite: website.input.getValue(),
						dateOfBirth: dob.input.getValue(),
						dateOfDeath: dod.input.getValue()
					};
					Object.keys( pairs ).forEach( function ( key ) {
						var value = String( pairs[ key ] || '' ).trim();
						if ( value !== '' ) {
							out[ key ] = value;
						}
					} );
					return out;
				}
			};
		}

		function openDialog( row, index ) {
			var dialog = new OO.ui.Dialog( {
				title: mw.msg( 'embeddablecontent-authorsearch-dialog-title', row.original ),
				classes: [ 'wb-author-dialog' ]
			} );
			var $body = $( '<div>' );
			var candidates = row.candidates || [];

			if ( candidates.length ) {
				$body.append( $( '<p>' ).text( mw.msg( 'embeddablecontent-authorsearch-choose' ) ) );
				candidates.forEach( function ( candidate ) {
					var $candidate = $( '<div>' ).addClass( 'wb-author-candidate' );
					$candidate.append( $( '<span>' ).text( candidateLabel( candidate ) ) );
					var use = new OO.ui.ButtonWidget( {
						label: mw.msg( 'embeddablecontent-authorsearch-use' ),
						flags: [ 'primary', 'progressive' ]
					} );
					use.on( 'click', function () {
						decisions[ index ] = { index: index, action: 'reuse', qid: candidate.qid };
						syncChoices();
						render();
						dialog.close();
					} );
					var edit = new OO.ui.ButtonWidget( {
						label: mw.msg( 'embeddablecontent-authorsearch-edit' )
					} );
					edit.on( 'click', function () {
						openDetails( row, index, candidate, 'update', dialog );
					} );
					$candidate.append( use.$element );
					$candidate.append( edit.$element );
					$body.append( $candidate );
				} );
			}

			var create = new OO.ui.ButtonWidget( {
				label: mw.msg( 'embeddablecontent-authorsearch-create' ),
				flags: [ 'progressive' ]
			} );
			create.on( 'click', function () {
				openDetails( row, index, null, 'create', dialog );
			} );
			var skip = new OO.ui.ButtonWidget( {
				label: mw.msg( 'embeddablecontent-authorsearch-skip' )
			} );
			skip.on( 'click', function () {
				decisions[ index ] = { index: index, action: 'skip' };
				syncChoices();
				render();
				dialog.close();
			} );
			$body.append( $( '<div>' ).addClass( 'wb-author-dialog-actions' )
				.append( create.$element ).append( skip.$element ) );

			dialog.$body.append( $body );
			windowManager.addWindows( [ dialog ] );
			windowManager.openWindow( dialog );
		}

		function openDetails( row, index, candidate, action, parentDialog ) {
			var prefill = action === 'update' && candidate
				? splitCandidateName( candidate )
				: { givenName: row.givenName, familyName: row.familyName };
			var details = buildDetails( prefill );
			var dialog = new OO.ui.Dialog( {
				title: mw.msg(
					action === 'update'
						? 'embeddablecontent-authorsearch-update-title'
						: 'embeddablecontent-authorsearch-create-title',
					row.original
				),
				classes: [ 'wb-author-dialog' ]
			} );
			var $body = $( '<div>' );
			if ( action === 'update' ) {
				$body.append( $( '<p>' ).addClass( 'wb-author-hint' )
					.text( mw.msg( 'embeddablecontent-authorsearch-keepblank' ) ) );
			}
			$body.append( details.$body );
			var save = new OO.ui.ButtonWidget( {
				label: mw.msg(
					action === 'update'
						? 'embeddablecontent-authorsearch-save'
						: 'embeddablecontent-authorsearch-create'
				),
				flags: [ 'primary', 'progressive' ]
			} );
			save.on( 'click', function () {
				var person = details.values();
				decisions[ index ] = action === 'update'
					? { index: index, action: 'update', qid: candidate.qid, person: person }
					: { index: index, action: 'create', person: person };
				syncChoices();
				render();
				dialog.close();
				parentDialog.close();
			} );
			var back = new OO.ui.ButtonWidget( {
				label: mw.msg( 'embeddablecontent-authorsearch-back' )
			} );
			back.on( 'click', function () {
				dialog.close();
			} );
			$body.append( $( '<div>' ).addClass( 'wb-author-dialog-actions' )
				.append( save.$element ).append( back.$element ) );
			dialog.$body.append( $body );
			windowManager.addWindows( [ dialog ] );
			windowManager.openWindow( dialog );
		}

		function search() {
			if ( harvestedInput ) {
				harvestedInput.value = '';
			}
			var names = namesInput.value.trim();
			if ( names === '' ) {
				mw.notify( mw.msg( 'embeddablecontent-authorsearch-empty' ), { type: 'warning' } );
				return;
			}
			button.disabled = true;
			results.textContent = mw.msg( 'embeddablecontent-authorsearch-searching' );
			api.get( {
				action: 'authorresolve',
				names: names,
				nameFormat: 'auto',
				language: mw.config.get( 'wgUserLanguage' ) || 'en'
			} ).then( function ( response ) {
				data = response.authorresolve || { names: [], collectives: [], warnings: [] };
				decisions = {};
				syncChoices();
				render();
			} ).catch( function () {
				results.textContent = mw.msg( 'embeddablecontent-authorsearch-error' );
			} ).always( function () {
				button.disabled = false;
			} );
		}

		button.addEventListener( 'click', search );
	}

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.notification', 'oojs-ui' ] ).then( function () {
		initPanel( 'author' );
		initPanel( 'member' );
	} );
}() );
