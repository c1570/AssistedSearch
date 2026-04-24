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

## Wiki Gist

A gist file gives the LLM context about the wiki's categories and articles so it generates better search terms.

Generate it with the maintenance script (run via cron):

```
php maintenance/run.php generateAssistedSearchGist \
  --output=$IP/cache/assistedsearch-gist.txt \
  --feedback-file=$IP/cache/assistedsearch-feedback.jsonl
```

The gist includes:
- **Categories**: top-level categories and their subcategories (2 levels deep)
- **Articles**: all articles above `$wgAssistedSearchMinArticleLength` with a short summary and category membership
- **Frequently accessed**: articles that appear most often in search results (from feedback log), with longer summaries

The maintenance script also prunes feedback entries older than 30 days.

## Installation

```
wfLoadExtension( 'AssistedSearch' );
$wgAssistedSearchApiKey = 'sk-or-v1-...';
$wgAssistedSearchGistFile = "$IP/cache/assistedsearch-gist.txt";
$wgAssistedSearchFeedbackFile = "$IP/cache/assistedsearch-feedback.jsonl";
```
