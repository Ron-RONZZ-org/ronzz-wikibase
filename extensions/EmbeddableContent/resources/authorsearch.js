/**
 * AddSource "Search for authors" panel.
 *
 * The contributor pastes a bibliography author list into the Author(s)
 * field; this module calls action=authorresolve (all names in ONE request)
 * and renders one row per parsed name:
 *
 *   yellow !  — local candidate(s) exist, not confirmed yet
 *   red    !  — no local match (add one)
 *   green  ✓  — the contributor confirmed an existing/created entity
 *   –         — removed (skipped)
 *
 * Clicking a row opens a radio list of matches plus "Add new person" /
 * "Add new collective" (an ORG: row). Confirming a match injects its Q-id
 * into the Author entity ID(s) field. A new entity is created IMMEDIATELY
 * via action=addsemanticentity and its Q-id is injected the same way.
 *
 * The parsed names + per-name decisions are still round-tripped through the
 * hidden wpauthorChoices field; the server re-validates them. Confirmed rows
 * survive a re-search and stay on top. Submitting is blocked while a row is
 * neither confirmed nor removed.
 *
 * A group-of-humans suggestion is rendered as a banner; "Add all members at
 * once" expands only that group, never the whole author list.
 */
( function () {
	'use strict';

	var ns = mw.embeddableContent.authorsearch = mw.embeddableContent.authorsearch || {};

	function normalize( value ) {
		return String( value || '' ).toLowerCase().replace( /\s+/g, ' ' ).trim();
	}

	function rowKey( row ) {
		return ( row.kind || 'person' ) + '|' + normalize( row.original ) + '|' + normalize( row.familyName );
	}

	function targetField( panel ) {
		return panel === 'author' ? 'wpauthors' : 'wpmembers';
	}

	function addValue( field, value ) {
		if ( mw.embeddableContent.entitysuggest && mw.embeddableContent.entitysuggest.addValue ) {
			return mw.embeddableContent.entitysuggest.addValue( field, value );
		}
		return false;
	}

	function removeValue( field, value ) {
		if ( mw.embeddableContent.entitysuggest && mw.embeddableContent.entitysuggest.removeValue ) {
			return mw.embeddableContent.entitysuggest.removeValue( field, value );
		}
		return false;
	}

	ns.addValue = addValue;
	ns.removeValue = removeValue;
	ns.rowKey = rowKey;

	function initPanel( panel ) {
		var button = document.getElementById( 'wb-' + panel + '-search' );
		var namesInput = document.querySelector( '[name="wp' + panel + 'Names"]' );
		var choicesInput = document.querySelector( '[name="wp' + panel + 'Choices"]' );
		var results = document.getElementById( 'wb-' + panel + '-results' );
		var harvestedInput = document.querySelector( '[name="wp' + panel + 'NamesHarvested"]' );
		if ( !button || !namesInput || !choicesInput || !results ) {
			return;
		}

		var windowManager = new OO.ui.WindowManager();
		$( document.body ).append( windowManager.$element );

		var state = {
			panel: panel,
			field: targetField( panel ),
			button: button,
			namesInput: namesInput,
			choicesInput: choicesInput,
			results: results,
			harvestedInput: harvestedInput,
			api: new mw.Api(),
			windowManager: windowManager,
			data: null,
			rows: [],
			confirmed: {},
			skipped: {},
			confirmedOrder: [],
			synthetic: [],
			dismissedGroups: {},
			lastSearchedText: namesInput.value,
			dirty: false
		};
		ns.state = state;

		results.innerHTML = '<div class="wb-author-banners"></div>'
			+ '<div class="wb-author-rows"></div>';
		state.bannersEl = results.querySelector( '.wb-author-banners' );
		state.rowsEl = results.querySelector( '.wb-author-rows' );

		function decisionFor( row ) {
			if ( row.index === null || row.index === undefined ) {
				return null;
			}
			if ( row.status === 'removed' ) {
				return { index: row.index, action: 'skip' };
			}
			if ( row.status !== 'confirmed' || !row.decision ) {
				return null;
			}
			var decision = { index: row.index, action: row.decision.action };
			if ( row.decision.qid ) {
				decision.qid = row.decision.qid;
			}
			if ( row.decision[ row.kind ] ) {
				decision[ row.kind ] = row.decision[ row.kind ];
			}
			return decision;
		}

		function syncChoices() {
			var list = [];
			state.rows.forEach( function ( row ) {
				var decision = decisionFor( row );
				if ( decision ) {
					list.push( decision );
				}
			} );
			list.sort( function ( a, b ) { return a.index - b.index; } );
			choicesInput.value = list.length ? JSON.stringify( list ) : '';
		}

		function statusFor( row ) {
			if ( row.status === 'removed' ) {
				return mw.msg( 'embeddablecontent-authorsearch-skipped' );
			}
			if ( row.status === 'confirmed' ) {
				if ( row.decision && row.decision.action === 'create' ) {
					return row.kind === 'collective'
						? mw.msg( 'embeddablecontent-authorsearch-created-collective' )
						: mw.msg( 'embeddablecontent-authorsearch-created' );
				}
				return mw.msg( 'embeddablecontent-authorsearch-reused', row.qid || '' )
					+ ( row.label && row.label !== row.qid ? ' — ' + row.label : '' );
			}
			if ( row.error ) {
				return row.error;
			}
			if ( row.candidates && row.candidates.length ) {
				return mw.msg( 'embeddablecontent-authorsearch-candidates', row.candidates.length );
			}
			return mw.msg( 'embeddablecontent-authorsearch-missing' );
		}

		function flagFor( row ) {
			if ( row.status === 'removed' ) {
				return { state: 'skipped', text: '–' };
			}
			if ( row.status === 'confirmed' ) {
				return { state: 'green', text: '✓' };
			}
			return row.candidates && row.candidates.length
				? { state: 'yellow', text: '!' }
				: { state: 'red', text: '!' };
		}

		function openRowDialog( row ) {
			ns.dialogs.open( state, row, function ( result ) {
				onDecision( row, result );
			} );
		}

		function createRowEl( row ) {
			var el = document.createElement( 'div' );
			el.className = 'wb-author-row';
			// The WHOLE row is the activation target. The status text
			// ("No local match found — add one") was a plain span with no
			// handler, so clicking the visible action did nothing; only the
			// name button opened the dialog. The remove/undo button keeps its
			// own handler, and a removed row is inert until undone.
			el.addEventListener( 'click', function ( event ) {
				if ( row.status === 'removed' ) {
					return;
				}
				if ( event.target.closest && event.target.closest( '.wb-author-remove' ) ) {
					return;
				}
				openRowDialog( row );
			} );

			var flag = document.createElement( 'span' );
			flag.className = 'wb-author-flag';
			// Kept as a focusable <button> so the row stays keyboard
			// activatable (Enter/Space bubbles the click to the row).
			var name = document.createElement( 'button' );
			name.type = 'button';
			name.className = 'wb-author-name';
			name.textContent = row.original;
			var parsed = document.createElement( 'span' );
			parsed.className = 'wb-author-parsed';
			parsed.textContent = row.kind === 'collective'
				? mw.msg( 'embeddablecontent-authorsearch-kind-collective' )
				: [ row.givenName, row.familyName ].filter( Boolean ).join( ' ' );
			var status = document.createElement( 'span' );
			status.className = 'wb-author-status';
			var remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'wb-author-remove';
			remove.addEventListener( 'click', function () {
				if ( row.status === 'removed' ) {
					undoRow( row );
				} else {
					removeRow( row );
				}
			} );

			el.appendChild( flag );
			el.appendChild( name );
			el.appendChild( parsed );
			el.appendChild( status );
			el.appendChild( remove );
			row.el = el;
			row.flagEl = flag;
			row.statusEl = status;
			row.removeEl = remove;
			refreshRow( row );
			return el;
		}

		function refreshRow( row ) {
			if ( !row.el ) {
				return;
			}
			var flag = flagFor( row );
			row.el.className = 'wb-author-row wb-author-row-' + row.status;
			row.flagEl.className = 'wb-author-flag wb-author-flag-' + flag.state;
			row.flagEl.textContent = flag.text;
			row.flagEl.title = statusFor( row );
			row.statusEl.textContent = statusFor( row );
			row.removeEl.textContent = row.status === 'removed' ? '↶' : '✕';
			row.removeEl.title = row.status === 'removed'
				? mw.msg( 'embeddablecontent-authorsearch-undo' )
				: mw.msg( 'embeddablecontent-authorsearch-skip' );
			if ( row.status === 'unconfirmed' && !( row.candidates && row.candidates.length ) ) {
				row.el.classList.add( 'wb-author-row-actionable' );
			}
		}

		function sortRows() {
			var order = {};
			state.confirmedOrder.forEach( function ( key, index ) {
				order[ key ] = index;
			} );
			state.rows.sort( function ( a, b ) {
				var rank = function ( row ) {
					if ( row.status === 'confirmed' ) {
						return 0;
					}
					if ( row.status === 'removed' ) {
						return 2;
					}
					return 1;
				};
				var diff = rank( a ) - rank( b );
				if ( diff !== 0 ) {
					return diff;
				}
				if ( a.status === 'confirmed' ) {
					return ( order[ a.key ] !== undefined ? order[ a.key ] : 9999 ) - ( order[ b.key ] !== undefined ? order[ b.key ] : 9999 );
				}
				return ( a.index !== null ? a.index : 9999 ) - ( b.index !== null ? b.index : 9999 );
			} );
		}

		function reorderRows() {
			sortRows();
			var current = Array.prototype.slice.call( state.rowsEl.children );
			var desired = state.rows.map( function ( row ) { return row.el; } );
			current.forEach( function ( node ) {
				if ( desired.indexOf( node ) === -1 ) {
					node.remove();
				}
			} );
			desired.forEach( function ( node, index ) {
				var reference = state.rowsEl.children[ index ] || null;
				if ( reference !== node ) {
					state.rowsEl.insertBefore( node, reference );
				}
			} );
		}

		function rowFromParsed( parsed ) {
			var kind = parsed.kind === 'collective' ? 'collective' : 'person';
			var row = {
				key: rowKey( parsed ),
				kind: kind,
				original: parsed.original,
				givenName: parsed.givenName,
				familyName: parsed.familyName,
				candidates: parsed.candidates || [],
				collective: parsed.collective || null,
				index: parsed.index,
				status: 'unconfirmed',
				decision: null,
				qid: null,
				label: null,
				el: null
			};
			var confirmed = state.confirmed[ row.key ];
			if ( confirmed ) {
				row.status = 'confirmed';
				row.qid = confirmed.qid;
				row.label = confirmed.label;
				row.decision = { index: row.index, action: confirmed.action || 'reuse', qid: confirmed.qid };
			} else if ( state.skipped[ row.key ] ) {
				row.status = 'removed';
				row.decision = { index: row.index, action: 'skip' };
			}
			return row;
		}

		function rebuild() {
			var oldByKey = {};
			state.rows.forEach( function ( row ) {
				oldByKey[ row.key ] = row;
			} );
			var rows = [];
			var seen = {};
			( state.data && state.data.names ? state.data.names : [] ).forEach( function ( parsed, index ) {
				parsed.index = index;
				var row = rowFromParsed( parsed );
				var existing = oldByKey[ row.key ];
				if ( existing ) {
					// Reuse the DOM node (and the confirmed/removed state):
					// a re-search only replaces the unconfirmed rows.
					existing.index = row.index;
					existing.candidates = row.candidates;
					existing.collective = row.collective;
					existing.givenName = row.givenName;
					existing.familyName = row.familyName;
					existing.original = row.original;
					row = existing;
				}
				seen[ row.key ] = true;
				rows.push( row );
			} );
			state.synthetic.forEach( function ( row ) {
				if ( !seen[ row.key ] ) {
					rows.push( row );
				}
			} );
			state.rows = rows;
			state.rows.forEach( function ( row ) {
				if ( !row.el ) {
					createRowEl( row );
				} else {
					refreshRow( row );
				}
			} );
			renderBanners();
			reorderRows();
			syncChoices();
		}

		function renderBanners() {
			state.bannersEl.textContent = '';
			var groups = state.data && state.data.collectives ? state.data.collectives : [];
			groups.forEach( function ( group ) {
				if ( state.dismissedGroups[ group.qid ] ) {
					return;
				}
				var matched = state.rows.find( function ( row ) {
					return row.collective && row.collective.qid === group.qid;
				} );
				var source = matched ? matched.original : ( state.data.input || group.label );
				var banner = document.createElement( 'div' );
				banner.className = 'wb-author-collective';
				var text = document.createElement( 'span' );
				text.textContent = mw.msg(
					'embeddablecontent-authorsearch-collective',
					source,
					group.qid,
					group.label
				);
				var add = document.createElement( 'button' );
				add.type = 'button';
				add.className = 'mw-ui-button mw-ui-progressive';
				add.textContent = mw.msg( 'embeddablecontent-authorsearch-addindividuals' );
				add.addEventListener( 'click', function () {
					addAllMembers( group );
				} );
				var dismiss = document.createElement( 'button' );
				dismiss.type = 'button';
				dismiss.className = 'mw-ui-button';
				dismiss.textContent = mw.msg( 'embeddablecontent-authorsearch-dismiss' );
				dismiss.addEventListener( 'click', function () {
					state.dismissedGroups[ group.qid ] = true;
					banner.remove();
				} );
				banner.appendChild( text );
				banner.appendChild( add );
				banner.appendChild( dismiss );
				state.bannersEl.appendChild( banner );
			} );
		}

		function onDecision( row, result ) {
			if ( !result || result.action === 'skip' ) {
				removeRow( row );
				return;
			}
			row.status = 'confirmed';
			row.kind = result.kind || row.kind;
			row.qid = result.qid;
			row.label = result.label || result.qid;
			row.decision = { index: row.index, action: 'reuse', qid: result.qid };
			state.confirmed[ row.key ] = {
				qid: result.qid,
				label: row.label,
				kind: row.kind
			};
			delete state.skipped[ row.key ];
			if ( state.confirmedOrder.indexOf( row.key ) === -1 ) {
				state.confirmedOrder.push( row.key );
			}
			addValue( state.field, result.qid );
			refreshRow( row );
			syncChoices();
			reorderRows();
		}

		function addSynthetic( kind, qid, label ) {
			var key = 'synthetic|' + qid;
			if ( state.synthetic.some( function ( row ) { return row.key === key; } ) ) {
				return;
			}
			var row = {
				key: key,
				kind: kind,
				original: label || qid,
				givenName: '',
				familyName: label || '',
				candidates: [],
				collective: null,
				index: null,
				status: 'confirmed',
				decision: null,
				qid: qid,
				label: label || qid,
				el: null
			};
			state.synthetic.push( row );
			state.rows.push( row );
			state.confirmedOrder.push( key );
			createRowEl( row );
			state.rowsEl.appendChild( row.el );
			addValue( state.field, qid );
			reorderRows();
			syncChoices();
		}

		function addAllMembers( group ) {
			state.dismissedGroups[ group.qid ] = true;
			var matched = state.rows.find( function ( row ) {
				return row.collective && row.collective.qid === group.qid;
			} );
			if ( matched && matched.status !== 'confirmed' ) {
				removeRow( matched );
			}
			state.api.get( { action: 'groupmembers', qid: group.qid } ).then( function ( data ) {
				var members = data && data.groupmembers && data.groupmembers.members || [];
				if ( members.length ) {
					members.forEach( function ( member ) {
						addSynthetic( 'person', member.qid, member.label );
					} );
					return;
				}
				// No has-member statements: create the parsed member names
				// immediately (the explicit "add all members" confirmation).
				var parsed = group.parsedNames || [];
				if ( !parsed.length ) {
					parsed = ( group.names || [] ).map( function ( name ) {
						var tokens = String( name ).trim().split( /\s+/ );
						var family = tokens.pop() || '';
						return { givenName: tokens.join( ' ' ), familyName: family, original: name };
					} );
				}
				var chain = Promise.resolve();
				parsed.forEach( function ( member ) {
					chain = chain.then( function () {
						return ns.dialogs.createImmediately( state, 'person', {
							givenName: member.givenName,
							familyName: member.familyName
						} ).then( function ( created ) {
							addSynthetic( 'person', created.qid, created.label );
						} );
					} );
				} );
				return chain;
			} ).catch( function ( error ) {
				mw.notify(
					mw.msg( 'embeddablecontent-authorsearch-create-failed', error || '' ),
					{ type: 'error' }
				);
			} );
		}

		function removeRow( row ) {
			if ( row.status === 'confirmed' && row.qid ) {
				removeValue( state.field, row.qid );
				delete state.confirmed[ row.key ];
			}
			delete state.skipped[ row.key ];
			row.status = 'removed';
			row.decision = { index: row.index, action: 'skip' };
			state.skipped[ row.key ] = true;
			refreshRow( row );
			syncChoices();
			reorderRows();
		}

		function undoRow( row ) {
			delete state.skipped[ row.key ];
			row.status = 'unconfirmed';
			row.decision = null;
			refreshRow( row );
			syncChoices();
			reorderRows();
		}

		function search() {
			if ( state.harvestedInput ) {
				state.harvestedInput.value = '';
			}
			var names = state.namesInput.value.trim();
			if ( names === '' ) {
				mw.notify( mw.msg( 'embeddablecontent-authorsearch-empty' ), { type: 'warning' } );
				return;
			}
			state.button.disabled = true;
			state.results.classList.add( 'wb-author-loading' );
			state.api.get( {
				action: 'authorresolve',
				names: names,
				nameFormat: 'auto',
				language: mw.config.get( 'wgUserLanguage' ) || 'en'
			} ).then( function ( response ) {
				state.data = response.authorresolve || { names: [], collectives: [], warnings: [] };
				state.lastSearchedText = state.namesInput.value;
				state.dirty = false;
				rebuild();
			} ).catch( function () {
				mw.notify( mw.msg( 'embeddablecontent-authorsearch-error' ), { type: 'error' } );
			} ).always( function () {
				state.button.disabled = false;
				state.results.classList.remove( 'wb-author-loading' );
			} );
		}

		function hasBlockingRows() {
			if ( state.dirty && ( state.data || state.confirmedOrder.length ) ) {
				return true;
			}
			return state.rows.some( function ( row ) {
				return row.status === 'unconfirmed' || row.status === 'error';
			} );
		}

		button.addEventListener( 'click', search );
		state.namesInput.addEventListener( 'input', function () {
			if ( state.harvestedInput ) {
				state.harvestedInput.value = '';
			}
			state.dirty = true;
		} );
		var form = state.namesInput.closest( 'form' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( hasBlockingRows() ) {
					event.preventDefault();
					event.stopImmediatePropagation();
					mw.notify(
						state.dirty
							? mw.msg( 'embeddablecontent-authorsearch-rerun' )
							: mw.msg( 'embeddablecontent-authorsearch-blocked' ),
						{ type: 'warning' }
					);
				}
			}, true );
		}

		ns.syncChoices = syncChoices;
		ns.rebuild = rebuild;
	}

	mw.loader.using( [ 'mediawiki.api', 'mediawiki.notification', 'oojs-ui', 'ext.embeddableContent.entitysuggest' ] ).then( function () {
		initPanel( 'author' );
		initPanel( 'member' );
	} );
}() );
