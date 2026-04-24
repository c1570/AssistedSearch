<?php

namespace MediaWiki\Extension\AssistedSearch;

use cheinisch\OpenRouterClient;
use Psr\Log\LoggerInterface;

class AssistedSearchService {

	private string $apiKey;
	private string $model;
	private int $maxRounds;
	private SearchToolExecutor $toolExecutor;
	private SectionExtractor $sectionExtractor;
	private LoggerInterface $logger;

	private const SYSTEM_PROMPT = <<<'PROMPT'
You are a wiki search assistant. Your job is to find the most relevant article sections for a user's query.

You have two tools available:

1. **search_wiki(query)** - Search the wiki for articles matching a query. Returns sections from the top matching articles. Think about synonyms and related terms to search for.

2. **retrieve_section(section_url, direction)** - Get the previous or next section of an article relative to a given section URL. Use this when a section seems partially relevant and you want to check if the adjacent section is more relevant.

Instructions:
- Start by generating good search terms from the user's query, then call search_wiki.
- Review the returned sections. If none are relevant, try different search terms.
- If a section seems partially relevant, use retrieve_section to check adjacent sections.
- Once you have found the best results, return a JSON array of the most relevant sections.

Your final response MUST be a valid JSON array (and nothing else) of objects with this format:
[
    {
        "article_title": "Title of the article",
        "section_heading": "Heading of the section",
        "section_url": "Full URL to the section",
        "relevance_explanation": "Brief explanation of why this section is relevant to the query"
    }
]

Rank the results from most relevant to least relevant. Include only sections that are genuinely relevant (at least 1, at most 5). If no sections are relevant, return an empty array [].
PROMPT;

