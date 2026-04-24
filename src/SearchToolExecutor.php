<?php

namespace MediaWiki\Extension\AssistedSearch;

use MediaWiki\MediaWikiServices;

class SearchToolExecutor {

	private SectionExtractor $sectionExtractor;
	private int $maxArticles;

	public function __construct( SectionExtractor $sectionExtractor, int $maxArticles = 5 ) {
		$this->sectionExtractor = $sectionExtractor;
		$this->maxArticles = $maxArticles;
	}

	public function executeSearch( string $query ): array {
		$searchEngine = MediaWikiServices::getInstance()
			->getSearchEngineFactory()
			->create();

		$searchEngine->setLimitOffset( $this->maxArticles );
		$searchEngine->setNamespaces( [ NS_MAIN ] );

		$results = $searchEngine->searchText( $query );
		if ( $results === null ) {
			return [];
		}

		if ( $results instanceof \Status ) {
			$results = $results->getValue();
		}

		if ( $results === null ) {
			return [];
		}

		$allSections = [];
		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();

		foreach ( $results->extractResults() as $result ) {
			$title = $result->getTitle();
			if ( !$title ) {
				continue;
			}

			$wikiPage = $wikiPageFactory->newFromTitle( $title );
			if ( !$wikiPage->exists() ) {
				continue;
			}

			$sections = $this->sectionExtractor->extractAllSections( $wikiPage );
			foreach ( $sections as $section ) {
				$allSections[] = $section;
			}
		}

		return $allSections;
	}

	public function executeRetrieve( string $sectionUrl, string $direction ): ?array {
		return $this->sectionExtractor->getAdjacentSection( $sectionUrl, $direction );
	}
}
