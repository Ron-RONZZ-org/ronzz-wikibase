<?php

declare( strict_types = 1 );

namespace EmbeddableContent\Spec;

use DataValues\QuantityValue;
use DataValues\StringValue;
use DataValues\TimeValue;
use EmbeddableContent\Content\FragmentSanitizer;
use EmbeddableContent\Duration;
use EmbeddableContent\Fetch\ProviderResult;
use EmbeddableContent\Fetch\WorkRecord;
use EmbeddableContent\Fetch\YouTubeProvider;
use MediaWiki\Title\Title;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\NumericPropertyId;
use Wikibase\Repo\WikibaseRepo;

/**
 * Special:AddSource — create a work item (book / scholarly article / website /
 * song / film / video / YouTube channel / video, plus the child classes
 * bookExcerpt / webpage / YouTube video) from an external authority.
 *
 * Class-first flow (issue #7, follow-up): the page opens on a class picker
 * (Special:AddSource), then routes to the class-scoped search step
 * (Special:AddSource/<classKey>), selection and review — each class carrying
 * its own adapted search and verification fields. Child classes additionally
 * require an existing parent-class item, picked via an entity combobox and
 * linked automatically with a `part of` statement. Every class requires at
 * least one author (entity) at record creation.
 *
 * @license GPL-2.0-or-later
 */
class SpecialAddSource extends SpecialAddExternalEntity {

	/**
	 * Classes with no external authority: the picker sends them straight to
	 * the adapted manual form (no search step).
	 */
	private const MANUAL_ONLY_CLASSES = [ 'website', 'webpage', 'bookExcerpt' ];

	/**
	 * Classes whose FIRST page is a URL entry (website/webpage): the
	 * metadata of the entered URL is fetched and prefills the manual form.
	 */
	private const URL_ENTRY_CLASSES = [ 'website', 'webpage' ];

	/** @var string|null class key selected for the current request */
	protected ?string $currentClassKey = null;

	/** @var \EmbeddableContent\Fetch\PageMetadataFetcher|null lazily built */
	private ?\EmbeddableContent\Fetch\PageMetadataFetcher $metadataFetcher = null;

	public function __construct(
		\EmbeddableContent\EmbeddableContentConfig $config,
		\EmbeddableContent\Fetch\ProviderClient $client,
		string $pageName = 'AddSource'
	) {
		parent::__construct( $pageName, $config, $client );
	}

	protected function kindKey(): string {
		return 'source';
	}

	/**
	 * Class-scoped search title: "Search for book … from an external
	 * authority" — $1 is ONE of the class labels (book / scholarly article /
	 * song / film), so the user always knows which flow they are in.
	 */
	protected function searchStepTitleMessage(): \Message {
		return $this->msg(
			'embeddablecontent-source-title',
			$this->msg( 'embeddablecontent-source-class-' . $this->currentClassKey )->text()
		);
	}

	/**
	 * Manual-form autofill from the search inputs (issue #35): title/isbn/doi
	 * pass through via the base (same field names); the search's `author`
	 * field maps to the manual `authors` field — in entity mode (Q-ids) as-is;
	 * as a free-text NAME it is fuzzy-matched to an existing agent item and
	 * prefilled with a confirmation banner (the manual authors field requires
	 * item ids, so a plain name would fail validation — the autofill-confirm
	 * flow turns the typed name into a confirmed entity). The URL-first
	 * flow's fetched intro/keywords ride along for the content review step.
	 *
	 * @param array<string,mixed> $search
	 * @return array<string,mixed>
	 */
	protected function autofillRecord( array $search ): array {
		$out = parent::autofillRecord( $search );
		$author = trim( (string)( $search['author'] ?? '' ) );
		if ( $author !== '' ) {
			if ( ( $search['authorMode'] ?? '' ) === 'entity' ) {
				$out['authors'] = $author;
			} else {
				$resolved = $this->resolveEntityField( $author, array_values( $this->config->agentClasses() ) );
				if ( $resolved !== null ) {
					$out['authors'] = $resolved['id'];
					$out['authorsConfirm'] = [
						'fetched' => $author,
						'label' => $resolved['label'],
						'id' => $resolved['id'],
					];
				}
			}
		}
		foreach ( [ 'intro', 'keywords' ] as $key ) {
			if ( !empty( $search[$key] ) ) {
				$out[$key] = (string)$search[$key];
			}
		}
		// Webpage parent inference (URL-first flow): the structured
		// banner/hint payloads ride along with the fetched urlmeta — they are
		// arrays, so the base autofillRecord (string values only) skips them.
		foreach ( [ 'parentConfirm', 'parentUnresolved' ] as $key ) {
			if ( isset( $search[$key] ) && is_array( $search[$key] ) ) {
				$out[$key] = $search[$key];
			}
		}
		return $out;
	}

	// ------------------------------------------------------------- URL entry
	// website/webpage first page: enter a URL, the metadata is fetched and
	// prefills the manual form (the contributor corrects everything there).

