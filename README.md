# silverstripe-rag-assistant

A floating AI referral widget for SilverStripe 4/5. It indexes your pages using OpenAI embeddings and directs visitors to the right page — it never answers from its own knowledge, only from your content.

**RAG** = Retrieval-Augmented Generation: embed your content → find the most relevant chunks via cosine similarity → send those as context to the language model.

## Requirements

- PHP 8.0+
- SilverStripe CMS 4 or 5 (`silverstripe/cms`)
- OpenAI API key
- MySQL (embeddings stored as comma-separated floats in a TEXT column)

## Installation

```bash
composer require xddesigners/silverstripe-rag-assistant
```

Run a dev/build after installation:

```bash
vendor/bin/sake dev/build flush=1
```

Assets are exposed automatically via `vendor-expose` during `composer install` or `composer update` — no extra steps required.

## Configuration

Set your OpenAI API key in `.env`:

```env
OPENAI_API_KEY="sk-..."
```

Override defaults in your project's `_config/myconfig.yml`:

```yaml
XD\RAGAssistant\Controllers\AssistantController:
  chat_model: 'gpt-4o-mini'
  embedding_model: 'text-embedding-3-small'
  embedding_dimensions: 512
  top_k: 5
  system_prompt: >
    You are a referral assistant for example.com. Your task is to refer
    visitors to the correct page. Only use information from the provided
    context. Always answer in Dutch.

XD\RAGAssistant\Tasks\RAGIndexTask:
  chunk_size: 800
  indexed_classes:
    - class: Page
      exclude_classes:
        - RedirectorPage
        - ErrorPage
    - class: SilverStripe\Blog\Model\BlogPost
      date_field: PublishDate
      date_offset: '-12 months'
```

### `indexed_classes` options

| Key | Description |
|---|---|
| `class` | Fully qualified class name to index |
| `exclude_classes` | Subclasses to skip (per entry) |
| `extra_fields` | Additional DB fields to include in the chunk text |
| `date_field` | Filter by this date field |
| `date_offset` | Only index records newer than this offset (e.g. `-12 months`) |
| `upcoming_via` | Related class to check for upcoming dates |
| `upcoming_via_relation` | Foreign key on the related class |
| `upcoming_date_field` | Date field on the related class — only indexes records with a future date |
| `limit` | Maximum number of records to index for this entry (no limit by default) |

By default there is no record limit — all pages matching the configured filters are indexed. Use `limit` to cap the number of indexed records per entry, for example to control token costs or indexing time on large datasets:

```yaml
XD\RAGAssistant\Tasks\RAGIndexTask:
  indexed_classes:
    - class: SilverStripe\Blog\Model\BlogPost
      date_field: PublishDate
      date_offset: '-12 months'
      limit: 100

    - class: App\Pages\EventPage
      upcoming_via: App\Models\EventDateTime
      upcoming_via_relation: EventID
      upcoming_date_field: StartDate
      limit: 50
```

### Excluding page classes globally

Use `excluded_page_classes` to block entire page types from appearing in results — regardless of which `indexed_classes` entry they come from. Subclasses of listed classes are also excluded.

Set it on **both** the task (prevents indexing) and the controller (filters query results immediately, without needing a re-index):

```yaml
XD\RAGAssistant\Tasks\RAGIndexTask:
  excluded_page_classes:
    - App\Pages\ProfilePage
    - App\Pages\AccountPage

XD\RAGAssistant\Controllers\AssistantController:
  excluded_page_classes:
    - App\Pages\ProfilePage
    - App\Pages\AccountPage
```

After changing `excluded_page_classes` on the task, re-run `RAGIndexTask` to remove the excluded pages from the index. The controller-side filter takes effect immediately for pages already in the index.

### Logging

Request logging is disabled by default. Enable it per project:

```yaml
XD\RAGAssistant\Controllers\AssistantController:
  enable_logging: true
```

Log entries are written to `silverstripe-rag-assistant.log` in the project root (`BASE_PATH`). The file is created automatically on first use. Override the filename via YAML:

```yaml
XD\RAGAssistant\Controllers\AssistantController:
  enable_logging: true
  log_file: 'logs/rag-assistant.log'  # relative to BASE_PATH
```

A typical conversation produces entries like:

```
[RAG] Question — ip:a3f2b1c8 history:1 "is er opleiding in groningen?"
[RAG] Embedding request — model:text-embedding-3-small
[RAG] Embedding response — HTTP:200 312ms
[RAG] Chat request — model:gpt-4o-mini
[RAG] Chat response — HTTP:200 1048ms
[RAG] Answer — sources:1 "Ja, in Groningen worden er opleidingen georganiseerd..."
```

Rate limit hits are logged at `warning` level, API errors at `error` level, and quota exhaustion at `critical` level. IP addresses are hashed (first 8 hex chars of MD5) for privacy.

### Optional module integrations (Blog, Events, etc.)

This module has **no dependency** on Blog, Events, or any other optional SilverStripe module. Classes listed in `indexed_classes` that are not installed are silently skipped during indexing — no errors, no fatal crashes.

To index Blog posts or Event pages, add them to `indexed_classes` in your project YAML **only when those modules are installed**:

```yaml
XD\RAGAssistant\Tasks\RAGIndexTask:
  indexed_classes:
    - class: Page
      exclude_classes:
        - SilverStripe\CMS\Model\RedirectorPage

    # Only add these if the modules are installed:
    - class: SilverStripe\Blog\Model\BlogPost
      date_field: PublishDate
      date_offset: '-12 months'

    - class: App\Pages\EventPage
      upcoming_via: App\Models\EventDateTime
      upcoming_via_relation: EventID
      upcoming_date_field: StartDate
```

## Adding the widget to a template

Add the include to your layout template, typically just before `</body>`:

```silverstripe
<% include Assistent %>
```

## Indexing content

Run the index task from the CLI or via `/dev/tasks`:

```bash
vendor/bin/sake dev/tasks/RAGIndexTask
```

This fetches all configured pages, splits them into chunks, embeds each chunk via OpenAI, and stores the results in the `RAGContentChunk` table. A binary cache is written to `silverstripe-cache/rag_chunks.bin` for fast loading on each request.

Re-run the task whenever content changes significantly, or set up a nightly cron job.

## Customising the widget appearance

Override CSS custom properties in your project stylesheet:

```css
:root {
    --rag-primary:      #your-brand-color;
    --rag-primary-dark: #your-brand-color-dark;
    --rag-panel-width:  360px;
    --rag-z-index:      9000;
}
```

## Building assets (development only)

The `client/dist/` files are pre-built and committed. You only need to run a build if you modify the source files in `client/src/`.

```bash
yarn install
yarn build    # one-off build
yarn watch    # rebuild on save
```

Requires Node.js 18+.

## API endpoint

The widget POSTs to `/api/assistant/ask`:

```json
{ "question": "..." }
```

Response:

```json
{
  "answer": "...",
  "sources": [
    { "title": "Page title", "url": "https://..." }
  ]
}
```