	private const TOOLS = [
		[
			'type' => 'function',
			'function' => [
				'name' => 'search_wiki',
				'description' => 'Search the wiki for articles matching a query and return their sections',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'query' => [
							'type' => 'string',
							'description' => 'Search terms to find relevant wiki articles',
						],
					],
					'required' => [ 'query' ],
				],
			],
		],
		[
			'type' => 'function',
			'function' => [
				'name' => 'retrieve_section',
				'description' => 'Get the previous or next section of an article relative to a given section URL',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'section_url' => [
							'type' => 'string',
							'description' => 'The URL of the current section',
						],
						'direction' => [
							'type' => 'string',
							'enum' => [ 'previous', 'next' ],
							'description' => 'Whether to get the previous or next section',
						],
					],
					'required' => [ 'section_url', 'direction' ],
				],
			],
		],
	];

	public function __construct(
		string $apiKey,
		string $model,
		int $maxRounds,
		SearchToolExecutor $toolExecutor,
		SectionExtractor $sectionExtractor,
		LoggerInterface $logger
	) {
		$this->apiKey = $apiKey;
		$this->model = $model;
		$this->maxRounds = $maxRounds;
		$this->toolExecutor = $toolExecutor;
		$this->sectionExtractor = $sectionExtractor;
		$this->logger = $logger;
	}

	/**
	 * @return array<int, array{article_title: string, section_heading: string, section_url: string, relevance_explanation: string}>
	 * @throws \Exception
	 */
	public function search( string $userQuery, string $langCode = 'en' ): array {
		$this->logger->info( "AssistedSearch: Starting search for query: {query} (lang={lang})", [
			'query' => $userQuery,
			'lang' => $langCode,
		] );

		$client = new OpenRouterClient( $this->apiKey );

		$systemPrompt = self::SYSTEM_PROMPT . "\n\nIMPORTANT: You MUST respond in the language with code \"$langCode\". All text in your final answer (relevance_explanation, section_heading, article_title) must be in that language.";

		$messages = [
			[ 'role' => 'system', 'content' => $systemPrompt ],
			[ 'role' => 'user', 'content' => $userQuery ],
		];

		for ( $round = 0; $round < $this->maxRounds; $round++ ) {
			$this->logger->info( "AssistedSearch: Round {round}/{max} — calling LLM (model={model})", [
				'round' => $round + 1,
				'max' => $this->maxRounds,
				'model' => $this->model,
			] );

			$result = $client->chatEx( $messages, $this->model, [
				'tools' => self::TOOLS,
				'tool_choice' => 'auto',
			] );

			$assistantMessage = [
				'role' => 'assistant',
				'content' => $result['content'] ?? null,
			];
			if ( !empty( $result['tool_calls'] ) ) {
				$assistantMessage['tool_calls'] = $result['tool_calls'];
			}
			$messages[] = $assistantMessage;

			if ( empty( $result['tool_calls'] ) ) {
				$this->logger->info( "AssistedSearch: LLM returned final response (no tool calls)" );
				$this->logger->debug( "AssistedSearch: Final LLM content: {content}", [
					'content' => $result['content'] ?? '',
				] );
				$parsed = $this->parseFinalResponse( $result['content'] ?? '' );
				$this->logger->info( "AssistedSearch: Parsed {count} results", [ 'count' => count( $parsed ) ] );
				return $parsed;
			}

			foreach ( $result['tool_calls'] as $toolCall ) {
				$functionName = $toolCall['function']['name'];
				$arguments = json_decode( $toolCall['function']['arguments'], true );

				$this->logger->info( "AssistedSearch: Tool call: {tool}({args})", [
					'tool' => $functionName,
					'args' => json_encode( $arguments ?? [] ),
				] );

				$toolResult = $this->executeTool( $functionName, $arguments ?? [] );

				$this->logger->info( "AssistedSearch: Tool result: {tool} returned {count} items", [
					'tool' => $functionName,
					'count' => count( $toolResult ),
				] );
				$this->logger->debug( "AssistedSearch: Tool result detail: {detail}", [
					'detail' => json_encode( $toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				] );

				$messages[] = [
					'role' => 'tool',
					'tool_call_id' => $toolCall['id'],
					'content' => json_encode( $toolResult ),
				];
			}
		}

		$this->logger->info( "AssistedSearch: Max rounds ({max}) reached, requesting final answer", [
			'max' => $this->maxRounds,
		] );
		$messages[] = [
			'role' => 'user',
			'content' => 'Please provide your final answer as a JSON array now, based on all the information you have gathered.',
		];
		$result = $client->chatEx( $messages, $this->model, [] );
		$parsed = $this->parseFinalResponse( $result['content'] ?? '' );
		$this->logger->info( "AssistedSearch: Final answer parsed {count} results", [ 'count' => count( $parsed ) ] );
		return $parsed;
	}

	private function executeTool( string $name, array $args ): array {
		try {
			switch ( $name ) {
				case 'search_wiki':
					return $this->toolExecutor->executeSearch( $args['query'] ?? '' );
				case 'retrieve_section':
					return $this->toolExecutor->executeRetrieve(
						$args['section_url'] ?? '',
						$args['direction'] ?? 'next'
					) ?? [ 'error' => 'Section not found' ];
				default:
					return [ 'error' => "Unknown tool: $name" ];
			}
		} catch ( \Exception $e ) {
			return [ 'error' => $e->getMessage() ];
		}
	}

	/**
	 * @return array<int, array{article_title: string, section_heading: string, section_url: string, relevance_explanation: string}>
	 */
	private function parseFinalResponse( string $content ): array {
		$json = $content;
		if ( preg_match( '/```(?:json)?\s*(\[[\s\S]*?\])\s*```/', $content, $matches ) ) {
			$json = $matches[1];
		} elseif ( preg_match( '/(\[[\s\S]*\])\s*$/', $content, $matches ) ) {
			$json = $matches[1];
		}

		$parsed = json_decode( $json, true );
		if ( !is_array( $parsed ) ) {
			return [];
		}

		$results = [];
		foreach ( $parsed as $item ) {
			if ( isset( $item['article_title'], $item['section_heading'], $item['section_url'] ) ) {
				$results[] = [
					'article_title' => $item['article_title'],
					'section_heading' => $item['section_heading'],
					'section_url' => $item['section_url'],
					'relevance_explanation' => $item['relevance_explanation'] ?? '',
				];
			}
		}

		return $results;
	}
}
