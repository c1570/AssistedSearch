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
		$this->addDescription( 'Generate a gist file for AssistedSearch containing wiki articles and frequently accessed pages.' );
		$this->addOption( 'output', 'Output file path (default: $wgAssistedSearchGistFile or false)', false, true );
		$this->addOption( 'min-article-length', 'Minimum page length in bytes to include (default: 500)', false, true );
		$this->addOption( 'feedback-file', 'Feedback JSONL file path (default: $wgAssistedSearchFeedbackFile)', false, true );
		$this->addOption( 'max-articles', 'Maximum number of articles to include (default: no limit)', false, true );
		$this->addOption( 'max-frequent', 'Max frequently accessed articles to include (default: 20)', false, true );
		$this->addOption( 'summary-length', 'Max chars per article summary (default: 200)', false, true );
		$this->addOption( 'max-size', 'Maximum total size in bytes for articles section (default: 80000)', false, true );
	}

	public function execute() {
		$config = $this->getConfig();

		$outputPath = $this->getOption( 'output', $config->has( 'AssistedSearchGistFile' ) ? $config->get( 'AssistedSearchGistFile' ) : false );
		if ( !$outputPath ) {
			$this->fatalError( 'No output path specified. Use --output or set $wgAssistedSearchGistFile.' );
		}

		$minLength = (int)$this->getOption( 'min-article-length', $config->has( 'AssistedSearchMinArticleLength' ) ? $config->get( 'AssistedSearchMinArticleLength' ) : 500 );
		$feedbackFile = $this->getOption( 'feedback-file', $config->has( 'AssistedSearchFeedbackFile' ) ? $config->get( 'AssistedSearchFeedbackFile' ) : false );
		$maxArticles = $this->getOption( 'max-articles', null );
		$maxFrequent = (int)$this->getOption( 'max-frequent', 20 );
		$summaryLength = (int)$this->getOption( 'summary-length', 200 );
		$maxSize = (int)$this->getOption( 'max-size', $config->has( 'AssistedSearchGistMaxSize' ) ? $config->get( 'AssistedSearchGistMaxSize' ) : 80000 );

		$dbr = $this->getReplicaDB();
		$services = MediaWikiServices::getInstance();
		$wikiPageFactory = $services->getWikiPageFactory();

		$this->output( "Generating AssistedSearch gist...\n" );

		$stats = $this->getStats( $dbr );
		$frequentTitles = $this->loadFeedback( $feedbackFile );
		$articles = $this->getArticles( $dbr, $wikiPageFactory, $minLength, $maxArticles, $summaryLength, $frequentTitles );

		$gist = $this->renderGist( $stats, $articles, $frequentTitles, $maxFrequent, $summaryLength, $maxSize, $wikiPageFactory );

		$dir = dirname( $outputPath );
		if ( !is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $outputPath, iconv( 'UTF-8', 'UTF-8//IGNORE', $gist ) );

		$this->output( "Gist written to $outputPath (" . strlen( $gist ) . " bytes)\n" );

		$this->pruneFeedback( $feedbackFile );
	}

	private function getStats( $dbr ): array {
		return [
			'articles' => (int)$dbr->selectField( 'page', 'COUNT(*)', [ 'page_namespace' => NS_MAIN ], __METHOD__ ),
		];
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
		$hasLinkTarget = $dbr->fieldExists( 'categorylinks', 'cl_target_id' );

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
				$summary = WikitextCleaner::cleanText( $content->getTextForSearchIndex(), $summaryLength );
			}

			if ( $hasLinkTarget ) {
				$pageCats = $dbr->newSelectQueryBuilder()
					->select( 'lt_title' )
					->from( 'categorylinks' )
					->join( 'linktarget', null, 'cl_target_id = lt_id' )
					->where( [
						'cl_from' => $row->page_id,
						'lt_namespace' => NS_CATEGORY,
					] )
					->caller( __METHOD__ )->fetchFieldValues();
			} else {
				$pageCats = $dbr->newSelectQueryBuilder()
					->select( 'cl_to' )
					->from( 'categorylinks' )
					->where( [ 'cl_from' => $row->page_id ] )
					->caller( __METHOD__ )->fetchFieldValues();
			}

			$articles[] = [
				'title' => $row->page_title,
				'summary' => $summary,
				'categories' => $pageCats,
			];
		}

		return $articles;
	}

	private function renderGist( array $stats, array $articles, array $frequentTitles, int $maxFrequent, int $summaryLength, int $maxSize, $wikiPageFactory ): string {
		$lines = [];
		$lines[] = 'Wiki Gist for AssistedSearch';
		$lines[] = 'Generated: ' . wfTimestampNow();
		$lines[] = "Stats: {$stats['articles']} articles";
		$lines[] = '';

		$frequentTitleSet = array_flip( array_keys( $frequentTitles ) );

		$lines[] = '=== ARTICLES ===';
		$lines[] = 'Format: [Title]\tcategories\tsummary';
		$articlesSize = 0;

		$mainPageDone = false;
		foreach ( $articles as $article ) {
			if ( !$mainPageDone && $article['title'] === wfMessage( 'mainpage' )->inContentLanguage()->text() ) {
				$entry = $this->formatArticleEntry( $article, $summaryLength * 15 );
				$articlesSize += strlen( $entry ) + 1;
				if ( $articlesSize > $maxSize ) {
					$lines[] = '(article list truncated)';
					break;
				}
				$lines[] = $entry;
				$mainPageDone = true;
			}
		}

		foreach ( $articles as $article ) {
			if ( isset( $frequentTitleSet[$article['title']] ) ) {
				$entry = $this->formatArticleEntry( $article, $summaryLength );
				$articlesSize += strlen( $entry ) + 1;
				if ( $articlesSize > $maxSize ) {
					$lines[] = '(article list truncated)';
					break;
				}
				$lines[] = $entry;
			}
		}

		foreach ( $articles as $article ) {
			$isMainPage = $article['title'] === wfMessage( 'mainpage' )->inContentLanguage()->text();
			$isFrequent = isset( $frequentTitleSet[$article['title']] );
			if ( $isMainPage || $isFrequent ) {
				continue;
			}
			$entry = $this->formatArticleEntry( $article, $summaryLength );
			$articlesSize += strlen( $entry ) + 1;
			if ( $articlesSize > $maxSize ) {
				$lines[] = '(article list truncated)';
				break;
			}
			$lines[] = $entry;
		}
		$lines[] = '';

		return implode( "\n", $lines ) . "\n";
	}

	private function formatArticleEntry( array $article, int $summaryLength ): string {
		$title = str_replace( '_', ' ', $article['title'] );
		$cats = implode( ',', array_map( static fn ( $c ) => str_replace( '_', ' ', $c ), $article['categories'] ) );
		$summary = str_replace( [ "\t", "\n", "\r" ], ' ', $article['summary'] ?? '' );
		return "[$title]\t$cats\t$summary";
	}

}

$maintClass = GenerateAssistedSearchGist::class;
require_once RUN_MAINTENANCE_IF_MAIN;
