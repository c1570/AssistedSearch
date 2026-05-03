# AssistedSearch

LLM-assisted wiki search using OpenRouter. Provides `Special:AssistedSearch`.

- User enters a query on the special page
- An LLM autonomously searches the wiki via tool calling (`search_wiki`, `retrieve_section`)
- Returns a ranked list of relevant article sections with relevance explanations
- Results are displayed in the user's locale
- Concurrent searches are limited via MediaWiki's `BagOStuff` lock mechanism
- All queries are logged to `AssistedSearch` log channel
- Optional wiki gist gives the LLM context about the wiki's structure
- Search feedback loop tracks frequently accessed articles for better gist generation

## Configuration

| Variable | Default | Description |
|---|---|---|
| `$wgAssistedSearchApiKey` | `""` | OpenRouter API key (required) |
| `$wgAssistedSearchModel` | `"openai/gpt-4o-mini"` | OpenRouter model identifier |
| `$wgAssistedSearchMaxToolRounds` | `3` | Max LLM tool-calling rounds per search |
| `$wgAssistedSearchMaxConcurrent` | `2` | Max concurrent searches |
| `$wgAssistedSearchGistFile` | `false` | Path to wiki gist file; included in LLM prompt if set |
| `$wgAssistedSearchFeedbackFile` | `false` | Path to JSONL feedback log for search result tracking |
| `$wgAssistedSearchMinArticleLength` | `500` | Min page length in bytes to include in gist |
| `$wgAssistedSearchGistMaxSize` | `80000` | Max total size in bytes for the articles section of the gist |

## Wiki Gist

A gist file gives the LLM context about the wiki's articles so it generates better search terms.

Generate it with the maintenance script (run via cron):

```
php maintenance/run.php AssistedSearch:generateAssistedSearchGist \
  --output=$IP/cache/assistedsearch-gist.txt \
  --feedback-file=$IP/cache/assistedsearch-feedback.jsonl
```

Available options:
- `--output` — output file path (default: `$wgAssistedSearchGistFile`)
- `--min-article-length` — minimum page length in bytes to include (default: 500)
- `--max-articles` — maximum number of articles to include (default: no limit)
- `--max-frequent` — max frequently accessed articles to include (default: 20)
- `--summary-length` — max chars per article summary (default: 200)
- `--max-size` — maximum total size in bytes for articles section (default: 80000)

The gist includes:
- **Articles**: all articles above the minimum length with a short summary and category membership
- **Frequently accessed**: articles that appear most often in search results (from feedback log), with longer summaries
- **Main page**: included with an extended summary (15× the normal length)

Each article's wikitext is cleaned before summarization: templates are removed, wikilinks are resolved to their display text, long italicized passages are dropped, and magic words (e.g. `__NOTOC__`) are stripped. Summaries are truncated at sentence boundaries where possible.

The maintenance script also prunes feedback entries older than 30 days.

## Logging

Search queries and the complete LLM conversation (messages, tool calls, tool results) are logged to the `AssistedSearch` channel. Enable debug-level logging to capture full conversation details:

```
$wgDebugLogGroups['AssistedSearch'] = [
    'destination' => '/path/to/assistedsearch.log',
    'level' => 'debug',
];
```

## Installation

```
wfLoadExtension( 'AssistedSearch' );
$wgAssistedSearchApiKey = 'sk-or-v1-...';
$wgAssistedSearchGistFile = "$IP/cache/assistedsearch-gist.txt";
$wgAssistedSearchFeedbackFile = "$IP/cache/assistedsearch-feedback.jsonl";
```
