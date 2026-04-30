<?php

namespace MediaWiki\Extension\AssistedSearch;

use MediaWiki\MediaWikiServices;

class SearchToolExecutor {

	private SectionExtractor $sectionExtractor;
	private int $maxArticles;
	private const TEXT_BUDGET = 20000;

	public function __construct( SectionExtractor $sectionExtractor, int $maxArticles = 5 ) {
		$this->sectionExtractor = $sectionExtractor;
		$this->maxArticles = $maxArticles;
	}

	public function executeSearch( array $queries ): array {
		$searchTerms = [];
		foreach ( $queries as $query ) {
			if ( is_string( $query ) && trim( $query ) !== '' ) {
				$searchTerms[] = strtolower( trim( $query ) );
			}
		}
		if ( $searchTerms === [] ) {
			return [];
		}

		$searchEngine = MediaWikiServices::getInstance()
			->getSearchEngineFactory()
			->create();
		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		$pageMap = [];

		foreach ( $searchTerms as $term ) {
			$searchEngine->setLimitOffset( $this->maxArticles );
			$searchEngine->setNamespaces( [ NS_MAIN, NS_CATEGORY ] );

			$results = $searchEngine->searchText( $term );
			if ( $results === null ) {
				continue;
			}

			if ( $results instanceof \Status ) {
				$results = $results->getValue();
			}

			if ( $results === null ) {
				continue;
			}

			foreach ( $results->extractResults() as $result ) {
				$title = $result->getTitle();
				if ( !$title ) {
					continue;
				}
				$pageKey = $title->getPrefixedDBkey();
				if ( !isset( $pageMap[$pageKey] ) ) {
					$pageMap[$pageKey] = $title;
				}
			}
		}

		$pages = [];
		foreach ( $pageMap as $title ) {
			$wikiPage = $wikiPageFactory->newFromTitle( $title );
			if ( !$wikiPage->exists() ) {
				continue;
			}

			$sections = $this->sectionExtractor->extractAllSections( $wikiPage );
			$filtered = [];
			foreach ( $sections as $section ) {
				$heading = strtolower( $section['section_heading'] );
				$isIntro = $section['section_index'] === 0;
				$matchesQuery = false;
				foreach ( $searchTerms as $term ) {
					if ( str_contains( $heading, $term ) || str_contains( strtolower( $section['article_title'] ), $term ) ) {
						$matchesQuery = true;
						break;
					}
				}
				if ( $isIntro || $matchesQuery ) {
					$filtered[] = [
						'article_title' => $section['article_title'],
						'section_heading' => $section['section_heading'],
						'section_id' => $section['section_id'],
						'_wikiPage' => $wikiPage,
						'_sectionIndex' => $section['section_index'],
					];
				}
			}

			if ( $filtered !== [] ) {
				$pages[] = $filtered;
			}
		}

		$textBudget = self::TEXT_BUDGET;
		$result = [];
		foreach ( $pages as $pageSections ) {
			$pageEntry = [
				'article_title' => $pageSections[0]['article_title'],
				'sections' => [],
			];

			foreach ( $pageSections as $section ) {
				$wikiPage = $section['_wikiPage'];
				$sectionIndex = $section['_sectionIndex'];
				$sectionText = null;
				$content = $wikiPage->getContent();
				if ( $content ) {
					$sectionContent = $content->getSection( (string)$sectionIndex );
					if ( $sectionContent ) {
						$rawText = WikitextCleaner::cleanText( $sectionContent->getTextForSearchIndex(), 1000 );
						if ( strlen( $rawText ) <= $textBudget ) {
							$sectionText = $rawText;
							$textBudget -= strlen( $rawText );
						}
					}
				}

				$pageEntry['sections'][] = [
					'section_heading' => $section['section_heading'],
					'section_id' => $section['section_id'],
					'section_text' => $sectionText,
				];
			}

			$result[] = $pageEntry;
		}

		return $result;
	}

	public function executeRetrieve( string $sectionId, string $direction ): ?array {
		return $this->sectionExtractor->getAdjacentSection( $sectionId, $direction );
	}
}
