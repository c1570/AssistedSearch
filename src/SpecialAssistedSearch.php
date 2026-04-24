<?php

namespace MediaWiki\Extension\AssistedSearch;

use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Logger\LoggerFactory;
use Wikimedia\ObjectCache\EmptyBagOStuff;

class SpecialAssistedSearch extends SpecialPage {

	public function __construct() {
		parent::__construct( 'AssistedSearch' );
	}

	public function execute( $par ) {
		$this->setHeaders();
		$output = $this->getOutput();
		$request = $this->getRequest();

		$apiKey = $this->getConfig()->get( 'AssistedSearchApiKey' );
		if ( !$apiKey ) {
			$output->addWikiMsg( 'assistedsearch-error-no-api-key' );
			return;
		}

		$output->addHTML( $this->getSearchForm() );
		$output->addWikiMsg( 'assistedsearch-privacy-notice' );
		$output->addWikiMsg( 'assistedsearch-time-notice' );

		$query = $request->getText( 'query' );
		if ( $query === '' ) {
			return;
		}

		$output->addHTML( '<h2>' . htmlspecialchars(
			$this->msg( 'assistedsearch-results-heading', $query )->text()
		) . '</h2>' );

		$config = $this->getConfig();
		$maxConcurrent = $config->get( 'AssistedSearchMaxConcurrent' );

		$cache = MediaWikiServices::getInstance()->getMainObjectStash();
		if ( $cache instanceof EmptyBagOStuff ) {
			$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();
		}

		$lock = null;
		for ( $i = 0; $i < $maxConcurrent; $i++ ) {
			$lock = $cache->getScopedLock( "assistedsearch:slot:$i", 0, 180 );
			if ( $lock ) {
				break;
			}
		}

		if ( !$lock ) {
			$output->addWikiMsg( 'assistedsearch-error-concurrency' );
			return;
		}

		try {
			$service = $this->createService( $apiKey );
			$results = $service->search( $query, $this->getLanguage()->getCode() );

			if ( empty( $results ) ) {
				$output->addWikiMsg( 'assistedsearch-no-results' );
				return;
			}

			$output->addHTML( $this->renderResults( $results ) );
		} catch ( \Exception $e ) {
			$output->addWikiMsg( 'assistedsearch-error-llm', $e->getMessage() );
		}
	}

	private function getSearchForm(): string {
		$query = htmlspecialchars( $this->getRequest()->getText( 'query' ) );
		$buttonText = $this->msg( 'assistedsearch-search-button' )->text();
		$placeholder = $this->msg( 'assistedsearch-placeholder' )->text();
		$actionUrl = htmlspecialchars( $this->getPageTitle()->getLocalURL() );

		return <<<HTML
<form method="get" action="{$actionUrl}">
	<input type="text" name="query" value="{$query}" placeholder="{$placeholder}" size="60" autofocus />
	<input type="submit" value="{$buttonText}" />
</form>
HTML;
	}

	/**
	 * @param array<int, array{article_title: string, section_heading: string, section_url: string, relevance_explanation: string}> $results
	 */
	private function renderResults( array $results ): string {
		$html = '<ol class="assistedsearch-results">';
		foreach ( $results as $i => $result ) {
			$rank = $i + 1;
			$articleTitle = htmlspecialchars( $result['article_title'] );
			$sectionHeading = htmlspecialchars( $result['section_heading'] );
			$sectionUrl = htmlspecialchars( $result['section_url'] );
			$relevance = htmlspecialchars( $result['relevance_explanation'] );

			$html .= <<<HTML
<li class="assistedsearch-result">
	<strong>{$rank}. {$sectionHeading}</strong> ({$articleTitle})<br />
	{$relevance}<br />
	<a href="{$sectionUrl}">{$sectionUrl}</a>
</li>
HTML;
		}
		$html .= '</ol>';
		return $html;
	}

	private function createService( string $apiKey ): AssistedSearchService {
		$config = $this->getConfig();
		$model = $config->get( 'AssistedSearchModel' );
		$maxRounds = $config->get( 'AssistedSearchMaxToolRounds' );
		$serverUrl = $config->get( 'Server' );

		$sectionExtractor = new SectionExtractor( $serverUrl );
		$toolExecutor = new SearchToolExecutor( $sectionExtractor );
		$logger = LoggerFactory::getInstance( 'AssistedSearch' );

		return new AssistedSearchService( $apiKey, $model, $maxRounds, $toolExecutor, $sectionExtractor, $logger );
	}
}
