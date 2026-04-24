<?php

namespace MediaWiki\Extension\AssistedSearch;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/includes/Maintenance.php";

class GenerateAssistedSearchGist extends Maintenance {

	private const FEEDBACK_PRUNE_DAYS = 30;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Generate a gist file for AssistedSearch containing wiki categories, articles, and frequently accessed pages.' );
		$this->addOption( 'output', 'Output file path (default: $wgAssistedSearchGistFile or false)', false, true );
		$this->addOption( 'min-article-length', 'Minimum page length in bytes to include (default: 500)', false, true );
		$this->addOption( 'feedback-file', 'Feedback JSONL file path (default: $wgAssistedSearchFeedbackFile)', false, true );
		$this->addOption( 'max-articles', 'Maximum number of articles to include (default: no limit)', false, true );
		$this->addOption( 'max-frequent', 'Max frequently accessed articles to include (default: 20)', false, true );
		$this->addOption( 'summary-length', 'Max chars per article summary (default: 200)', false, true );
	}

	public function execute() {
		$config = $this->getConfig();

		$outputPath = $this->getOption( 'output', $config->get( 'AssistedSearchGistFile' ) );
		if ( !$outputPath ) {
			$this->fatalError( 'No output path specified. Use --output or set $wgAssistedSearchGistFile.' );
		}

		$minLength = (int)$this->getOption( 'min-article-length', $config->get( 'AssistedSearchMinArticleLength' ) );
		$feedbackFile = $this->getOption( 'feedback-file', $config->get( 'AssistedSearchFeedbackFile' ) );
		$maxArticles = $this->getOption( 'max-article-length', null );
		$maxFrequent = (int)$this->getOption( 'max-frequent', 20 );
		$summaryLength = (int)$this->getOption( 'summary-length', 200 );

		$dbr = $this->getReplicaDB();
		$services = MediaWikiServices::getInstance();
		$wikiPageFactory = $services->getWikiPageFactory();

		$this->output( "Generating AssistedSearch gist...\n" );

		$stats = $this->getStats( $dbr );
		$categories = $this->buildCategoryTree( $dbr );
		$frequentTitles = $this->loadFeedback( $feedbackFile );
		$articles = $this->getArticles( $dbr, $wikiPageFactory, $minLength, $maxArticles, $summaryLength, $frequentTitles );

		$gist = $this->renderGist( $stats, $categories, $articles, $frequentTitles, $maxFrequent, $summaryLength, $wikiPageFactory );

		$dir = dirname( $outputPath );
		if ( !is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $outputPath, $gist );

		$this->output( "Gist written to $outputPath (" . strlen( $gist ) . " bytes)\n" );

		$this->pruneFeedback( $feedbackFile );
	}

	private function getStats( $dbr ): array {
		$row = $dbr->newSelectQueryBuilder()
			->select( [
				'articles' => $dbr->expr( 'COUNT(*)', '>=', 0 )->andExpr(
					$dbr->expr( 'page_namespace', '=', NS_MAIN )
				),
				'categories' => $dbr->expr( 'COUNT(*)', '>=', 0 )->andExpr(
					$dbr->expr( 'page_namespace', '=', NS_CATEGORY )
				),
			] )
			->from( 'page' )
			->caller( __METHOD__ )->fetchRow();

		return [
			'articles' => (int)$dbr->selectField( 'page', 'COUNT(*)', [ 'page_namespace' => NS_MAIN ], __METHOD__ ),
			'categories' => (int)$dbr->selectField( 'page', 'COUNT(*)', [ 'page_namespace' => NS_CATEGORY ], __METHOD__ ),
		];
	}

	private function buildCategoryTree( $dbr ): array {
		$childCats = $dbr->newSelectQueryBuilder()
			->select( 'lt_title' )
			->from( 'categorylinks' )
			->join( 'linktarget', null, 'cl_target_id = lt_id' )
			->where( [ 'cl_type' => 'subcat', 'lt_namespace' => NS_CATEGORY ] )
			->caller( __METHOD__ )->fetchFieldValues();

		$childCatSet = array_flip( $childCats );

		$res = $dbr->newSelectQueryBuilder()
			->select( 'cat_title' )
			->from( 'category' )
			->caller( __METHOD__ )->fetchResultSet();

		$topLevel = [];
		foreach ( $res as $row ) {
			if ( !isset( $childCatSet[$row->cat_title] ) ) {
				$topLevel[] = $row->cat_title;
			}
		}

		sort( $topLevel );
		$tree = [];
		foreach ( $topLevel as $cat ) {
			$tree[$cat] = $this->getSubcategories( $dbr, $cat );
		}

		return $tree;
	}

	private function getSubcategories( $dbr, string $parentCat ): array {
		$subcats = $dbr->newSelectQueryBuilder()
			->select( 'lt_title' )
			->from( 'categorylinks' )
			->join( 'linktarget', null, 'cl_target_id = lt_id' )
			->where( [
				'cl_type' => 'subcat',
				'lt_namespace' => NS_CATEGORY,
				'cl_from' => $dbr->newSelectQueryBuilder()
					->select( 'page_id' )
					->from( 'page' )
					->where( [ 'page_namespace' => NS_CATEGORY, 'page_title' => $parentCat ] )
					->caller( __METHOD__ )->buildSelectSQLValues(),
			] )
			->caller( __METHOD__ )->fetchFieldValues();

		$subcats = array_unique( $subcats );
		sort( $subcats );
		return $subcats;
	}

	private function loadFeedback( ?string $feedbackFile ): array {
		if ( !$feedbackFile || !file_exists( $feedbackFile ) ) {
			return [];
		}

		$counts = [];
		$handle = fopen( $feedbackFile, 'r' );
		if ( !$handle ) {
			return [];
		}

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$entry = json_decode( $line, true );
			if ( $entry && isset( $entry['article_title'] ) ) {
				$title = $entry['article_title'];
				$counts[$title] = ( $counts[$title] ?? 0 ) + 1;
			}
		}
		fclose( $handle );

		arsort( $counts );
		return $counts;
	}

	private function pruneFeedback( ?string $feedbackFile ): void {
		if ( !$feedbackFile || !file_exists( $feedbackFile ) ) {
			return;
		}

		$cutoff = time() - ( self::FEEDBACK_PRUNE_DAYS * 86400 );
		$tempFile = $feedbackFile . '.tmp';
		$in = fopen( $feedbackFile, 'r' );
		$out = fopen( $tempFile, 'w' );

		if ( !$in || !$out ) {
			if ( $in ) {
				fclose( $in );
			}
			return;
		}

		$pruned = 0;
		while ( ( $line = fgets( $in ) ) !== false ) {
			$entry = json_decode( trim( $line ), true );
			if ( $entry && isset( $entry['ts'] ) && strtotime( $entry['ts'] ) < $cutoff ) {
				$pruned++;
				continue;
			}
			fwrite( $out, $line );
		}

		fclose( $in );
		fclose( $out );

		rename( $tempFile, $feedbackFile );

		if ( $pruned > 0 ) {
			$this->output( "Pruned $pruned old feedback entries.\n" );
		}
	}

	private function getArticles( $dbr, $wikiPageFactory, int $minLength, ?int $maxArticles, int $summaryLength, array $frequentTitles ): array {
		$query = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_title', 'page_len' ] )
			->from( 'page' )
			->where( [
				'page_namespace' => NS_MAIN,
				'page_is_redirect' => 0,
			] )
			->orderBy( 'page_id' )
			->caller( __METHOD__ );

		if ( $maxArticles ) {
			$query->limit( $maxArticles );
		}

		$res = $query->fetchResultSet();
		$articles = [];

		foreach ( $res as $row ) {
			if ( $row->page_len < $minLength && !isset( $frequentTitles[$row->page_title] ) ) {
				continue;
			}

			$wikiPage = $wikiPageFactory->newFromID( $row->page_id );
			if ( !$wikiPage || !$wikiPage->exists() ) {
				continue;
			}

			$content = $wikiPage->getContent();
			$summary = '';
			if ( $content ) {
				$summary = $content->getTextForSummary( $summaryLength );
			}

			$pageCats = $dbr->newSelectQueryBuilder()
				->select( 'lt_title' )
				->from( 'categorylinks' )
				->join( 'linktarget', null, 'cl_target_id = lt_id' )
				->where( [
					'cl_from' => $row->page_id,
					'lt_namespace' => NS_CATEGORY,
				] )
				->caller( __METHOD__ )->fetchFieldValues();

			$articles[] = [
				'title' => $row->page_title,
				'summary' => $summary,
				'bytes' => $row->page_len,
				'categories' => $pageCats,
			];
		}

		return $articles;
	}

	private function renderGist( array $stats, array $categories, array $articles, array $frequentTitles, int $maxFrequent, int $summaryLength, $wikiPageFactory ): string {
		$lines = [];
		$lines[] = 'Wiki Gist for AssistedSearch';
		$lines[] = 'Generated: ' . wfTimestampNow();
		$lines[] = "Stats: {$stats['articles']} articles, {$stats['categories']} categories";
		$lines[] = '';

		$lines[] = '=== CATEGORIES ===';
		foreach ( $categories as $cat => $subcats ) {
			$lines[] = str_replace( '_', ' ', $cat );
			foreach ( $subcats as $sub ) {
				$lines[] = '  ' . str_replace( '_', ' ', $sub );
			}
		}
		$lines[] = '';

		$lines[] = '=== ARTICLES ===';
		foreach ( $articles as $article ) {
			$title = str_replace( '_', ' ', $article['title'] );
			$catStr = $article['categories'] ? ' (' . implode( ' > ', array_map( static fn ( $c ) => str_replace( '_', ' ', $c ), $article['categories'] ) ) . ')' : '';
			$lines[] = "[$title]$catStr - {$article['bytes']} bytes";
			if ( $article['summary'] ) {
				$lines[] = '  ' . $article['summary'];
			}
		}
		$lines[] = '';

		if ( !empty( $frequentTitles ) ) {
			$lines[] = '=== FREQUENTLY ACCESSED ===';
			$shown = 0;
			foreach ( $frequentTitles as $title => $count ) {
				if ( $shown >= $maxFrequent ) {
					break;
				}

				$page = \MediaWiki\Title\Title::newFromText( $title );
				$summary = '';
				$bytes = 0;
				if ( $page ) {
					$wikiPage = $wikiPageFactory->newFromTitle( $page );
					if ( $wikiPage && $wikiPage->exists() ) {
						$content = $wikiPage->getContent();
						if ( $content ) {
							$summary = $content->getTextForSummary( $summaryLength * 2 );
						}
						$bytes = $wikiPage->getContent() ? $wikiPage->getContent()->getSize() : 0;
					}
				}

				$displayTitle = str_replace( '_', ' ', $title );
				$lines[] = "[$displayTitle] - $bytes bytes";
				$lines[] = "  (appeared in $count search results)";
				if ( $summary ) {
					$lines[] = '  ' . $summary;
				}
				$shown++;
			}
			$lines[] = '';
		}

		return implode( "\n", $lines ) . "\n";
	}
}

$maintClass = GenerateAssistedSearchGist::class;
require_once RUN_MAINTENANCE_IF_MAIN;
