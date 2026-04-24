<?php

namespace MediaWiki\Extension\AssistedSearch;

use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;

class SectionExtractor {

	private string $serverUrl;

	public function __construct( string $serverUrl ) {
		$this->serverUrl = $serverUrl;
	}

	/**
	 * @return array<int, array{article_title: string, section_heading: string, section_text: string, section_url: string, section_index: int}>
	 */
	public function extractAllSections( WikiPage $page ): array {
		$content = $page->getContent();
		if ( !$content ) {
			return [];
		}

		$wikitext = $content->getText();
		$headings = $this->parseHeadings( $wikitext );
		$title = $page->getTitle();
		$dbkey = $title->getPrefixedDBkey();
		$sections = [];

		$sectionContent = $content->getSection( '0' );
		if ( $sectionContent ) {
			$sections[] = [
				'article_title' => $title->getFullText(),
				'section_heading' => '(Introduction)',
				'section_text' => $this->cleanText( $sectionContent->getTextForSearchIndex() ),
				'section_url' => $this->serverUrl . '/' . $dbkey,
				'section_index' => 0,
			];
		}

		foreach ( $headings as $i => $heading ) {
			$sectionId = (string)( $i + 1 );
			$sectionContent = $content->getSection( $sectionId );
			if ( $sectionContent ) {
				$anchor = $this->makeAnchor( $heading );
				$sections[] = [
					'article_title' => $title->getFullText(),
					'section_heading' => $heading,
					'section_text' => $this->cleanText( $sectionContent->getTextForSearchIndex() ),
					'section_url' => $this->serverUrl . '/' . $dbkey . '#' . $anchor,
					'section_index' => $i + 1,
				];
			}
		}

		return $sections;
	}

	public function getSectionByUrl( string $url ): ?array {
		$parsed = parse_url( $url );
		$path = $parsed['path'] ?? '';
		$fragment = $parsed['fragment'] ?? '';

		$titleText = rawurldecode( ltrim( $path, '/' ) );
		$title = Title::newFromText( $titleText );
		if ( !$title ) {
			return null;
		}

		$wikiPage = MediaWikiServices::getInstance()
			->getWikiPageFactory()
			->newFromTitle( $title );

		if ( !$wikiPage->exists() ) {
			return null;
		}

		$content = $wikiPage->getContent();
		if ( !$content ) {
			return null;
		}

		$dbkey = $title->getPrefixedDBkey();

		if ( $fragment === '' ) {
			$sectionContent = $content->getSection( '0' );
			if ( !$sectionContent ) {
				return null;
			}
			return [
				'article_title' => $title->getFullText(),
				'section_heading' => '(Introduction)',
				'section_text' => $this->cleanText( $sectionContent->getTextForSearchIndex() ),
				'section_url' => $this->serverUrl . '/' . $dbkey,
				'section_index' => 0,
			];
		}

		$headingText = str_replace( '_', ' ', rawurldecode( $fragment ) );
		$headings = $this->parseHeadings( $content->getText() );

		foreach ( $headings as $i => $heading ) {
			if ( strtolower( $heading ) === strtolower( $headingText ) ) {
				$sectionId = (string)( $i + 1 );
				$sectionContent = $content->getSection( $sectionId );
				if ( $sectionContent ) {
					$anchor = $this->makeAnchor( $heading );
					return [
						'article_title' => $title->getFullText(),
						'section_heading' => $heading,
						'section_text' => $this->cleanText( $sectionContent->getTextForSearchIndex() ),
						'section_url' => $this->serverUrl . '/' . $dbkey . '#' . $anchor,
						'section_index' => $i + 1,
					];
				}
			}
		}

		return null;
	}

	public function getAdjacentSection( string $url, string $direction ): ?array {
		$parsed = parse_url( $url );
		$path = $parsed['path'] ?? '';
		$titleText = rawurldecode( ltrim( $path, '/' ) );
		$title = Title::newFromText( $titleText );
		if ( !$title ) {
			return null;
		}

		$wikiPage = MediaWikiServices::getInstance()
			->getWikiPageFactory()
			->newFromTitle( $title );

		if ( !$wikiPage->exists() ) {
			return null;
		}

		$content = $wikiPage->getContent();
		if ( !$content ) {
			return null;
		}

		$headings = $this->parseHeadings( $content->getText() );
		$fragment = $parsed['fragment'] ?? '';
		$dbkey = $title->getPrefixedDBkey();

		if ( $fragment === '' ) {
			if ( $direction === 'next' && count( $headings ) > 0 ) {
				$heading = $headings[0];
				$sectionContent = $content->getSection( '1' );
				if ( $sectionContent ) {
					$anchor = $this->makeAnchor( $heading );
					return [
						'article_title' => $title->getFullText(),
						'section_heading' => $heading,
						'section_text' => $this->cleanText( $sectionContent->getTextForSearchIndex() ),
						'section_url' => $this->serverUrl . '/' . $dbkey . '#' . $anchor,
						'section_index' => 1,
					];
				}
			}
			return null;
		}

		$headingText = str_replace( '_', ' ', rawurldecode( $fragment ) );
		$currentIndex = -1;
		foreach ( $headings as $i => $heading ) {
			if ( strtolower( $heading ) === strtolower( $headingText ) ) {
				$currentIndex = $i;
				break;
			}
		}

		if ( $currentIndex === -1 ) {
			return null;
		}

		$targetIndex = $direction === 'next' ? $currentIndex + 1 : $currentIndex - 1;

		if ( $targetIndex < 0 ) {
			$sectionContent = $content->getSection( '0' );
			if ( $sectionContent ) {
				return [
					'article_title' => $title->getFullText(),
					'section_heading' => '(Introduction)',
					'section_text' => $this->cleanText( $sectionContent->getTextForSearchIndex() ),
					'section_url' => $this->serverUrl . '/' . $dbkey,
					'section_index' => 0,
				];
			}
			return null;
		}

		if ( $targetIndex >= count( $headings ) ) {
			return null;
		}

		$heading = $headings[$targetIndex];
		$sectionId = (string)( $targetIndex + 1 );
		$sectionContent = $content->getSection( $sectionId );
		if ( $sectionContent ) {
			$anchor = $this->makeAnchor( $heading );
			return [
				'article_title' => $title->getFullText(),
				'section_heading' => $heading,
				'section_text' => $this->cleanText( $sectionContent->getTextForSearchIndex() ),
				'section_url' => $this->serverUrl . '/' . $dbkey . '#' . $anchor,
				'section_index' => $targetIndex + 1,
			];
		}

		return null;
	}

	/**
	 * @return string[]
	 */
	private function parseHeadings( string $wikitext ): array {
		$headings = [];
		if ( preg_match_all( '/^(={1,6})\s*(.+?)\s*\1\s*$/m', $wikitext, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$headings[] = $match[2];
			}
		}
		return $headings;
	}

	private function makeAnchor( string $heading ): string {
		return rawurlencode( str_replace( ' ', '_', $heading ) );
	}

	private function cleanText( string $text ): string {
		if ( strlen( $text ) > 2000 ) {
			$text = substr( $text, 0, 2000 ) . '...';
		}
		return trim( $text );
	}
}