	private function executeUrlEntry(): void {
		$this->getOutput()->setPageTitle(
			$this->msg(
				'embeddablecontent-source-url-title',
				$this->msg( 'embeddablecontent-source-class-' . $this->currentClassKey )->text()
			)->text()
		);
		if ( $this->currentClassKey === 'website' ) {
			$this->getOutput()->addHTML(
				\MediaWiki\Html\Html::warningBox( $this->msg( 'embeddablecontent-source-website-explanation' )->parse() )
			);
		}
		$form = \MediaWiki\HTMLForm\HTMLForm::factory( 'ooui', [
			'url' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-source-field-url',
				'required' => true,
				'maxlength' => 500,
				'placeholder' => 'https://…',
				'help-message' => 'embeddablecontent-source-url-help',
			],
		], $this->getContext() );
		$form->setTitle( $this->stepTitle() )
			->setSubmitTextMsg( 'embeddablecontent-source-url-submit' )
			->setSubmitCallback( [ $this, 'onUrlEntrySubmit' ] )
			->setSubmitID( 'wb-ext-add-url' )
			->setWrapperLegendMsg( 'embeddablecontent-source-url-legend' );
		$form->show();
		$this->getOutput()->addHTML( $this->manualFallbackHtml() );
	}

	/**
	 * URL-entry submit: SSRF-guarded fetch of the entered URL, metadata
	 * stored in the session under a token, redirect to the (prefilled)
	 * manual form. A website URL is collapsed to its site root first.
	 *
	 * @param array<string,mixed> $data
	 * @return bool|string
	 */
	public function onUrlEntrySubmit( array $data ) {
		$loginError = $this->loginRequiredError();
		if ( $loginError !== null ) {
			return $loginError;
		}
		$raw = trim( (string)( $data['url'] ?? '' ) );
		$url = \EmbeddableContent\Fetch\SsrfGuard::validate( $raw );
		if ( $url === null ) {
			return $this->msg( 'embeddablecontent-source-url-error' )->text();
		}
		if ( $this->currentClassKey === 'website' ) {
			// A website is the SITE, not a page: collapse the entered URL to
			// its root (https://www.bbc.co.uk/article1 → https://www.bbc.co.uk).
			$url = \EmbeddableContent\Fetch\SsrfGuard::siteRoot( $url );
		}
		$this->metadataFetcher ??= new \EmbeddableContent\Fetch\PageMetadataFetcher();
		$fetched = $this->metadataFetcher->fetch( $url );

		$token = \MWCryptRand::generateHex( 16 );
		$urlMeta = [ 'url' => $url ];
		if ( $fetched !== null ) {
			$urlMeta += [
				'title' => $fetched->title,
				'description' => $fetched->description,
				'intro' => $fetched->intro,
				'keywords' => $fetched->keywords,
			];
		}
		// webpage → website parent inference: the site root of the entered
		// page URL is matched against existing website-class items (see
		// inferWebpageParent). A match stores the parent id + a
		// confirmation-banner payload for the manual form; no match stores
		// the "create the website first" hint. Runs even when the PAGE
		// fetch failed (404/slow page — example.org 404s every non-root
		// path): the inference only needs the SITE ROOT fetch, which is
		// independent of the page itself.
		if ( $this->currentClassKey === 'webpage' ) {
			$this->inferWebpageParent( $url, $urlMeta );
		}
		$this->getRequest()->getSession()->set( self::SESSION_PREFIX . $token . ':urlmeta', $urlMeta );
		$this->getOutput()->redirect( $this->stepTitle( 'manual' )->getFullURL( [ 'token' => $token ] ) );
		return true;
	}

	/**
	 * Webpage → website parent inference (the child-class requirement):
	 * the site root of the entered webpage URL is matched against existing
	 * website-class items in two steps:
	 *
	 *  1. **Normalized-host match** (this follow-up): the root URL's host is
	 *     compared — via WDQS — against the URL statements of website-class
	 *     items. An exact host match AUTO-ASSIGNS the parent silently (no
	 *     confirmation banner): deterministic, independent of any metadata
	 *     fetch (works even when the site blocks crawling). The user can
	 *     still correct the prefilled combobox before submit.
	 *  2. **Site-name match** (round 3, the fallback): the site root's
	 *     fetched title is matched against website items — exact first,
	 *     then the fuzzy EntityLabelMatcher — with the standard
	 *     confirmation banner; no match stores the "add the website first"
	 *     hint. Used when WDQS is unavailable/stale (a website created
	 *     minutes ago on production) or the host matches nothing.
	 *
	 * Best-effort throughout: an unfetchable site root or an unreachable
	 * WDQS simply skips that step, never failing the URL-entry flow.
	 *
	 * @param array<string,mixed> $urlMeta
	 */
	private function inferWebpageParent( string $url, array &$urlMeta ): void {
		$websiteClassId = $this->config->sourceClasses()['website'] ?? null;
		if ( $websiteClassId === null ) {
			return;
		}
		$root = \EmbeddableContent\Fetch\SsrfGuard::siteRoot( $url );

		// 1) Normalized-host match → silent auto-assign (no banner).
		$byHost = $this->websiteItemByRootHost( $websiteClassId, $root );
		if ( $byHost !== null ) {
			$urlMeta['parent'] = $byHost['id'];
			return;
		}

		// 2) Site-name inference (round 3): banner / "add the website
		//    first" hint. Runs even when the PAGE fetch failed (404/slow
		//    page — example.org 404s every non-root path): the inference
		//    only needs the SITE ROOT fetch, which is independent.
		$siteMeta = $this->metadataFetcher->fetch( $root );
		$siteName = $siteMeta !== null ? trim( (string)$siteMeta->title ) : '';
		if ( $siteName === '' ) {
			return;
		}
		$resolved = $this->resolveEntityField( $siteName, [ $websiteClassId ] );
		// The exact-label branch of resolveEntityField does NOT filter by
		// class (and can return a stale term-store hit for a deleted item) —
		// re-check the class here; on failure, fall through to the fuzzy
		// matcher, which skips deleted items and filters by class itself.
		if ( $resolved !== null ) {
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $resolved['id'] ) );
			if ( !$item instanceof Item || !$this->itemHasClass( $item, [ $websiteClassId ] ) ) {
				$match = ( new \EmbeddableContent\EntityLabelMatcher( null, $this->config->instanceOfPropertyId() ) )
					->findBestMatch( $siteName, [ $websiteClassId ] );
				$resolved = $match !== null
					? [ 'id' => $match['itemId'], 'label' => $match['label'], 'exact' => false ]
					: null;
			}
		}
		if ( $resolved !== null ) {
			$urlMeta['parent'] = $resolved['id'];
			$urlMeta['parentConfirm'] = [
				'fetched' => $siteName,
				'label' => $resolved['label'],
				'id' => $resolved['id'],
			];
		} else {
			$urlMeta['parentUnresolved'] = [
				'root' => $root,
				'siteName' => $siteName,
			];
		}
	}

	/**
	 * Normalized-host match against website-class items' URL statements via
	 * WDQS: one SPARQL query for the (website-class × URL-statement) rows,
	 * host-compared in PHP (SPARQL string matching would not normalize).
	 *
	 * @return array{id:string,label:string}|null the matching website item,
	 *  or null when no URL statement host-matches, the endpoint is
	 *  unavailable, or the instance predates the sparqlUrl config
	 */
	private function websiteItemByRootHost( string $websiteClassId, string $root ): ?array {
		try {
			$endpoint = $this->config->sparqlUrl();
			$urlProp = $this->config->sourcePropertyIds()['url'] ?? null;
			if ( $endpoint === null || $urlProp === null ) {
				return null;
			}
			// The instance does not pre-register wd:/wdt: prefixes — declare
			// them from the entity URI namespace (the seed's own SPARQL
			// check does the same). Derive it the way Wikibase's default
			// entitySources does ($wgServer . '/entity/') — it is what WDQS
			// indexed; there is no top-level 'baseUri' setting.
			$server = $GLOBALS['wgServer'] ?? '';
			if ( !is_string( $server ) || $server === '' ) {
				return null;
			}
			$wd = rtrim( $server, '/' ) . '/entity/';
			$wdt = str_replace( '/entity/', '/prop/direct/', $wd );
			$instanceOf = $this->config->instanceOfPropertyId();
			$query = "PREFIX wd: <{$wd}> PREFIX wdt: <{$wdt}>\n"
				. "SELECT ?item ?label ?url WHERE {\n"
				. "  ?item wdt:{$instanceOf} wd:{$websiteClassId} ; wdt:{$urlProp} ?url .\n"
				. "  OPTIONAL { ?item rdfs:label ?label FILTER(LANG(?label) = \"en\") }\n"
				. "} LIMIT 500";

			$rows = $this->sparqlQuery( $endpoint, $query );
			if ( $rows === null ) {
				return null;
			}
			return \EmbeddableContent\Fetch\SiteRootMatcher::findByHost( $rows, $root );
		} catch ( \Throwable $e ) {
			// The host match is an enhancement: any failure (config shape,
			// service wiring) degrades to the site-name inference, never a
			// 500 on the URL-entry flow.
			return null;
		}
	}

	/**
	 * One read-only SPARQL query against the configured WDQS endpoint.
	 * Returns the decoded `results.bindings` rows, or null on any failure
	 * (endpoint down, malformed response, timeout) — the caller degrades
	 * to the site-name inference, never fatal.
	 *
	 * @return array<int,array<string,array{type:string,value:string}>|array<string,mixed>>|null
	 */
	private function sparqlQuery( string $endpoint, string $query ): ?array {
		try {
			$http = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory();
			$request = $http->create(
				$endpoint,
				[ 'method' => 'POST', 'postData' => [ 'query' => $query ], 'timeout' => 10 ],
				__METHOD__
			);
			$request->setHeader( 'Accept', 'application/sparql-results+json' );
			if ( !$request->execute()->isOK() ) {
				return null;
			}
			$decoded = json_decode( $request->getContent(), true );
			if ( !is_array( $decoded ) || !isset( $decoded['results']['bindings'] ) ) {
				return null;
			}
			$rows = $decoded['results']['bindings'];
			return is_array( $rows ) ? $rows : null;
		} catch ( \Throwable $e ) {
			// WDQS unreachable: the host match is an enhancement, never a
			// failure — the site-name inference takes over.
			return null;
		}
	}

	protected function classUrlPrefix(): string {
		return $this->currentClassKey ?? '';
	}

	/**
	 * Class-first dispatch: / → class picker, /<classKey> → class-scoped
	 * search (or manual for manual-only classes), /<classKey>/<token>,
	 * /<classKey>/<token>/review/<i>, /<classKey>/manual.
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->getOutput()->enableOOUI();
		$this->getOutput()->addModules( 'ext.embeddableContent.entitysuggest' );

		$parts = explode( '/', trim( (string)$subPage ) );
		$first = $parts[0] ?? '';

		if ( $first === '' || !$this->isSourceClassKey( $first ) ) {
			// Root, or an unknown subpage (e.g. a stale pre-rework token).
			$this->executeClassPicker();
			return;
		}

		$this->currentClassKey = $first;
		$second = $parts[1] ?? '';
		if ( $second === '' ) {
			if ( in_array( $first, self::URL_ENTRY_CLASSES, true ) ) {
				// website/webpage: the first page is the URL entry — the
				// metadata of the entered URL autofills the manual form.
				$this->executeUrlEntry();
				return;
			}
			if ( in_array( $first, self::MANUAL_ONLY_CLASSES, true ) ) {
				$this->getOutput()->redirect( $this->stepTitle( 'manual' )->getFullURL() );
				return;
			}
			$this->executeSearch();
			return;
		}
		if ( $second === 'manual' ) {
			if ( ( $parts[2] ?? '' ) === 'content' ) {
				$this->executeManualContent();
				return;
			}
			$this->executeManual();
			return;
		}
		if ( $second === 'complete' && ( $parts[2] ?? '' ) !== '' ) {
			// Finalize step for a just-created Source: page (base class).
			$this->executeComplete( $parts[2] );
			return;
		}
		if ( ( $parts[2] ?? '' ) === 'review' && ( $parts[3] ?? '' ) !== '' ) {
			if ( ( $parts[4] ?? '' ) === 'content' ) {
				$this->executeContent( $second, (int)$parts[3] );
				return;
			}
			$this->executeReview( $second, (int)$parts[3] );
			return;
		}
		$this->executeSelection( $second );
	}

	// ------------------------------------------------------------- class picker

	private function executeClassPicker(): void {
		$this->getOutput()->setPageTitle( $this->msg( 'embeddablecontent-source-pick-title' )->text() );

		// Plain class options — no "(part of …)" suffix (the parent relation
		// is picked on the child-class form itself), no redundant field
		// label: the legend already asks the question.
		$options = [];
		foreach ( $this->config->sourceClasses() as $key => $_ ) {
			$options[$this->msg( 'embeddablecontent-source-class-' . $key )->text()] = $key;
		}

		$form = \MediaWiki\HTMLForm\HTMLForm::factory( 'ooui', [
			'class' => [
				'type' => 'radio',
				'options' => $options,
				'required' => true,
			],
		], $this->getContext() );
		$form->setTitle( $this->getPageTitle() )
			->setSubmitTextMsg( 'embeddablecontent-source-pick-continue' )
			->setSubmitCallback( [ $this, 'onClassPickerSubmit' ] )
			->setSubmitID( 'wb-ext-add-pick-class' )
			->setWrapperLegendMsg( 'embeddablecontent-source-pick-legend' );
		$form->show();
	}

	/**
	 * @param array<string,mixed> $data
	 * @return bool|string
	 */
	public function onClassPickerSubmit( array $data ) {
		$classKey = (string)( $data['class'] ?? '' );
		if ( !$this->isSourceClassKey( $classKey ) ) {
			return $this->msg( 'embeddablecontent-extselect-classrequired' )->text();
		}
		// Manual-only classes route to their first step via execute()
		// (website/webpage: URL entry; bookExcerpt: manual form).
		$this->getOutput()->redirect( $this->getPageTitle( $classKey )->getFullURL() );
		return true;
	}

	private function isSourceClassKey( string $key ): bool {
		return isset( $this->config->sourceClasses()[$key] );
	}

	// ------------------------------------------------------------- step 1 (class-scoped)

	protected function buildSearchFields(): array {
		switch ( $this->currentClassKey ) {
			case 'book':
				return $this->titleAuthorFields() + [ 'isbn' => [
					'type' => 'text',
					'label-message' => 'embeddablecontent-extsearch-isbn',
					'required' => false,
					'maxlength' => 17,
					'placeholder' => '978-0-00-000000-0',
				] ];
			case 'scholarlyArticle':
				return $this->titleAuthorFields() + [ 'doi' => [
					'type' => 'text',
					'label-message' => 'embeddablecontent-extsearch-doi',
					'required' => false,
					'maxlength' => 250,
					'placeholder' => '10.1000/xxxx',
				] ];
			case 'film':
			case 'song':
			case 'video':
				return $this->titleAuthorFields();
			case 'youtubeChannel':
			case 'youtubeVideo':
				return [ 'query' => [
					'type' => 'text',
					'label-message' => 'embeddablecontent-extsearch-youtube',
					'help-message' => 'embeddablecontent-extsearch-youtube-help',
					'required' => true,
					'maxlength' => 500,
				] ];
			default:
				return [];
		}
	}

	/** @return array<string,mixed> */
	private function titleAuthorFields(): array {
		return [
			'title' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-title',
				'required' => false,
				'maxlength' => 250,
			],
			'author' => [
				'type' => 'text',
				'label-message' => 'embeddablecontent-extsearch-author',
				'required' => false,
				'maxlength' => 250,
				'help-message' => 'embeddablecontent-extsearch-author-help',
			],
			'authorMode' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-extsearch-author-mode',
				'options-messages' => [
					'embeddablecontent-extsearch-author-mode-text' => 'text',
					'embeddablecontent-extsearch-author-mode-entity' => 'entity',
				],
				'default' => 'text',
			],
		];
	}

	protected function search( array $data ): ProviderResult {
		switch ( $this->currentClassKey ) {
			case 'book':
				$isbn = trim( (string)( $data['isbn'] ?? '' ) );
				if ( $isbn !== '' ) {
					return $this->client->byIsbn( $isbn );
				}
				return $this->searchByTitleAuthor( $data );
			case 'scholarlyArticle':
				$doi = trim( (string)( $data['doi'] ?? '' ) );
				if ( $doi !== '' ) {
					return $this->client->byDoi( $doi );
				}
				return $this->searchByTitleAuthor( $data );
			case 'film':
			case 'song':
			case 'video':
				return $this->searchByTitleAuthor( $data );
			case 'youtubeChannel':
			case 'youtubeVideo':
				return $this->searchYouTube( $data );
			default:
				return new ProviderResult( [], [ $this->msg( 'embeddablecontent-extsearch-nohits' )->text() ] );
		}
	}

	private function searchByTitleAuthor( array $data ): ProviderResult {
		$title = trim( (string)( $data['title'] ?? '' ) );
		$author = trim( (string)( $data['author'] ?? '' ) );
		if ( $author !== '' ) {
			if ( ( $data['authorMode'] ?? 'text' ) === 'entity' ) {
				$qids = array_values( array_filter(
					ItemIdList::split( $author ),
					static fn ( string $id ): bool => preg_match( '/^Q[1-9]\d*$/i', $id ) === 1
				) );
				if ( $qids === [] ) {
					return new ProviderResult( [], [ 'Entity-mode author search needs Wikidata Q-ids (e.g. Q42, Q179)' ] );
				}
				return $this->client->searchWorksByAuthorEntities( $qids, $title );
			}
			return $this->client->searchWorksByAuthorName( $author, $title );
		}
		if ( $title === '' ) {
			return new ProviderResult( [], [ 'No title or author given' ] );
		}
		return $this->client->searchWorks( $title );
	}

	/**
	 * YouTube search: a URL resolves EXACTLY (no match → localized "no match
	 * for the provided URL"), a name runs the capped search.list.
	 */
	private function searchYouTube( array $data ): ProviderResult {
		$query = trim( (string)( $data['query'] ?? '' ) );
		if ( $query === '' ) {
			return new ProviderResult( [], [ $this->msg( 'embeddablecontent-extsearch-nohits' )->text() ] );
		}
		if ( YouTubeProvider::isVideoUrl( $query ) || YouTubeProvider::isChannelUrl( $query ) ) {
			$result = $this->client->byYouTubeUrl( $query );
			if ( $result->records === [] && $result->warnings === [] ) {
				return new ProviderResult( [], [ $this->msg( 'embeddablecontent-extsearch-youtube-urlnomatch' )->text() ] );
			}
			return $result;
		}
		return $this->currentClassKey === 'youtubeChannel'
			? $this->client->searchYouTubeChannels( $query )
			: $this->client->searchYouTubeVideos( $query );
	}

	protected function candidateOptions( array $records ): array {
		return $this->candidateOptionLabels( $records );
	}

	protected function primaryLabel( array $record ): string {
		$title = (string)( $record['title'] ?? '' );
		// Disambiguation suffix: the review default carries it, and the
		// creation-time append covers a title typed from blank (idempotent —
		// never double-suffixed). Special:UpdateSource overrides
		// applyLabelSuffix() to keep an existing label as-is on update.
		return $this->applyLabelSuffix() ? $this->disambiguatedTitle( $title ) : $title;
	}

	/**
	 * Whether the class disambiguation suffix (" (Book)", " (Website)", …)
	 * applies to the label. True on the AddSource flows; Special:UpdateSource
	 * overrides to false (an update shows the item's existing label as-is —
	 * re-adding the suffix would rename every pre-convention item on its
	 * first update).
	 */
	protected function applyLabelSuffix(): bool {
		return true;
	}

	/**
	 * Appends the source class label in parentheses for disambiguation, e.g.
	 * "The Hobbit" → "The Hobbit (Book)", "Example Domain" → "Example Domain
	 * (Website)". The suffix is the ENGLISH class label (labels are stored as
	 * the item's `en` term). Idempotent: a label already ending with the
	 * suffix (case-insensitive) is returned unchanged. An empty title stays
	 * empty (the manual-from-blank form lets the user type it).
	 */
	protected function disambiguatedTitle( string $title ): string {
		$suffix = $this->sourceLabelSuffix();
		if ( $suffix === '' || trim( $title ) === '' ) {
			return $title;
		}
		$trimmed = rtrim( $title );
		$needle = strtolower( $suffix );
		if ( substr( strtolower( $trimmed ), -strlen( $needle ) ) === $needle ) {
			return $title;
		}
		return $trimmed . $suffix;
	}

	/**
	 * The " (Class label)" disambiguation suffix for the current class, or ''
	 * when no class is selected (the class-picker root page).
	 */
	private function sourceLabelSuffix(): string {
		if ( $this->currentClassKey === null || !isset( $this->config->sourceClasses()[$this->currentClassKey] ) ) {
			return '';
		}
		$label = $this->msg( 'embeddablecontent-source-class-' . $this->currentClassKey )
			->inLanguage( 'en' )->text();
		return $label === '' ? '' : ' (' . $label . ')';
	}

	// ------------------------------------------------------------- page content
	// Auto-fetched page content (best-effort, never fatal): abstracts +
	// keywords for scholarly articles (OpenAlex, Crossref fallback),
	// Wikipedia lead intros and sections (Plot/Lyrics) for book/song/film.
	// The content is reviewed on its own step (/review/<i>/content) before
	// it is written to the Source: page.

	/** @var \EmbeddableContent\Fetch\WikipediaContentProvider|null lazily built */
	private ?\EmbeddableContent\Fetch\WikipediaContentProvider $wikipedia = null;

	private function wikipediaContent(): \EmbeddableContent\Fetch\WikipediaContentProvider {
		$this->wikipedia ??= \MediaWiki\MediaWikiServices::getInstance()
			->get( 'EmbeddableContent.WikipediaContent' );
		return $this->wikipedia;
	}

	protected function harvestContent( array $record ): array {
		switch ( $this->currentClassKey ) {
			case 'scholarlyArticle':
				return $this->harvestArticleContent( $record );
			case 'book':
			case 'film':
			case 'song':
			case 'youtubeChannel':
				return $this->harvestWikipediaContent( $record );
			default:
				return $record;
		}
	}

	/**
	 * Scholarly-article abstract + keywords: OpenAlex by DOI (inverted-index
	 * reconstruction), Crossref as the direct-text fallback; by bare OpenAlex
	 * id when no DOI was harvested.
	 *
	 * @param array<string,mixed> $record
	 */
	private function harvestArticleContent( array $record ): array {
		$doi = trim( (string)( $record['doi'] ?? '' ) );
		$openalexId = trim( (string)( $record['openalexId'] ?? '' ) );
		if ( $doi === '' && $openalexId === '' ) {
			return $record;
		}
		$data = $doi !== ''
			? $this->client->workAbstractByDoi( $doi )
			: $this->client->workAbstractByOpenAlexId( $openalexId );
		if ( !empty( $data['abstract'] ) ) {
			$record['abstract'] = (string)$data['abstract'];
			$record['contentSources']['abstract'] = (string)( $data['source'] ?? 'openalex' );
		}
		if ( !empty( $data['keywords'] ) ) {
			$record['keywords'] = (string)$data['keywords'];
			$record['contentSources']['keywords'] = (string)( $data['source'] ?? 'openalex' );
		}
		return $record;
	}

	/**
	 * Wikipedia lead intro (book/song/film/youtubeChannel) + the class
	 * section (song → Lyrics, film → Plot) from the article wikitext.
	 *
	 * @param array<string,mixed> $record
	 */
	private function harvestWikipediaContent( array $record ): array {
		$title = trim( (string)( $record['enwikiTitle'] ?? '' ) );
		if ( $title === '' ) {
			return $record;
		}
		$wp = $this->wikipediaContent();
		if ( $this->currentClassKey === 'book' ) {
			$intro = $wp->intro( $title );
			if ( $intro !== null ) {
				$record['summary'] = $intro;
				$record['contentSources']['summary'] = 'wikipedia';
			}
			return $record;
		}
		$intro = $wp->intro( $title );
		if ( $intro !== null ) {
			$record['intro'] = $intro;
			$record['contentSources']['intro'] = 'wikipedia';
		}
		if ( $this->currentClassKey === 'song' ) {
			$lyrics = $wp->section( $title, [ 'Lyrics', 'Paroles' ] );
			if ( $lyrics !== null ) {
				$record['lyrics'] = $lyrics;
				$record['contentSources']['lyrics'] = 'wikipedia';
			}
		} elseif ( $this->currentClassKey === 'film' ) {
			$plot = $wp->section( $title, [ 'Plot', 'Synopsis', 'Premise' ] );
			if ( $plot !== null ) {
				$record['plot'] = $plot;
				$record['contentSources']['plot'] = 'wikipedia';
			}
		}
		return $record;
	}

	/**
	 * Content-review fields (multi-line textareas, one per fetched content
	 * key): the contributor confirms, corrects or clears each block before
	 * it lands on the Source: page.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	protected function contentFieldSpecs( array $record ): array {
		$keys = $this->contentKeysForClass();
		$fields = [];
		foreach ( $keys as $key => $messageKey ) {
			$field = [
				'type' => 'textarea',
				'rows' => 8,
				'label-message' => $messageKey,
				'default' => (string)( $record[$key] ?? '' ),
			];
			$source = $record['contentSources'][$key] ?? null;
			if ( $source !== null ) {
				$field['help'] = $this->msg( 'embeddablecontent-content-from-' . $source )->parse();
			}
			$fields[$key] = $field;
		}
		return $fields;
	}

	/** @return array<string,string> content key => label message key */
	private function contentKeysForClass(): array {
		switch ( $this->currentClassKey ) {
			case 'scholarlyArticle':
				return [ 'abstract' => 'embeddablecontent-content-field-abstract', 'keywords' => 'embeddablecontent-content-field-keywords' ];
			case 'book':
				return [ 'summary' => 'embeddablecontent-content-field-summary', 'keywords' => 'embeddablecontent-content-field-keywords' ];
			case 'song':
				return [ 'intro' => 'embeddablecontent-content-field-intro', 'lyrics' => 'embeddablecontent-content-field-lyrics' ];
			case 'film':
				return [ 'intro' => 'embeddablecontent-content-field-intro', 'plot' => 'embeddablecontent-content-field-plot' ];
			case 'webpage':
				return [ 'summary' => 'embeddablecontent-content-field-summary', 'keywords' => 'embeddablecontent-content-field-keywords' ];
			case 'website':
			case 'youtubeChannel':
				return [ 'intro' => 'embeddablecontent-content-field-intro' ];
			default:
				return [];
		}
	}

	/** Class-specific authority identifiers (per-class review/create). */
	protected function externalIdRecordMap(): array {
		switch ( $this->currentClassKey ) {
			case 'book':
				return [ 'wikidata' => 'wikidataId', 'isbn' => 'isbn' ];
			case 'scholarlyArticle':
				return [ 'wikidata' => 'wikidataId', 'doi' => 'doi', 'openalex' => 'openalexId', 'pubmed' => 'pubmedId' ];
			case 'film':
			case 'song':
			case 'video':
				return [ 'wikidata' => 'wikidataId' ];
			default:
				return [];
		}
	}

	protected function harvest( string $qid ): ProviderResult {
		return $this->client->harvestWork( $qid );
	}

	protected function reviewFieldSpecs( array $record ): array {
		$title = (string)( $record['title'] ?? '' );
		$fields = $this->labelFieldSpec(
			'title',
			'embeddablecontent-extsearch-title',
			$this->applyLabelSuffix() ? $this->disambiguatedTitle( $title ) : $title
		)
			+ $this->descriptionFieldSpec( (string)( $record['description'] ?? '' ) )
			+ $this->authorsFieldSpec( $record );

		switch ( $this->currentClassKey ) {
			case 'book':
				$fields['publisher'] = $this->publisherFieldSpec( $record );
				$fields['pages'] = $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) );
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->accessFieldSpec( $record );
				break;
			case 'scholarlyArticle':
				$fields['journal'] = $this->journalFieldSpec( $record );
				$fields['publisher'] = $this->publisherFieldSpec( $record );
				$fields['volume'] = $this->plainTextField( 'embeddablecontent-field-volume', (string)( $record['volume'] ?? '' ) );
				$fields['issue'] = $this->plainTextField( 'embeddablecontent-field-issue', (string)( $record['issue'] ?? '' ) );
				$fields['pages'] = $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) );
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->accessFieldSpec( $record );
				break;
			case 'film':
			case 'song':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->durationFieldSpec( $record );
				$fields += $this->accessFieldSpec( $record );
				break;
			case 'video':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->durationFieldSpec( $record );
				$fields += $this->urlFieldSpec( $record );
				break;
			case 'youtubeChannel':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->urlFieldSpec( $record );
				$fields['youtubeChannelId'] = $this->plainTextField(
					'embeddablecontent-source-field-youtubechannelid',
					(string)( $record['youtubeChannelId'] ?? '' ),
					64
				);
				break;
			case 'youtubeVideo':
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->durationFieldSpec( $record );
				$fields += $this->urlFieldSpec( $record );
				$fields['youtubeVideoId'] = $this->plainTextField(
					'embeddablecontent-source-field-youtubevideoid',
					(string)( $record['youtubeVideoId'] ?? '' ),
					16
				);
				break;
			case 'website':
			case 'webpage':
				$fields += $this->urlFieldSpec( $record );
				// A website is dynamic — no fixed year (the /website class);
				// a webpage can carry a publication date.
				if ( $this->currentClassKey === 'webpage' ) {
					$fields += $this->issuedYearFieldSpec( $record );
				}
				break;
			case 'bookExcerpt':
				$fields['pages'] = $this->plainTextField( 'embeddablecontent-field-pages', (string)( $record['pages'] ?? '' ) );
				$fields['volume'] = $this->plainTextField( 'embeddablecontent-field-volume', (string)( $record['volume'] ?? '' ) );
				$fields['chapters'] = $this->plainTextField( 'embeddablecontent-source-field-chapters', (string)( $record['chapters'] ?? '' ) );
				$fields += $this->issuedYearFieldSpec( $record );
				$fields += $this->accessFieldSpec( $record );
				// The description auto-generates from pages/volume + the
				// parent book when left blank; year/authors fall back to the
				// parent book's own statements (see beforeCreate).
				$fields['description']['help'] = $this->msg(
					'embeddablecontent-source-bookexcerpt-desc-help'
				)->parse();
				$parentLabel = $this->parentClassLabel();
				$sameAsParent = $this->msg(
					'embeddablecontent-source-bookexcerpt-sameparent-help',
					$parentLabel
				)->parse();
				$fields['issuedYear']['help'] = $sameAsParent;
				$fields['authors']['help'] = ( $fields['authors']['help'] ?? '' ) . ' ' . $sameAsParent;
				// Authors may be left blank: they (like the year) are
				// inferred from the parent book in beforeCreate, so the
				// HTMLForm required flag must not block the submit.
				$fields['authors']['required'] = false;
				break;
		}

		$fields += $this->externalIdFieldSpecs( $record );
		$fields += $this->parentFieldSpec( $record );
		return $fields;
	}

	/** @return array<string,mixed> */
	private function authorsFieldSpec( array $record ): array {
		$help = $this->msg( 'embeddablecontent-source-field-authors-help' )->parse();
		// Free-text author name → fuzzy-matched agent item (autofill-confirm):
		// the banner rides in the help slot next to the prefilled Q-id.
		if ( isset( $record['authorsConfirm'] ) && is_array( $record['authorsConfirm'] ) ) {
			$confirm = $record['authorsConfirm'];
			$help .= ' ' . $this->entityConfirmHtml(
				'wpauthors',
				$this->msg( 'embeddablecontent-source-field-authors' )->text(),
				(string)( $confirm['fetched'] ?? '' ),
				(string)( $confirm['label'] ?? '' ),
				(string)( $confirm['id'] ?? '' )
			);
		}
		return [ 'authors' => [
			'type' => 'combobox',
			'options' => [],
			'label-message' => 'embeddablecontent-source-field-authors',
			'cssclass' => 'wb-entity-combobox wb-entity-combobox-multi',
			'default' => (string)( $record['authors'] ?? '' ),
			'help' => $help,
			'required' => true,
		] ];
	}

	/** @return array<string,mixed> */
	private function issuedYearFieldSpec( array $record ): array {
		return [ 'issuedYear' => [
			'type' => 'text',
			'label-message' => 'embeddablecontent-field-year',
			'default' => (string)( $record['issuedYear'] ?? '' ),
			'maxlength' => 4,
		] ];
	}

	/** @return array<string,mixed> */
	private function durationFieldSpec( array $record ): array {
		$seconds = (int)( $record['durationSeconds'] ?? 0 );
		return [ 'duration' => [
			'type' => 'text',
			'label-message' => 'embeddablecontent-source-field-duration',
			'default' => $seconds > 0 ? Duration::formatSeconds( $seconds ) : '',
			'placeholder' => 'MM:SS or HH:MM:SS',
			'maxlength' => 12,
			'help' => $this->msg( 'embeddablecontent-source-field-duration-help' )->parse(),
		] ];
	}

	/** @return array<string,mixed> */
	private function urlFieldSpec( array $record ): array {
		return [ 'url' => [
			'type' => 'url',
			'label-message' => 'embeddablecontent-source-field-url',
			'default' => (string)( $record['url'] ?? '' ),
			'maxlength' => 250,
		] ];
	}

	/**
	 * Journal field (entity-only, scholarlyArticle): an entity combobox
	 * referencing an existing journal item — the container-title analogue of
	 * the publisher field. A harvested STRING container title (e.g. "Nature"
	 * from Wikidata's P1433) is resolved to a local item — exact label match
	 * or a fuzzy match (autofill-confirm: the field is prefilled with a
	 * "we think this corresponds to …" banner); otherwise the string is
	 * shown as context with a "create the item first" hint. The submitted
	 * value must be an item id.
	 *
	 * @return array<string,mixed>
	 */
	private function journalFieldSpec( array $record ): array {
		$harvested = (string)( $record['containerTitle'] ?? '' );
		$default = '';
		$help = '';
		if ( $harvested !== '' && preg_match( '/^Q[1-9]\d*$/i', $harvested ) !== 1 ) {
			$resolved = $this->resolveEntityField( $harvested );
			if ( $resolved !== null ) {
				$default = $resolved['id'];
				$help = $this->entityConfirmHtml(
					'wpjournal',
					$this->msg( 'embeddablecontent-source-field-journal' )->text(),
					$harvested,
					$resolved['label'],
					$resolved['id']
				);
			} else {
				// Plain text, HTML-escaped: the value comes from an external
				// API and must never inject markup.
				$help = htmlspecialchars(
					$this->msg( 'embeddablecontent-source-field-journal-unresolved', $harvested )->text()
				);
			}
		} elseif ( $harvested !== '' ) {
			$default = $harvested;
		}
		$field = [
			'type' => 'combobox',
			'options' => [],
			'label-message' => 'embeddablecontent-source-field-journal',
			'cssclass' => 'wb-entity-combobox',
			'default' => $default,
			'help' => $this->msg( 'embeddablecontent-source-field-journal-help' )->parse(),
		];
		if ( $help !== '' ) {
			$field['help'] .= ' ' . $help;
		}
		return $field;
	}

	/**
	 * Publisher field (entity-only, issue #35): an entity combobox
	 * referencing an existing publisher item. A harvested STRING publisher
	 * (Open Library etc.) is resolved to a local item — exact label match
	 * or a fuzzy match (autofill-confirm: the field is prefilled with a
	 * "we think this corresponds to …" banner); otherwise the string is
	 * shown as context with a "create the item first" hint (AddSoftware
	 * harvested-fact pattern) — the submitted value must be an item id.
	 *
	 * @return array<string,mixed>
	 */
	private function publisherFieldSpec( array $record ): array {
		$harvested = (string)( $record['publisher'] ?? '' );
		$default = '';
		$help = '';
		if ( $harvested !== '' && preg_match( '/^Q[1-9]\d*$/i', $harvested ) !== 1 ) {
			$resolved = $this->resolveEntityField( $harvested );
			if ( $resolved !== null ) {
				$default = $resolved['id'];
				$help = $this->entityConfirmHtml(
					'wppublisher',
					$this->msg( 'embeddablecontent-field-publisher' )->text(),
					$harvested,
					$resolved['label'],
					$resolved['id']
				);
			} else {
				// Plain text, HTML-escaped: the value comes from an external
				// API and must never inject markup.
				$help = htmlspecialchars(
					$this->msg( 'embeddablecontent-source-field-publisher-unresolved', $harvested )->text()
				);
			}
		} elseif ( $harvested !== '' ) {
			$default = $harvested;
		}
		$field = [
			'type' => 'combobox',
			'options' => [],
			'label-message' => 'embeddablecontent-field-publisher',
			'cssclass' => 'wb-entity-combobox',
			'default' => $default,
			'help' => $this->msg( 'embeddablecontent-source-field-publisher-help' )->parse(),
		];
		if ( $help !== '' ) {
			$field['help'] .= ' ' . $help;
		}
		return $field;
	}

	/**
	 * Access field group (issue #35): an `accessMode` toggle between
	 *  - url      — a non-direct access URL (landing page), no license;
	 *  - download — a direct download link, auto-fetched and saved server-side;
	 *  - file     — a local file from the browser;
	 *  - na       — not applicable (access only via archives, physical
	 *               copies, …): no access statement is written.
	 * The download/file modes expand the `license` field (entity combobox,
	 * reusing the P275-aligned license property — options from the seed's
	 * known license items, Special:Upload-style) with the copyright warning.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed>
	 */
	private function accessFieldSpec( array $record ): array {
		$mode = (string)( $record['accessMode'] ?? 'url' );
		return [
			'accessMode' => [
				'type' => 'radio',
				'label-message' => 'embeddablecontent-source-field-access-mode',
				'options-messages' => [
					'embeddablecontent-source-field-access-mode-url' => 'url',
					'embeddablecontent-source-field-access-mode-download' => 'download',
					'embeddablecontent-source-field-access-mode-file' => 'file',
					'embeddablecontent-source-field-access-mode-na' => 'na',
				],
				'default' => $mode,
				'help-message' => 'embeddablecontent-source-field-access-mode-help',
			],
			'accessUrl' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-source-field-access-url',
				'default' => (string)( $record['accessUrl'] ?? '' ),
				'maxlength' => 250,
				'hide-if' => [ '!==', 'accessMode', 'url' ],
			],
			'downloadUrl' => [
				'type' => 'url',
				'label-message' => 'embeddablecontent-source-field-access-download',
				'default' => (string)( $record['downloadUrl'] ?? '' ),
				'maxlength' => 500,
				'help-message' => 'embeddablecontent-source-field-access-download-help',
				'hide-if' => [ '!==', 'accessMode', 'download' ],
			],
			'accessFile' => [
				'type' => 'file',
				'label-message' => 'embeddablecontent-source-field-access-file',
				'hide-if' => [ '!==', 'accessMode', 'file' ],
			],
			// Required is NOT set here: the license is only mandatory in the
			// download/file modes, and HTMLForm validates required fields
			// even when hide-if hides them client-side — the requirement is
			// enforced in beforeCreate (validateAccessField) instead.
			'license' => [
				'type' => 'combobox',
				'options' => $this->config->licenseItems(),
				'label-message' => 'embeddablecontent-source-field-license',
				'cssclass' => 'wb-entity-combobox',
				'default' => (string)( $record['license'] ?? '' ),
				'help' => $this->msg( 'embeddablecontent-source-field-license-help' )->parse(),
				'hide-if' => [
					'OR',
					[ '===', 'accessMode', 'url' ],
					[ '===', 'accessMode', 'na' ],
				],
			],
		];
	}

	/**
	 * Parent-class combobox for child classes (bookExcerpt→book,
	 * youtubeVideo→youtubeChannel, webpage→website), with the
	 * "not yet imported? import it yourself" line.
	 *
	 * On the webpage URL-first flow the record may carry the site-root
	 * inference: parentConfirm (a match — the combobox is prefilled with a
	 * "we think this corresponds to …" banner) or parentUnresolved (no
	 * record — a "create the website first" hint; the field stays required).
	 *
	 * @return array<string,mixed>
	 */
	private function parentFieldSpec( array $record ): array {
		$parentKey = $this->config->sourceParents()[$this->currentClassKey] ?? null;
		if ( $parentKey === null ) {
			return [];
		}
		$parentLabel = $this->msg( 'embeddablecontent-source-class-' . $parentKey )->text();
		$help = $this->msg( 'embeddablecontent-source-parent-help', $parentLabel, $parentKey )->parse();

		if ( isset( $record['parentConfirm'] ) && is_array( $record['parentConfirm'] ) ) {
			$confirm = $record['parentConfirm'];
			$help .= ' ' . $this->entityConfirmHtml(
				'wpparent',
				$this->msg( 'embeddablecontent-source-field-parent' )->text(),
				(string)( $confirm['fetched'] ?? '' ),
				(string)( $confirm['label'] ?? '' ),
				(string)( $confirm['id'] ?? '' )
			);
		} elseif ( isset( $record['parentUnresolved'] ) && is_array( $record['parentUnresolved'] ) ) {
			$unresolved = $record['parentUnresolved'];
			$help .= ' ' . $this->msg(
				'embeddablecontent-source-parent-unresolved',
				(string)( $unresolved['root'] ?? '' ),
				$parentKey
			)->parse();
		}

		return [ 'parent' => [
			'type' => 'combobox',
			'options' => [],
			'label-message' => 'embeddablecontent-source-field-parent',
			'cssclass' => 'wb-entity-combobox',
			'default' => (string)( $record['parent'] ?? '' ),
			'help' => $help,
			'required' => true,
		] ];
	}

	protected function classOptions(): array {
		$key = $this->currentClassKey;
		$id = $this->config->sourceClasses()[$key] ?? null;
		return $id === null ? [] : [ $key => $id ];
	}

	protected function defaultClassItemId( array $record ): ?string {
		return $this->config->sourceClasses()[$this->currentClassKey] ?? null;
	}

	protected function executeManual(): void {
		// The website-vs-webpage explanation lives on the URL entry page
		// (the /website and /webpage first step); the manual form itself is
		// reached from there (or directly via /manual) and needs no banner.
		parent::executeManual();
	}

	// ------------------------------------------------------------- classic page
	// The base afterCreate() writes a sitelinked Source:<label> page (the
	// issue-#26 AddSoftware pattern); this class declares the page facts.
	// bookExcerpt gets NO page — it is part of a book.

	/** Per-class template transcluded by the Source: page skeleton. */
	private const SOURCE_PAGE_TEMPLATES = [
		'book' => 'Book',
		'scholarlyArticle' => 'ScholarlyArticle',
		'website' => 'Website',
		'song' => 'Song',
		'film' => 'Film',
		'video' => 'Video',
		'youtubeChannel' => 'YouTubeChannel',
		'youtubeVideo' => 'YouTubeVideo',
		'webpage' => 'Webpage',
	];

	protected function pageNamespace(): ?int {
		return defined( 'NS_SOURCE' ) ? NS_SOURCE : null;
	}

	/**
	 * No classic page for bookExcerpt (a part of a book, not a standalone
	 * work); every other source class gets a Source: page.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageTitleForRecord( array $record ): ?Title {
		if ( $this->currentClassKey === 'bookExcerpt' ) {
			return null;
		}
		return parent::pageTitleForRecord( $record );
	}

	protected function pageTemplate(): string {
		return self::SOURCE_PAGE_TEMPLATES[$this->currentClassKey] ?? '';
	}

	/**
	 * Source: page skeleton — prose lives on the page, facts in the item.
	 * Sections are rendered ONLY when the (reviewed) record carries their
	 * content: no blank sections, no generic Overview/Content/See also
	 * scaffolding. website/youtubeChannel take a short intro WITHOUT
	 * headings; the other classes get their meaningful section(s). When NO
	 * content was fetched, the item's description is the placeholder lead
	 * (== Overview ==, or the short intro for website/youtubeChannel).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function pageSkeleton( array $record, bool $withMarker = false ): string {
		$template = $this->pageTemplate();
		$marker = $withMarker ? "\n<!-- " . $this->pagePendingMarker() . " -->\n" : "";
		if ( $template === '' ) {
			return $marker;
		}
		$body = "{{" . $template . "}}\n\n";
		if ( in_array( $this->currentClassKey, [ 'website', 'youtubeChannel' ], true ) ) {
			// Short intro prose, no headings; the item description is the
			// placeholder lead when no intro was fetched.
			$intro = trim( (string)( $record['intro'] ?? '' ) );
			if ( $intro !== '' ) {
				$body .= $this->attributed( $record, 'intro', $intro ) . "\n\n";
			} else {
				$overview = trim( (string)( $record['description'] ?? '' ) );
				if ( $overview !== '' ) {
					$body .= $overview . "\n\n";
				}
			}
			return $body . $marker;
		}
		$rendered = false;
		foreach ( $this->pageSectionHeadings() as $key => $heading ) {
			$content = trim( (string)( $record[$key] ?? '' ) );
			if ( $content === '' ) {
				continue;
			}
			$body .= "== {$heading} ==\n\n" . $this->attributed( $record, $key, $content ) . "\n\n";
			$rendered = true;
		}
		if ( !$rendered ) {
			// No fetched content — the item description is the placeholder lead.
			$overview = trim( (string)( $record['description'] ?? '' ) );
			if ( $overview !== '' ) {
				$body .= "== Overview ==\n\n{$overview}\n\n";
			}
		}
		return $body . $marker;
	}

	/** @return array<string,string> content key => section heading */
	private function pageSectionHeadings(): array {
		switch ( $this->currentClassKey ) {
			case 'scholarlyArticle':
				return [ 'abstract' => 'Abstract', 'keywords' => 'Key words' ];
			case 'book':
				return [ 'summary' => 'Summary', 'keywords' => 'Key words' ];
			case 'song':
				return [ 'intro' => 'Overview', 'lyrics' => 'Lyrics' ];
			case 'film':
				return [ 'intro' => 'Overview', 'plot' => 'Plot' ];
			case 'video':
			case 'youtubeVideo':
				return [ 'keywords' => 'Key words' ];
			case 'webpage':
				return [ 'summary' => 'Summary', 'keywords' => 'Key words' ];
			default:
				return [];
		}
	}

	// ------------------------------------------------------------- validation

	/**
	 * Cross-field validation before creation (review AND manual paths):
	 * duration format, author entities (≥1, agent-class), parent item
	 * (child classes: exists and is instance of the parent class), and the
	 * YouTube id derived from the URL when not typed. Book excerpts also
	 * auto-generate their description and infer year/authors from the parent
	 * book when left blank (BEFORE the author validation, so an inferred
	 * author set passes). Returning a non-null string aborts the creation
	 * with the message as a form error.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function beforeCreate( array &$record ): ?string {
		$rawDuration = trim( (string)( $record['duration'] ?? '' ) );
		if ( $rawDuration !== '' ) {
			$seconds = Duration::parseSeconds( $rawDuration );
			if ( $seconds === null ) {
				return $this->msg( 'embeddablecontent-source-error-duration' )->text();
			}
			$record['durationSeconds'] = $seconds;
		}

		// Access field (issue #35): license + upload for the download/file
		// modes; a filled-in access field that cannot be honoured aborts the
		// creation with a form error (never silent).
		$error = $this->validateAccessField( $record );
		if ( $error !== null ) {
			return $error;
		}

		// bookExcerpt: description autogen + year/authors from the parent
		// book when blank — before validateAuthors (blank authors must be
		// inferred, not rejected).
		if ( $this->currentClassKey === 'bookExcerpt' ) {
			$error = $this->fillBookExcerptFromParent( $record );
			if ( $error !== null ) {
				return $error;
			}
		}

		$error = $this->validateAuthors( $record );
		if ( $error !== null ) {
			return $error;
		}

		// Derive the YouTube identifier from the URL when not typed.
		if ( $this->currentClassKey === 'youtubeChannel' && empty( $record['youtubeChannelId'] ) ) {
			$record['youtubeChannelId'] = YouTubeProvider::extractChannelId( (string)( $record['url'] ?? '' ) ) ?? '';
		}
		if ( $this->currentClassKey === 'youtubeVideo' && empty( $record['youtubeVideoId'] ) ) {
			$record['youtubeVideoId'] = YouTubeProvider::extractVideoId( (string)( $record['url'] ?? '' ) ) ?? '';
		}

		return $this->validateParent( $record );
	}

	/**
	 * Access field validation + upload (issue #35): in the `url` mode only
	 * the URL field exists (validated by the form's url type); the
	 * `download` and `file` modes require a license entity and produce the
	 * uploaded file (server-side fetch for download, browser file for file),
	 * stored as File:<label>.<ext> with auto-generated page text — the
	 * original filename is ignored. Mutates $record: accessMode normalized,
	 * license serialized, fileTitle set on upload.
	 *
	 * @param array<string,mixed> $record
	 * @return string|null error message, or null to proceed
	 */
	protected function validateAccessField( array &$record ): ?string {
		$mode = (string)( $record['accessMode'] ?? 'url' );
		if ( !in_array( $mode, [ 'url', 'download', 'file', 'na' ], true ) ) {
			$mode = 'url';
		}
		$record['accessMode'] = $mode;
		// The 'na' mode (access only via archives, physical copies, …) and
		// the plain 'url' mode need no license and no upload.
		if ( $mode === 'url' || $mode === 'na' ) {
			return null;
		}

		$licenseId = trim( (string)( $record['license'] ?? '' ) );
		$licenseItem = $this->parseItemId( $licenseId );
		if ( $licenseItem === null ) {
			return $this->msg( 'embeddablecontent-source-error-license' )->text();
		}
		$record['license'] = $licenseItem->getSerialization();

		try {
			if ( $mode === 'file' ) {
				$title = $this->uploadAccessFileFromRequest( $record );
				if ( $title === null ) {
					$upload = $this->getRequest()->getUpload( 'wpaccessFile' );
					if ( $upload instanceof \MediaWiki\Request\WebRequestUpload && $upload->getSize() > 0 ) {
						return $this->msg( 'embeddablecontent-source-error-access-upload', 'unsupported file type' )->text();
					}
					return $this->msg( 'embeddablecontent-source-error-access-file-required' )->text();
				}
			} else {
				$url = ( new FragmentSanitizer() )->validateUrl( (string)( $record['downloadUrl'] ?? '' ) );
				if ( $url === null ) {
					return $this->msg( 'embeddablecontent-source-error-access-upload', 'invalid or unreachable URL' )->text();
				}
				$title = $this->uploadAccessFileFromUrl( $url, $record );
				if ( $title === null ) {
					return $this->msg( 'embeddablecontent-source-error-access-upload', 'unreachable or unsupported URL' )->text();
				}
			}
		} catch ( \Throwable $e ) {
			return $this->msg( 'embeddablecontent-source-error-access-upload', $e->getMessage() )->text();
		}
		if ( $title !== null ) {
			$record['fileTitle'] = $title->getDBkey();
		}
		return null;
	}

	/**
	 * Local-file access upload (accessMode=file). Returns the file title, or
	 * null when no file was provided (the caller distinguishes "no file"
	 * from a failed verification).
	 *
	 * @param array<string,mixed> $record
	 */
	protected function uploadAccessFileFromRequest( array $record ): ?\MediaWiki\Title\Title {
		$request = $this->getRequest();
		$upload = $request->getUpload( 'wpaccessFile' );
		if ( !$upload instanceof \MediaWiki\Request\WebRequestUpload
			|| $upload->getSize() <= 0 || $upload->getTempName() === ''
		) {
			return null;
		}
		$tempPath = $upload->getTempName();
		$mime = \MediaWiki\MediaWikiServices::getInstance()
			->getMimeAnalyzer()->guessMimeType( $tempPath, false );
		$destName = $this->accessDestName( $record, $upload->getName(), $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new \MediaWiki\Upload\UploadFromFile();
		$base->initializePathInfo( $destName, $tempPath, $upload->getSize() );
		return $this->performAccessUpload( $base, $record );
	}

	/**
	 * Direct-download access upload (accessMode=download) — goes through
	 * UploadFromUrl so the instance's SSRF guards (IsUploadAllowedFromUrl)
	 * apply. Returns the file title, or null when the fetch failed.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function uploadAccessFileFromUrl( string $url, array $record ): ?\MediaWiki\Title\Title {
		if ( !\MediaWiki\Upload\UploadFromUrl::isAllowed( $this->getUser() ) ) {
			return null;
		}
		$path = parse_url( $url, PHP_URL_PATH );
		$name = $path !== false && $path !== null && $path !== '' ? basename( $path ) : 'file';
		$mime = $this->mimeFromUrl( $url );
		$destName = $this->accessDestName( $record, $name, $mime );
		if ( $destName === '' ) {
			return null;
		}
		$base = new \MediaWiki\Upload\UploadFromUrl();
		$base->initialize( $destName, $url );
		// initialize() only creates the EMPTY temp file — the download
		// itself happens in fetchFile() (UploadFromUrl streams the body and
		// follows redirects, SSRF-validating each hop). Without it
		// verifyUpload() sees a zero-size file and rejects the upload as
		// EMPTY_FILE (status 3) — the "The file could not be uploaded:
		// verifyUpload rejected (3)" regression.
		$status = $base->fetchFile();
		$tempPath = $base->getTempPath();
		if ( !$status->isGood() || $tempPath === '' || !is_file( $tempPath ) ) {
			return null;
		}
		return $this->performAccessUpload( $base, $record );
	}

	/**
	 * Best-effort remote MIME probe (HEAD) for download-mode files; '' when
	 * the probe fails (the destination-name extension fallback applies).
	 */
	private function mimeFromUrl( string $url ): string {
		try {
			$http = \MediaWiki\MediaWikiServices::getInstance()->getHttpRequestFactory()
				->create( $url, [], __METHOD__ );
			$http->execute();
			return (string)$http->getResponseHeader( 'Content-Type' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Computes the destination file name "<label>.<ext>" for the access
	 * upload: the extension comes from the original name, restricted to the
	 * instance's configured $wgFileExtensions allow-list, with a fallback to
	 * the MIME type's canonical extension. The original filename is
	 * otherwise IGNORED (the file is named after the item, issue #35).
	 * Returns '' when the format is unsupported or the label is unusable.
	 *
	 * @param array<string,mixed> $record
	 */
	private function accessDestName( array $record, string $originalName, string $mime ): string {
		$allowed = \MediaWiki\MediaWikiServices::getInstance()
			->getMainConfig()->get( 'FileExtensions' );
		$allowed = is_array( $allowed ) ? array_map( 'strtolower', $allowed ) : [];
		$ext = strtolower( (string)pathinfo( $originalName, PATHINFO_EXTENSION ) );
		if ( !in_array( $ext, $allowed, true ) ) {
			$ext = strtolower( (string)( \MediaWiki\MediaWikiServices::getInstance()
				->getMimeAnalyzer()->getExtensionFromMimeTypeOrNull( $mime ) ?? '' ) );
		}
		if ( $ext === '' || !in_array( $ext, $allowed, true ) ) {
			return '';
		}
		$label = (string)preg_replace( '/[#<>\[\]|{}:]/', '', trim( $this->primaryLabel( $record ) ) );
		$label = trim( (string)preg_replace( '/\s+/', ' ', $label ) );
		if ( $label === '' ) {
			return '';
		}
		return "{$label}.{$ext}";
	}

	/**
	 * Runs verifyUpload + performUpload on a prepared UploadBase for the
	 * access file; the File: page text is auto-generated from the item label
	 * and description (the original filename is not used). Returns the file
	 * title, or null when the target already exists. A FAILED verification
	 * or upload throws — the caller (validateAccessField) surfaces the
	 * reason as a form error instead of silently dropping the file.
	 *
	 * @param array<string,mixed> $record
	 * @throws \RuntimeException when the upload is rejected
	 */
	private function performAccessUpload( \MediaWiki\Upload\UploadBase $base, array $record ): ?\MediaWiki\Title\Title {
		$title = $base->getTitle();
		if ( $title === null ) {
			return null;
		}
		if ( $title->exists() ) {
			return $title; // idempotent: already uploaded on an earlier run
		}
		$verify = $base->verifyUpload();
		if ( ( $verify['status'] ?? null ) !== \MediaWiki\Upload\UploadBase::OK ) {
			$details = $verify['details'] ?? [];
			$detail = is_array( $details ) && $details !== []
				? (string)( $details[0] ?? '' )
				: (string)( $verify['status'] ?? 'rejected' );
			throw new \RuntimeException( 'verifyUpload rejected (' . $detail . ')' );
		}
		$label = $this->primaryLabel( $record );
		$description = trim( (string)( $record['description'] ?? '' ) );
		// Auto-generated File: page text — description/meta-data/file name
		// come from the item, never from the original filename (issue #35).
		$pageText = $this->msg( 'embeddablecontent-source-access-file-page', $label, $description )->text();
		$status = $base->performUpload(
			$this->msg( 'embeddablecontent-source-access-file-edit-summary', $label )->inContentLanguage()->text(),
			$pageText,
			false,
			$this->getUser()
		);
		if ( !$status->isOK() ) {
			throw new \RuntimeException( 'performUpload: ' . ( $status->getMessage()->getParams()[0] ?? 'rejected' ) );
		}
		return $title;
	}

	/**
	 * Book-excerpt conveniences (the parent book item is validated by
	 * validateParent, which runs after this): when the description is blank,
	 * auto-generate "Pages a-b (Volume c) of {book}" from the pages/volume
	 * fields and the parent's label; when the year or the authors are blank,
	 * copy them from the parent book's own `date` / `attributed to`
	 * statements. Never errors (the parent was validated) — returns null.
	 *
	 * @param array<string,mixed> $record
	 */
	protected function fillBookExcerptFromParent( array &$record ): ?string {
		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( preg_match( '/^Q[1-9]\d*$/', $parentId ) !== 1 ) {
			return null; // missing/invalid parent is validateParent's error
		}
		$parent = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $parentId ) );
		if ( !$parent instanceof Item ) {
			return null;
		}

		if ( trim( (string)( $record['description'] ?? '' ) ) === '' ) {
			$parts = [];
			$pages = trim( (string)( $record['pages'] ?? '' ) );
			if ( $pages !== '' ) {
				$parts[] = $this->msg( 'embeddablecontent-source-bookexcerpt-desc-pages', $pages )->text();
			}
			$volume = trim( (string)( $record['volume'] ?? '' ) );
			if ( $volume !== '' ) {
				$parts[] = $this->msg( 'embeddablecontent-source-bookexcerpt-desc-volume', $volume )->text();
			}
			$parentLabel = '';
			$labelTerm = $parent->getLabels()->getByLanguage( 'en' );
			if ( $labelTerm !== null ) {
				$parentLabel = $labelTerm->getText();
			}
			if ( $parts !== [] && $parentLabel !== '' ) {
				$record['description'] = $this->msg(
					'embeddablecontent-source-bookexcerpt-desc',
					implode( ' ', $parts ),
					$parentLabel
				)->text();
			}
		}

		if ( empty( $record['issuedYear'] ) ) {
			$year = $this->itemYear( $parent );
			if ( $year !== null ) {
				$record['issuedYear'] = $year;
			}
		}
		if ( empty( $record['authors'] ) ) {
			$attributedTo = $this->config->provenancePropertyIds()['attributedTo'] ?? null;
			if ( $attributedTo !== null ) {
				$parentAuthors = $this->itemEntityValues( $parent, $attributedTo );
				if ( $parentAuthors !== [] ) {
					$record['authors'] = implode( ', ', $parentAuthors );
				}
			}
		}
		return null;
	}

	/** Year of an item's `date` (P577-aligned) statement, or null. */
	private function itemYear( Item $item ): ?int {
		$dateProp = $this->config->provenancePropertyIds()['date'] ?? null;
		if ( $dateProp === null ) {
			return null;
		}
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $dateProp ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof TimeValue ) {
				// "+2020-00-00T00:00:00Z" → 2020
				return (int)substr( $value->getTime(), 1, 4 );
			}
		}
		return null;
	}

	/** Entity ids of an item's statements on the given property. */
	private function itemEntityValues( Item $item, string $propertyId ): array {
		$out = [];
		foreach ( $item->getStatements()->getByPropertyId( new NumericPropertyId( $propertyId ) ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue ) {
				$out[] = $value->getEntityId()->getSerialization();
			}
		}
		return $out;
	}

	/** Label of the current class's parent class (e.g. "book"), or ''. */
	private function parentClassLabel(): string {
		$parentKey = $this->config->sourceParents()[$this->currentClassKey] ?? null;
		return $parentKey !== null
			? $this->msg( 'embeddablecontent-source-class-' . $parentKey )->text()
			: '';
	}

	/**
	 * @param array<string,mixed> $record
	 */
	protected function validateAuthors( array $record ): ?string {
		$ids = ItemIdList::split( (string)( $record['authors'] ?? '' ) );
		if ( $ids === [] ) {
			return $this->msg( 'embeddablecontent-source-error-noauthor' )->text();
		}
		$agentIds = array_values( $this->config->agentClasses() );
		foreach ( $ids as $id ) {
			if ( preg_match( '/^Q[1-9]\d*$/', $id ) !== 1 ) {
				return $this->msg( 'embeddablecontent-add-error-baditemid', $id )->text();
			}
			$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $id ) );
			if ( !$item instanceof Item ) {
				return $this->msg( 'embeddablecontent-source-error-author-entity', $id )->text();
			}
			if ( !$this->itemHasClass( $item, $agentIds ) ) {
				return $this->msg( 'embeddablecontent-source-error-author-class', $id )->text();
			}
		}
		return null;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	protected function validateParent( array $record ): ?string {
		$parentKey = $this->config->sourceParents()[$this->currentClassKey] ?? null;
		if ( $parentKey === null ) {
			return null; // not a child class
		}
		$parentClassId = $this->config->sourceClasses()[$parentKey] ?? null;
		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( $parentClassId === null || preg_match( '/^Q[1-9]\d*$/', $parentId ) !== 1 ) {
			return $this->msg( 'embeddablecontent-source-error-parent-required' )->text();
		}
		$item = WikibaseRepo::getEntityLookup()->getEntity( new ItemId( $parentId ) );
		if ( !$item instanceof Item || !$this->itemHasClass( $item, [ $parentClassId ] ) ) {
			return $this->msg( 'embeddablecontent-source-error-parent-class', $parentId )->text();
		}
		return null;
	}

	/** @param string[] $classItemIds */
	private function itemHasClass( Item $item, array $classItemIds ): bool {
		$propertyId = new NumericPropertyId( $this->config->instanceOfPropertyId() );
		foreach ( $item->getStatements()->getByPropertyId( $propertyId ) as $statement ) {
			$value = $statement->getMainSnak()->getDataValue();
			if ( $value instanceof EntityIdValue
				&& in_array( $value->getEntityId()->getSerialization(), $classItemIds, true )
			) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------- creation

	/**
	 * The publisher and the journal are written as ENTITY values (entity-only,
	 * issue #35 + follow-up) — exclude them from the base string citation
	 * metadata.
	 *
	 * @return string[]
	 */
	protected function citationMetadataFieldExclusions(): array {
		return [ 'publisher', 'publishedIn' ];
	}

	/**
	 * Class-aware statement specs (base-class contract): external ids +
	 * citation metadata + duration (seconds, quantity) + item URL + YouTube
	 * identifiers + author entities (attributed to) + the child→parent
	 * `part of` link + the entity publisher + the access facts.
	 *
	 * @param array<string,mixed> $record
	 * @return array<string,mixed> property id => DataValue | DataValue[]
	 */
	protected function statementSpecs( array $record ): array {
		$specs = $this->externalIdStatements( $record ) + $this->citationMetadataStatements( $record );
		$props = $this->config->sourcePropertyIds();

		// Publisher: entity-only — the value is an item id (combobox), written
		// as an entity statement on the entity-typed publisher property.
		$publisherId = trim( (string)( $record['publisher'] ?? '' ) );
		$publisherItem = $this->parseItemId( $publisherId );
		$publisherProp = $this->config->citationMetadataPropertyIds()['publisher'] ?? null;
		if ( $publisherItem !== null && $publisherProp !== null ) {
			$specs[$publisherProp] = new EntityIdValue( $publisherItem );
		}

		// Journal (scholarlyArticle): entity-only, like the publisher — the
		// entity-typed journal property (P1433-aligned) replaces the legacy
		// string container title, which the citation engine resolves to the
		// journal item's label at render time.
		$journalId = trim( (string)( $record['journal'] ?? '' ) );
		$journalItem = $this->parseItemId( $journalId );
		$journalProp = $this->config->citationMetadataPropertyIds()['journal'] ?? null;
		if ( $journalItem !== null && $journalProp !== null ) {
			$specs[$journalProp] = new EntityIdValue( $journalItem );
		}

		if ( !empty( $record['durationSeconds'] ) && isset( $props['duration'] ) ) {
			$specs[$props['duration']] = QuantityValue::newFromNumber( (int)$record['durationSeconds'] );
		}

		// Chapters (book excerpts): optional count/range string.
		if ( !empty( $record['chapters'] ) && isset( $props['chapters'] ) ) {
			$specs[$props['chapters']] = new StringValue( (string)$record['chapters'] );
		}

		// Year: publication/creation date at YEAR precision on the shared
		// `date` property (P577-aligned — the citation engine already reads
		// it as publicationDate). Book-excerpt inference copies this
		// statement from the parent book item.
		$year = (int)( $record['issuedYear'] ?? 0 );
		if ( $year > 0 ) {
			$dateProp = $this->config->provenancePropertyIds()['date'] ?? null;
			if ( $dateProp !== null ) {
				$specs[$dateProp] = new TimeValue(
					sprintf( '+%04d-00-00T00:00:00Z', $year ),
					0, 0, 0,
					TimeValue::PRECISION_YEAR,
					'http://www.wikidata.org/entity/Q1985727'
				);
			}
		}

		$url = ( new FragmentSanitizer() )->validateUrl( (string)( $record['url'] ?? '' ) );
		if ( $url !== null && isset( $props['url'] ) ) {
			$specs[$props['url']] = new StringValue( $url );
		}

		if ( !empty( $record['youtubeChannelId'] ) && isset( $props['youtubeChannelId'] ) ) {
			$specs[$props['youtubeChannelId']] = new StringValue( (string)$record['youtubeChannelId'] );
		}
		if ( !empty( $record['youtubeVideoId'] ) && isset( $props['youtubeVideoId'] ) ) {
			$specs[$props['youtubeVideoId']] = new StringValue( (string)$record['youtubeVideoId'] );
		}

		// Access facts (issue #35): non-direct access URL, the uploaded file
		// (File: page URL) and the license entity — all set by
		// validateAccessField before creation.
		$accessUrl = ( new FragmentSanitizer() )->validateUrl( (string)( $record['accessUrl'] ?? '' ) );
		if ( $accessUrl !== null && isset( $props['accessUrl'] ) ) {
			$specs[$props['accessUrl']] = new StringValue( $accessUrl );
		}
		if ( !empty( $record['fileTitle'] ) && isset( $props['file'] ) ) {
			$fileTitle = \MediaWiki\Title\Title::makeTitle( NS_FILE, (string)$record['fileTitle'] );
			if ( $fileTitle !== null ) {
				$specs[$props['file']] = new StringValue( $fileTitle->getFullURL() );
			}
		}
		if ( !empty( $record['license'] ) && isset( $props['license'] ) ) {
			$licenseItem = $this->parseItemId( (string)$record['license'] );
			if ( $licenseItem !== null ) {
				$specs[$props['license']] = new EntityIdValue( $licenseItem );
			}
		}

		// Authors: one `attributed to` statement per entity (≥1 enforced in
		// beforeCreate; multi-valued specs write one statement per element).
		$authorIds = ItemIdList::split( (string)( $record['authors'] ?? '' ) );
		$attributedTo = $this->config->provenancePropertyIds()['attributedTo'] ?? null;
		if ( $authorIds !== [] && $attributedTo !== null ) {
			foreach ( $authorIds as $authorId ) {
				$specs[$attributedTo][] = new EntityIdValue( new ItemId( $authorId ) );
			}
		}

		// Child→parent link (`part of`), validated in beforeCreate.
		$parentId = trim( (string)( $record['parent'] ?? '' ) );
		if ( $parentId !== '' && isset( $props['partOf'] ) && preg_match( '/^Q[1-9]\d*$/', $parentId ) === 1 ) {
			$specs[$props['partOf']] = new EntityIdValue( new ItemId( $parentId ) );
		}

		return $specs;
	}

	// ------------------------------------------------------------- duration helpers

	/**
	 * Parses "(HH):MM:SS" (or "MM:SS") into seconds; null on a malformed
	 * value, so the caller can surface a form error.
	 */
	
}
