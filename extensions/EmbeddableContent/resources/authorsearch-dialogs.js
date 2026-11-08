/**
 * AddSource author panel — match chooser and immediate entity creation.
 *
 * All dialogs are OOUI; the whole module is a no-op until the main
 * authorsearch.js calls ns.dialogs.open(). New Person/Collective items are
 * written IMMEDIATELY through action=addsemanticentity (the duplicate and
 * exact-label collision guards are handled with a small choice dialog).
 */
( function () {
	'use strict';

	var ns = mw.embeddableContent.authorsearch = mw.embeddableContent.authorsearch || {};
	ns.dialogs = ns.dialogs || {};

	function textField( labelKey, value, placeholder ) {
		var input = new OO.ui.TextInputWidget( {
			value: value || '',
			placeholder: placeholder || ''
		} );
		var layout = new OO.ui.FieldLayout( input, {
			label: mw.msg( labelKey ),
			align: 'top'
		} );
		return { input: input, layout: layout };
	}

	function actionRow( buttons ) {
		var $row = $( '<div>' ).addClass( 'wb-author-dialog-actions' );
		buttons.forEach( function ( button ) {
			$row.append( button.$element );
		} );
		return $row;
	}

	/** Monotonic counter giving every open dialog a unique window name. */
	var dialogSequence = 0;

	/**
	 * Open an OOUI dialog whose body is built in initialize().
	 *
	 * A bare `new OO.ui.Dialog()` cannot be registered with a
	 * WindowManager:
	 *  - `OO.ui.Window.initialize()` — which creates `$body` — only runs
	 *    when the window is opened, so `dialog.$body.append(...)` before
	 *    that throws `Cannot read properties of undefined (reading
	 *    'append')`;
	 *  - `OO.ui.Dialog.static.name` is the empty string, so
	 *    `WindowManager.addWindows()` throws "Windows must have a `name`
	 *    static property defined".
	 * Both errors aborted every author-row click, so no dialog ever
	 * opened. A ProcessDialog subclass with a unique name and an
	 * initialize() that appends the body is the supported pattern (same
	 * as addmore.js / sitelinktab.js).
	 *
	 * @param {Object} state the author-panel state (owns windowManager)
	 * @param {Object} config { title, size, classes }
	 * @param {Function} buildBody receives the dialog; it may append the
	 *        content itself or return a jQuery node to append
	 * @return {OO.ui.ProcessDialog}
	 */
	function openContentDialog( state, config, buildBody ) {
		var name = 'embeddableContentAuthorDialog' + ( ++dialogSequence );
		function ContentDialog( cfg ) {
			ContentDialog.super.call( this, cfg );
		}
		OO.inheritClass( ContentDialog, OO.ui.ProcessDialog );
		ContentDialog.static.name = name;
		ContentDialog.static.title = config.title;
		ContentDialog.static.actions = [];
		ContentDialog.prototype.initialize = function () {
			ContentDialog.super.prototype.initialize.call( this );
			var content = buildBody( this );
			if ( content ) {
				this.$body.append( content );
			}
		};
		var dialog = new ContentDialog( {
			size: config.size,
			classes: config.classes
		} );
		state.windowManager.addWindows( [ dialog ] );
		// OOUI Windows emit no 'close' event; the openWindow lifecycle's
		// `.closed` promise is the supported hook. Detaching the window on
		// close keeps a long confirmation session from accumulating hidden
		// dialogs in the manager.
		state.windowManager.openWindow( dialog ).closed.then( function () {
			state.windowManager.removeWindows( [ name ] );
		} );
		return dialog;
	}

	/**
	 * Render a duplicate/collision choice into $host and resolve it.
	 *
	 * The create dialog keeps the choice INLINE (a single WindowManager
	 * refuses a second open window, and a second modal manager cannot be
	 * clicked through the first). The batch "Add all members at once" path
	 * has no dialog open and uses a standalone dialog instead.
	 *
	 * @param {jQuery} $host container to render into
	 * @param {string} message
	 * @param {Array} choices [{ value, label, flags }]
	 * @param {Function} resolve called with the chosen value (or null)
	 * @param {Function|null} closeHost closes the host dialog on choice
	 */
	function renderChoice( $host, message, choices, resolve, closeHost ) {
		$host.empty();
		$host.append( $( '<p>' ).addClass( 'wb-author-hint' ).text( message ) );
		var buttons = choices.map( function ( choice ) {
			var button = new OO.ui.ButtonWidget( {
				label: choice.label,
				flags: choice.flags || []
			} );
			button.on( 'click', function () {
				$host.empty();
				if ( closeHost ) {
					closeHost();
				}
				resolve( choice.value );
			} );
			return button;
		} );
		var cancel = new OO.ui.ButtonWidget( {
			label: mw.msg( 'embeddablecontent-authorsearch-cancel' )
		} );
		cancel.on( 'click', function () {
			$host.empty();
			if ( closeHost ) {
				closeHost();
			}
			resolve( null );
		} );
		buttons.push( cancel );
		$host.append( actionRow( buttons ) );
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

	function candidateLabel( candidate ) {
		var label = candidate.qid + ' — ' + candidate.label;
		if ( candidate.description ) {
			label += ' (' + candidate.description + ')';
		}
		return label;
	}

	function personFields( prefill ) {
		var given = textField( 'embeddablecontent-authorsearch-given', prefill.givenName );
		var family = textField( 'embeddablecontent-authorsearch-family', prefill.familyName );
		var description = textField( 'embeddablecontent-authorsearch-description', prefill.description );
		var orcid = textField( 'embeddablecontent-authorsearch-orcid', prefill.orcid, '0000-0000-0000-0000' );
		var viaf = textField( 'embeddablecontent-authorsearch-viaf', prefill.viaf );
		var isni = textField( 'embeddablecontent-authorsearch-isni', prefill.isni );
		var website = textField( 'embeddablecontent-authorsearch-website', prefill.officialWebsite );
		var dob = textField( 'embeddablecontent-authorsearch-dateofbirth', prefill.dateOfBirth, 'YYYY-MM-DD' );
		var dod = textField( 'embeddablecontent-authorsearch-dateofdeath', prefill.dateOfDeath, 'YYYY-MM-DD' );
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

	function collectiveClasses() {
		var classes = mw.config.get( 'wbAuthorCollectiveClasses' );
		if ( !Array.isArray( classes ) || classes.length === 0 ) {
			classes = [ { key: 'organization', label: mw.msg( 'embeddablecontent-agent-class-organization' ) } ];
		}
		return classes;
	}

	function collectiveFields( prefill ) {
		var label = textField( 'embeddablecontent-authorsearch-collective-label', prefill.label );
		var description = textField( 'embeddablecontent-authorsearch-collective-description', prefill.description );
		var website = textField( 'embeddablecontent-authorsearch-collective-website', prefill.officialWebsite );
		var options = collectiveClasses().map( function ( item ) {
			return { data: item.key, label: item.label };
		} );
		var classInput = new OO.ui.DropdownInputWidget( {
			options: options,
			value: prefill.collectiveClass || options[ 0 ] && options[ 0 ].data
		} );
		var classLayout = new OO.ui.FieldLayout( classInput, {
			label: mw.msg( 'embeddablecontent-authorsearch-collective-class' ),
			align: 'top'
		} );
		var $body = $( '<div>' );
		$body.append( label.layout.$element );
		$body.append( description.layout.$element );
		$body.append( classLayout.$element );
		$body.append( website.layout.$element );
		return {
			$body: $body,
			values: function () {
				var out = {};
				var pairs = {
					label: label.input.getValue(),
					description: description.input.getValue(),
					collectiveClass: classInput.getValue(),
					officialWebsite: website.input.getValue()
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

	/**
	 * Resolve a duplicate/collision choice.
	 *
	 * @param {Object} state
	 * @param {string} title standalone-dialog title
	 * @param {string} message
	 * @param {Array} choices
	 * @param {jQuery} [$host] when given, render the choice inline in that
	 *        element (the create dialog is already open); otherwise open a
	 *        standalone dialog (the batch-creation path).
	 * @return {Promise}
	 */
	function confirmChoice( state, title, message, choices, $host ) {
		return new Promise( function ( resolve ) {
			if ( $host ) {
				renderChoice( $host, message, choices, resolve, null );
				return;
			}
			openContentDialog(
				state,
				{ title: title, size: 'medium' },
				function ( dialog ) {
					renderChoice(
						dialog.$body,
						message,
						choices,
						resolve,
						function () {
							dialog.close();
						}
					);
				}
			);
		} );
	}

	function entityLabel( kind, values ) {
		if ( kind === 'collective' ) {
			return String( values.label || '' );
		}
		return [ values.givenName, values.familyName ].filter( Boolean ).join( ' ' );
	}

	function attemptCreate( state, kind, values, qid, extra, $choiceHost ) {
		var params = $.extend( {
			action: 'addsemanticentity',
			kind: kind,
			format: 'json'
		}, extra || {} );
		if ( qid ) {
			params.qid = qid;
		}
		Object.keys( values ).forEach( function ( key ) {
			if ( values[ key ] !== '' ) {
				params[ key ] = values[ key ];
			}
		} );
		if ( !params.labelLanguage ) {
			params.labelLanguage = 'en';
		}
		return state.api.postWithToken( 'csrf', params ).then( function ( response ) {
			var semantic = response && response.semantic ? response.semantic : {};
			if ( semantic.entityId ) {
				return { qid: semantic.entityId, label: entityLabel( kind, values ) };
			}
			if ( semantic.duplicate === '1' ) {
				return confirmChoice(
					state,
					mw.msg( 'embeddablecontent-authorsearch-confirm' ),
					mw.msg(
						'embeddablecontent-authorsearch-duplicate',
						semantic.duplicateLabel || '',
						semantic.duplicateOf || ''
					),
					[
						{
							value: 'use',
							label: mw.msg( 'embeddablecontent-authorsearch-use-existing' ),
							flags: [ 'primary', 'progressive' ]
						},
						{
							value: 'create',
							label: mw.msg( 'embeddablecontent-authorsearch-create-anyway' )
						}
					],
					$choiceHost
				).then( function ( choice ) {
					if ( choice === 'use' ) {
						return { qid: semantic.duplicateOf, label: semantic.duplicateLabel || semantic.duplicateOf };
					}
					if ( choice === 'create' ) {
						return attemptCreate(
							state, kind, values, qid,
							$.extend( {}, extra, { confirmDuplicate: 1 } ),
							$choiceHost
						);
					}
					return null;
				} );
			}
			if ( semantic.collision === '1' ) {
				return confirmChoice(
					state,
					mw.msg( 'embeddablecontent-authorsearch-confirm' ),
					mw.msg(
						'embeddablecontent-authorsearch-collision',
						semantic.collisionLabel || '',
						semantic.collisionOf || ''
					),
					[
						{
							value: 'use',
							label: mw.msg( 'embeddablecontent-authorsearch-use-existing' ),
							flags: [ 'primary', 'progressive' ]
						},
						{
							value: 'duplicate',
							label: mw.msg( 'embeddablecontent-authorsearch-create-duplicate' )
						}
					],
					$choiceHost
				).then( function ( choice ) {
					if ( choice === 'use' ) {
						return { qid: semantic.collisionOf, label: semantic.collisionLabel || semantic.collisionOf };
					}
					if ( choice === 'duplicate' ) {
						return attemptCreate(
							state, kind, values, qid,
							$.extend( {}, extra, { collisionAction: 'duplicate' } ),
							$choiceHost
						);
					}
					return null;
				} );
			}
			throw new Error( 'The entity was not created.' );
		} );
	}

	function createImmediately( state, kind, values, qid, $choiceHost ) {
		return attemptCreate( state, kind, values, qid, {}, $choiceHost ).then( function ( result ) {
			if ( !result || !result.qid ) {
				throw new Error( 'Cancelled.' );
			}
			return result;
		} );
	}

	function openCreateDialog( state, row, onResult, candidate ) {
		var editing = !!candidate;
		var prefill = {};
		if ( candidate ) {
			prefill = row.kind === 'collective'
				? { label: candidate.label, description: candidate.description }
				: splitCandidateName( candidate );
		} else if ( row.kind === 'collective' ) {
			prefill = { label: row.familyName || row.original };
		} else {
			prefill = { givenName: row.givenName, familyName: row.familyName };
		}
		var details = row.kind === 'collective' ? collectiveFields( prefill ) : personFields( prefill );
		openContentDialog(
			state,
			{
				title: mw.msg(
					editing
						? ( row.kind === 'collective'
							? 'embeddablecontent-authorsearch-update-collective-title'
							: 'embeddablecontent-authorsearch-update-title' )
						: ( row.kind === 'collective'
							? 'embeddablecontent-authorsearch-create-collective-title'
							: 'embeddablecontent-authorsearch-create-title' ),
					row.original
				),
				size: 'large',
				classes: [ 'wb-author-dialog' ]
			},
			function ( dialog ) {
				var $body = $( '<div>' );
				if ( editing ) {
					$body.append( $( '<p>' ).addClass( 'wb-author-hint' )
						.text( mw.msg( 'embeddablecontent-authorsearch-keepblank' ) ) );
				}
				$body.append( details.$body );
				var status = $( '<p>' ).addClass( 'wb-author-hint' );
				$body.append( status );
				// The duplicate/collision choice renders here, inside the
				// open dialog (a second OOUI window cannot be opened on top).
				var $choice = $( '<div>' ).addClass( 'wb-author-choice' );
				$body.append( $choice );
				var submit = new OO.ui.ButtonWidget( {
					label: mw.msg(
						editing
							? 'embeddablecontent-authorsearch-save'
							: ( row.kind === 'collective'
								? 'embeddablecontent-authorsearch-create-collective'
								: 'embeddablecontent-authorsearch-create' )
					),
					flags: [ 'primary', 'progressive' ]
				} );
				var cancel = new OO.ui.ButtonWidget( { label: mw.msg( 'embeddablecontent-authorsearch-cancel' ) } );
				cancel.on( 'click', function () {
					dialog.close();
				} );
				submit.on( 'click', function () {
					var values = details.values();
					if ( !entityLabel( row.kind, values ) ) {
						status.text( mw.msg( 'embeddablecontent-authorsearch-name-required' ) );
						return;
					}
					submit.setDisabled( true );
					status.text( mw.msg( 'embeddablecontent-authorsearch-created-immediately' ) );
					createImmediately(
						state, row.kind, values,
						editing ? candidate.qid : null,
						$choice
					).then( function ( result ) {
						dialog.close();
						onResult( { action: 'reuse', qid: result.qid, label: result.label, kind: row.kind } );
					} ).catch( function ( error ) {
						submit.setDisabled( false );
						// Cancelling the duplicate/collision choice is not an
						// error: leave the form usable and clear the status.
						if ( error && error.message === 'Cancelled.' ) {
							status.text( '' );
							return;
						}
						status.text( mw.msg(
							'embeddablecontent-authorsearch-create-failed',
							error && error.message ? error.message : String( error || '' )
						) );
					} );
				} );
				// The help line sits directly above the Create button.
				$body.append( $( '<p>' ).addClass( 'wb-author-hint' )
					.text( mw.msg( 'embeddablecontent-authorsearch-created-immediately' ) ) );
				$body.append( actionRow( [ submit, cancel ] ) );
				return $body;
			}
		);
	}

	function openCandidateDialog( state, row, onResult ) {
		openContentDialog(
			state,
			{
				title: mw.msg( 'embeddablecontent-authorsearch-dialog-title', row.original ),
				size: 'large',
				classes: [ 'wb-author-dialog' ]
			},
			function ( dialog ) {
				var $body = $( '<div>' );
				$body.append( $( '<p>' ).text( mw.msg( 'embeddablecontent-authorsearch-choose' ) ) );
				var items = row.candidates.map( function ( candidate ) {
					return new OO.ui.RadioOptionWidget( {
						data: candidate.qid,
						label: candidateLabel( candidate )
					} );
				} );
				items.push( new OO.ui.RadioOptionWidget( {
					data: '__new__',
					label: row.kind === 'collective'
						? mw.msg( 'embeddablecontent-authorsearch-create-collective' )
						: mw.msg( 'embeddablecontent-authorsearch-create' )
				} ) );
				var radio = new OO.ui.RadioSelectWidget( { items: items } );
				$body.append( radio.$element );
				var confirm = new OO.ui.ButtonWidget( {
					label: mw.msg( 'embeddablecontent-authorsearch-confirm' ),
					flags: [ 'primary', 'progressive' ],
					disabled: true
				} );
				var edit = new OO.ui.ButtonWidget( {
					label: mw.msg( 'embeddablecontent-authorsearch-edit' ),
					disabled: true
				} );
				var cancel = new OO.ui.ButtonWidget( { label: mw.msg( 'embeddablecontent-authorsearch-cancel' ) } );
				var selected = null;
				radio.on( 'select', function ( item ) {
					selected = item;
					confirm.setDisabled( !item );
					edit.setDisabled( !item || item.getData() === '__new__' );
				} );
				function candidateFor( data ) {
					return row.candidates.find( function ( candidate ) {
						return candidate.qid === data;
					} ) || null;
				}
				confirm.on( 'click', function () {
					if ( !selected ) {
						return;
					}
					if ( selected.getData() === '__new__' ) {
						dialog.close();
						openCreateDialog( state, row, onResult, null );
						return;
					}
					var candidate = candidateFor( selected.getData() );
					dialog.close();
					if ( candidate ) {
						onResult( { action: 'reuse', qid: candidate.qid, label: candidate.label, kind: row.kind } );
					}
				} );
				edit.on( 'click', function () {
					if ( !selected || selected.getData() === '__new__' ) {
						return;
					}
					var candidate = candidateFor( selected.getData() );
					dialog.close();
					if ( candidate ) {
						openCreateDialog( state, row, onResult, candidate );
					}
				} );
				cancel.on( 'click', function () { dialog.close(); } );
				$body.append( actionRow( [ confirm, edit, cancel ] ) );
				return $body;
			}
		);
	}

	function open( state, row, onResult ) {
		if ( row.candidates && row.candidates.length ) {
			openCandidateDialog( state, row, onResult );
		} else {
			openCreateDialog( state, row, onResult, null );
		}
	}

	ns.dialogs.open = open;
	ns.dialogs.createImmediately = createImmediately;
}() );
