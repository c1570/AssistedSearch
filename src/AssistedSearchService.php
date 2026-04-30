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
	private ?string $gistFile;
	private ?string $feedbackFile;
	private const MAX_RETRIES = 3;
	private const RETRY_DELAY_MS = 1000;

	private const SYSTEM_PROMPT = "You are a wiki search assistant. Your job is to find the most relevant article sections for a user's query.";

	private const GIST_INSTRUCTION = <<<PROMPT

The following is a summary of this wiki's contents (categories and articles). Use it to generate better search terms and understand the wiki structure:

```
PROMPT;

	private const TOOLS_INSTRUCTION = <<<'PROMPT'

You have three tools available:

1. **search_wiki(queries)** - Search the wiki for articles matching one or more queries. Each query should be individual keywords or short terms, NOT natural language phrases or sentences. MediaWiki search is keyword-based. Provide an array of 2-5 relevant keyword strings including synonyms and related terms. Returns sections from the top matching articles for each query, deduplicated by page. Also searches category pages — to search categories, prefix the term with "Category:" (e.g., "Category:Games"). Categories group related articles and are useful for discovering topic clusters.

2. **retrieve_section(section_id, direction)** - Get the previous or next section of an article relative to a given section identified by its section_id (format: "Article_Title" or "Article_Title#Section_Heading").

3. **submit_results(results)** - Submit your final search results. Call this tool when you have finished searching and identified the most relevant sections, or when instructed to.

Instructions:
- Start by extracting individual keywords and synonyms from the user's query, then call search_wiki with all of them at once. Do NOT use natural language phrases or sentences as search terms.
- To discover related articles on a topic, search for "Category:TopicName" (e.g., "Category:Games", "Category:Hardware"). The gist contains a list of available categories.
- Review the returned sections. If none are relevant, try different search terms in a new search_wiki call.
- If a section seems partially relevant, use retrieve_section to check adjacent sections.
- Once you have found the best results or when instructed to, call submit_results with an array of the most relevant sections (1-10 items, ranked from most to least relevant). If no sections are relevant, call submit_results with an empty array [].
- Do NOT respond with text — always call submit_results to provide your answer.
PROMPT;

	private const TOOLS = [
		[
			'type' => 'function',
			'function' => [
				'name' => 'submit_results',
				'description' => 'Submit your final search results. Provide an array of 1-10 result objects (relevant sections) ranked from most to least relevant, or an empty array if absolutely nothing was found.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'results' => [
							'type' => 'array',
							'items' => [
								'type' => 'object',
								'properties' => [
									'article_title' => [
										'type' => 'string',
										'description' => 'Title of the article',
									],
									'section_heading' => [
										'type' => 'string',
										'description' => 'Heading of the section',
									],
									'section_id' => [
										'type' => 'string',
										'description' => 'Section identifier in format "Article_Title#Section_Heading"',
									],
									'relevance_explanation' => [
										'type' => 'string',
										'description' => 'Brief explanation of why this section is relevant to the query',
									],
								],
								'required' => [ 'article_title', 'section_heading', 'section_id', 'relevance_explanation' ],
							],
							'description' => 'Array of relevant sections ranked from most to least relevant (1-10 items)',
						],
					],
					'required' => [ 'results' ],
				],
			],
		],
		[
			'type' => 'function',
			'function' => [
				'name' => 'search_wiki',
				'description' => 'Search the wiki for articles matching one or more queries. Returns results grouped by article. Each article has an article_title and a sections array. section_text is included for sections matching the search terms (or the intro) within a text budget; other sections have section_text set to null. Use retrieve_section to get full text for any section that interests you.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'queries' => [
							'type' => 'array',
							'items' => [ 'type' => 'string' ],
							'description' => 'Search terms to find relevant wiki articles',
						],
					],
					'required' => [ 'queries' ],
				],
			],
		],
		[
			'type' => 'function',
			'function' => [
				'name' => 'retrieve_section',
				'description' => 'Get the previous or next section of an article relative to a given section_id (format: "Article_Title" or "Article_Title#Section_Heading")',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'section_id' => [
							'type' => 'string',
							'description' => 'The section_id of the current section',
						],
						'direction' => [
							'type' => 'string',
							'enum' => [ 'previous', 'next' ],
							'description' => 'Whether to get the previous or next section',
						],
					],
					'required' => [ 'section_id', 'direction' ],
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
		LoggerInterface $logger,
		?string $gistFile = null,
		?string $feedbackFile = null
	) {
		$this->apiKey = $apiKey;
		$this->model = $model;
		$this->maxRounds = $maxRounds;
		$this->toolExecutor = $toolExecutor;
		$this->sectionExtractor = $sectionExtractor;
		$this->logger = $logger;
		$this->gistFile = $gistFile;
		$this->feedbackFile = $feedbackFile;
	}

	/**
	 * @return array<int, array{article_title: string, section_heading: string, section_id: string, section_url: string, relevance_explanation: string}>
	 * @throws \Exception
	 */
	public function search( string $userQuery, string $langCode = 'en' ): array {
		$this->logger->info( "AssistedSearch: Starting search for query: {query} (lang={lang})", [
			'query' => $userQuery,
			'lang' => $langCode,
		] );

		$client = new OpenRouterClient( $this->apiKey );

		$systemPrompt = self::SYSTEM_PROMPT;

		$gistContent = $this->loadGist();
		if ( $gistContent !== '' ) {
			$systemPrompt .= self::GIST_INSTRUCTION . "\n{$gistContent}\n```";
		}

		$systemPrompt .= self::TOOLS_INSTRUCTION;

		$systemPrompt .= "\n\nIMPORTANT: relevance_explanation in submit_results MUST use the language with code \"$langCode\".";

		$messages = [
			[ 'role' => 'system', 'content' => $systemPrompt ],
			[ 'role' => 'user', 'content' => $userQuery ],
		];

		$state = 'searching';
		$rounds = 0;

		while ( true ) {
			$rounds++;
			$this->logger->info( "AssistedSearch: Round {round} — state={state} (model={model})", [
				'round' => $rounds,
				'state' => $state,
				'model' => $this->model,
			] );

			$result = $this->callLlm( $client, $messages, [
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

			if ( !empty( $result['tool_calls'] ) ) {
				$submittedResults = $this->processToolCalls( $result['tool_calls'], $messages, $state );
				if ( $submittedResults !== null ) {
					$this->logConversation( $messages );
					$this->logger->info( "AssistedSearch: Got {count} results via submit_results", [ 'count' => count( $submittedResults ) ] );
					$this->writeFeedback( $userQuery, $submittedResults );
					return $submittedResults;
				}
				$this->logConversation( $messages );
			} else {
				$finishReason = $result['finish_reason'] ?? '';
				$this->logger->warning( "AssistedSearch: LLM returned no tool calls (state={state}, finish_reason={reason}). Full reply:\n{reply}", [
					'state' => $state,
					'reason' => $finishReason,
					'reply' => self::safeJsonEncode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ),
				] );
				if ( $finishReason === 'length' ) {
					$messages[] = [
						'role' => 'system',
						'content' => 'Your response was too long. Please give a shorter (tool calling only!) response.',
					];
					continue;
				}
			}

			if ( $state === 'searching' && $rounds >= $this->maxRounds ) {
				$this->logger->info( "AssistedSearch: Max rounds reached, transitioning to final state" );
				$messages[] = [
					'role' => 'system',
					'content' => 'STOP exploring and call submit_results NOW with your findings. relevance_explanation MUST use the language with code "' . $langCode . '". Submit 0 results only if there was absolutely no lead.',
				];
				$state = 'final';
				continue;
			}

			if ( $rounds >= 5 ) {
				$this->logger->warning( "AssistedSearch: Max total rounds (5) reached, returning empty results" );
				$this->logConversation( $messages );
				return [];
			}
		}
	}

	/**
	 * Process tool calls. Executes non-submit tools normally, returns results from submit_results.
	 *
	 * @param array $toolCalls
	 * @param array $messages Messages array to append tool results to
	 * @return array|null Extracted results if submit_results was called, null otherwise
	 */
	private function processToolCalls( array $toolCalls, array &$messages, string $state = 'searching' ): ?array {
		$submittedResults = null;

		foreach ( $toolCalls as $toolCall ) {
			$functionName = $toolCall['function']['name'];
			$arguments = json_decode( $toolCall['function']['arguments'], true );

			$this->logger->info( "AssistedSearch: Tool call: {tool}({args})", [
				'tool' => $functionName,
				'args' => self::safeJsonEncode( $arguments ?? [] ),
			] );

			if ( $functionName === 'submit_results' ) {
				$submittedResults = $this->extractSubmitResults( $arguments ?? [] );
				$messages[] = [
					'role' => 'tool',
					'tool_call_id' => $toolCall['id'],
					'content' => self::safeJsonEncode( [ 'status' => 'ok', 'count' => count( $submittedResults ) ] ),
				];
				continue;
			}

			if ( $state === 'final' ) {
				$this->logger->warning( "AssistedSearch: LLM called {tool} in final state, rejecting", [ 'tool' => $functionName ] );
				$messages[] = [
					'role' => 'tool',
					'tool_call_id' => $toolCall['id'],
					'content' => self::safeJsonEncode( [
						'error' => "You must call the submit_results tool now. Do not call $functionName — you have already used all your search rounds.",
					] ),
				];
				continue;
			}

			$toolResult = $this->executeTool( $functionName, $arguments ?? [] );

			$this->logger->info( "AssistedSearch: Tool result: {tool} returned {count} items", [
				'tool' => $functionName,
				'count' => count( $toolResult ),
			] );

			$messages[] = [
				'role' => 'tool',
				'tool_call_id' => $toolCall['id'],
				'content' => self::safeJsonEncode( $toolResult ),
			];
		}

		return $submittedResults;
	}

	/**
	 * @return array<int, array{article_title: string, section_heading: string, section_id: string, section_url: string, relevance_explanation: string}>
	 */
	private function extractSubmitResults( array $args ): array {
		$raw = $args['results'] ?? [];
		if ( !is_array( $raw ) ) {
			return [];
		}

		$results = [];
		foreach ( $raw as $item ) {
			$sectionId = $item['section_id'] ?? '';
			if ( !$sectionId || !isset( $item['article_title'], $item['section_heading'] ) ) {
				continue;
			}
			$results[] = [
				'article_title' => $item['article_title'],
				'section_heading' => $item['section_heading'],
				'section_id' => $sectionId,
				'section_url' => $this->sectionExtractor->makeFullUrl( $sectionId ),
				'relevance_explanation' => $item['relevance_explanation'] ?? '',
			];
		}

		return $results;
	}

	private function loadGist(): string {
		if ( !$this->gistFile || !file_exists( $this->gistFile ) ) {
			return '';
		}
		$content = file_get_contents( $this->gistFile );
		if ( $content === false ) {
			$this->logger->warning( "AssistedSearch: Failed to read gist file: {file}", [
				'file' => $this->gistFile,
			] );
			return '';
		}
		$this->logger->info( "AssistedSearch: Loaded gist ({bytes} bytes) from {file}", [
			'bytes' => strlen( $content ),
			'file' => $this->gistFile,
		] );
		return $content;
	}

	private function writeFeedback( string $query, array $results ): void {
		if ( !$this->feedbackFile ) {
			return;
		}
		$dir = dirname( $this->feedbackFile );
		if ( !is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}

		$ts = wfTimestampNow();
		$handle = fopen( $this->feedbackFile, 'a' );
		if ( !$handle ) {
			$this->logger->warning( "AssistedSearch: Failed to open feedback file: {file}", [
				'file' => $this->feedbackFile,
			] );
			return;
		}

		foreach ( $results as $result ) {
			fwrite( $handle, self::safeJsonEncode( [
				'query' => $query,
				'article_title' => $result['article_title'],
				'section_heading' => $result['section_heading'],
				'ts' => $ts,
			] ) . "\n" );
		}
		fclose( $handle );
	}

	private function executeTool( string $name, array $args ): array {
		try {
			switch ( $name ) {
				case 'search_wiki':
					return $this->toolExecutor->executeSearch( $args['queries'] ?? [ $args['query'] ?? '' ] );
				case 'retrieve_section':
					return $this->toolExecutor->executeRetrieve(
						$args['section_id'] ?? '',
						$args['direction'] ?? 'next'
					) ?? [ 'error' => 'Section not found' ];
				default:
					return [ 'error' => "Unknown tool: $name" ];
			}
		} catch ( \Exception $e ) {
			return [ 'error' => $e->getMessage() ];
		}
	}

	private function callLlm( OpenRouterClient $client, array $messages, array $options ): array {
		$lastException = null;
		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			try {
				return $client->chatEx( $messages, $this->model, $options );
			} catch ( \Exception $e ) {
				$lastException = $e;
				$retryable = str_contains( $e->getMessage(), '502' )
					|| str_contains( $e->getMessage(), '503' )
					|| str_contains( $e->getMessage(), '429' )
					|| str_contains( $e->getMessage(), 'timeout' )
					|| str_contains( $e->getMessage(), 'Provider returned error' );
				if ( !$retryable || $attempt === self::MAX_RETRIES ) {
					break;
				}
				$this->logger->warning( "AssistedSearch: LLM call failed (attempt {attempt}/{max}), retrying: {error}", [
					'attempt' => $attempt,
					'max' => self::MAX_RETRIES,
					'error' => $e->getMessage(),
				] );
				usleep( self::RETRY_DELAY_MS * 1000 * $attempt );
			}
		}
		throw $lastException;
	}

	private function logConversation( array $messages ): void {
		$this->logger->debug( "AssistedSearch: Conversation ({count} messages)\n{conversation}", [
			'count' => count( $messages ),
			'conversation' => self::safeJsonEncode( $messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ),
		] );
	}

	private static function safeJsonEncode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags | JSON_INVALID_UTF8_SUBSTITUTE );
	}
}
