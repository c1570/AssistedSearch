# AssistedSearch

LLM-assisted wiki search using OpenRouter. Provides `Special:AssistedSearch`.

- User enters a query on the special page
- An LLM autonomously searches the wiki via tool calling (`search_wiki`, `retrieve_section`)
- Returns a ranked list of relevant article sections with relevance explanations
- Results are displayed in the user's locale
- Concurrent searches are limited via MediaWiki's `BagOStuff` lock mechanism
- All queries are logged to `AssistedSearch` log channel

## Configuration

| Variable | Default | Description |
|---|---|---|
| `$wgAssistedSearchApiKey` | `""` | OpenRouter API key (required) |
| `$wgAssistedSearchModel` | `"openai/gpt-4o-mini"` | OpenRouter model identifier |
| `$wgAssistedSearchMaxToolRounds` | `3` | Max LLM tool-calling rounds per search |
| `$wgAssistedSearchMaxConcurrent` | `2` | Max concurrent searches |

## Installation

```
wfLoadExtension( 'AssistedSearch' );
$wgAssistedSearchApiKey = 'sk-or-v1-...';
```
