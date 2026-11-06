# Searching the Symfony docs with a small local model

This guide explains how the search was designed, why each decision was made, and how to run and measure it yourself. The app in this folder is the proof. The method is the point.

The goal: someone describes a problem in their own words, for example "send people somewhere else when they type a wrong password", and gets the right passage from the Symfony docs, without any cloud API. The embedding model is `embeddinggemma-2`, 303 MB.

## 1. The result

Measured inside this app with `php bin/console app:docs:eval`. A result counts as correct only if it comes from an accepted page and literally contains the answer.

Reworded questions, the hard case. They were written to avoid the wording of the page that answers them.

| Correct passage in the top 10 | Fresh set (57) | Held-out set (47) |
|---|---:|---:|
| Keywords only (BM25) | 54% | 55% |
| Symfony AI's built-in hybrid query | 5% | 0% |
| **This app** | **89%** | **83%** |
| This app, reordered by a local LLM (about 55 s per question) | 95% | 83% |
| This app, answer anywhere on a returned page (one click through the hit's link) | 100% | 87% |

On reworded questions "This app" and plain vector search score the same: the router only adds keywords for code-shaped or very short queries. The 89% is Symfony AI's `VectorQuery` with embeddinggemma-2; the custom code earns its keep on identifiers (top 1: 65% to 85% fresh, 70% to 80% held-out).

Exact identifiers such as `PhpArrayAdapter` or `max_host_connections`:

| Questions (fresh set: 20, held-out: 20) | Top 1 | Top 3 | Top 5 | Top 10 |
|---|---:|---:|---:|---:|
| This app, fresh set | 85% | 100% | 100% | 100% |
| This app, held-out set | 80% | 90% | 95% | 100% |

Cost and speed:

| | |
|---|---|
| Model | 303 MB, 768 dimensions, no GPU on the host |
| Corpus | 461 pages and 122 include files, 6,370 chunks, about 1.19M estimated tokens |
| Embedding the corpus | roughly 45 minutes at the measured 400-500 tokens/s (calculated, not timed end to end) |
| Index in Postgres | 96 MB database: chunks 40 MB, BM25 terms 49 MB |
| Rebuilding the index from shipped vectors | 12.9 s, no model calls |
| Embedding a question | typically 65-140 ms in the app on the LAN host; 46 ms direct to Ollama on the laptop; about 2 s when the model has to load |
| Vector search | about 30 ms exact scan of every chunk |
| Keyword search | about 40-90 ms |
| Whole search | median 126-129 ms on the LAN host, 89-93 ms on the laptop |
| Per-query cost | none beyond electricity |

**What this does not show.** Reworded recall is good, not exact: on these sets 11-17% of reworded questions do not reach the top 10. In about half of those misses the hit's page holds the answer, only in a different chunk. I wrote the questions myself and have looked at them more than once. With 57 questions, one question moves a score by about 2 points. Treat this as honest engineering evidence, not a published benchmark.

## 2. Architecture

```
INDEXING (once, then only what changed)

  .rst + .rst.inc files
        |
        v
  Chunker                     headings -> sections -> whole blocks, up to 512 est. tokens
        |                     text   = one config format, what gets embedded
        |                     source = every format, what the reader sees
        |                     id     = sha1(file, breadcrumb, text), so unchanged = same id
        v
  ChunkIndex.sync             skip ids already stored, remove ids gone from the docs
        |
        |-- shipped vector exists? ---------------------.
        |                                               |
        v                                               |
  Embedder.documents ---> Ollama: embeddinggemma-2      |
        |   "title: Page > Section | text: ..."         |
        v                                               v
  PostgreSQL + pgvector
        chunks       (id, metadata JSONB, embedding vector(768), tokens)   <- Symfony AI store
        chunk_terms  (term, chunk_id, tf)                                  <- BM25


SEARCH (every question)

  question
        |
        v
  Embedder.query ---> Ollama: "task: search result | query: ..."
        |             host down? use the saved vector and say so; none saved? keywords only
        v
  Retriever.vector            exact cosine scan, top 50
        |
        v
  looks like code, or one or two words?
        |-- no  --> vector ranking as is
        |-- yes --> Retriever.keyword (BM25, top 50) + reciprocal rank fusion, k = 60
        v
  optional: Reranker          local LLM reorders the top 20; only integers are read from its reply
        |
        v
  top 10, each with its source text
```

| Piece | File | Job |
|---|---|---|
| Chunker | `src/Docs/Chunker.php` | RST to chunks, the embedding input formats, the routing rule |
| Index | `src/Docs/ChunkIndex.php` | Incremental sync, shipped vectors, BM25 terms |
| Embedder | `src/Docs/Embedder.php` | Symfony AI Platform to Ollama, unit vectors, saved-vector fallback |
| Retriever | `src/Docs/Retriever.php` | Vector search through the Symfony AI store, BM25 in SQL, fusion |
| Reranker | `src/Docs/Reranker.php` | Optional LLM reorder through the Symfony AI Platform |
| Measurement | `src/Docs/TestSet.php`, `src/Command/EvalCommand.php` | What "correct" means, recall at each cutoff |
| Ingest | `src/Command/IngestCommand.php` | `app:docs:ingest` |
| Web | `src/Controller/SearchController.php`, `templates/search.html.twig` | Side-by-side comparison page |

## 3. Run it

You need PHP 8.4 with `pdo_pgsql`, Composer, Postgres with pgvector, and an Ollama server with the model.

**Step 1. Create the database.** The app uses an existing pgvector server; here it is the `pgvector/pgvector:pg17` container on port 5434.

```bash
docker exec <container> psql -U <user> -d postgres -c "create database docs_search"
docker exec <container> psql -U <user> -d postgres -c "create database docs_search_test"
```

The migration runs `CREATE EXTENSION IF NOT EXISTS vector`, which needs a role allowed to create extensions. Otherwise create it once in both databases as an administrator.

**Step 2. Point the app at your services** in `.env.local`, which git ignores. For the tests, put the same `DATABASE_URL` in `.env.test.local`; the test environment adds `_test` to the database name.

```bash
DATABASE_URL="postgresql://USER:PASSWORD@127.0.0.1:5434/docs_search?serverVersion=17&charset=utf8"
OLLAMA_HOST_URL=http://192.168.50.221:11434
```

**Step 3. Start it.** This installs dependencies, creates the tables, loads the shipped vectors and serves the page. It is safe to run again.

```bash
./start
# Open http://127.0.0.1:8150   (PORT=9000 ./start to change it)
```

**Step 4. Try these.** The examples are on the page.

| Question | Keywords | This app |
|---|---:|---:|
| send people somewhere else when they type a wrong password | not in top 10 | 1 |
| stop validating a field after the first rule that fails | not in top 10 | 1 |
| tell if code is running in a persistent process mode rather than per request | not in top 10 | 1 |
| why does my post-login account check run on each API request | not in top 10 | 3 |
| PhpArrayAdapter | 1 | 1 |
| move an old standard edition project to the new recipe tool | 2 | not in top 10 |

The last one is deliberate. It is a real miss, and showing it makes the rest believable.

**Step 5. Measure it yourself.**

```bash
php bin/phpunit                                                # tests, the embedding model is faked
php bin/console app:docs:eval                                  # recall, about 7 minutes
php bin/console app:docs:eval --modes=rerank --sets=fresh      # with the LLM, about a minute per question on CPU
```

**Without the LAN host.** A second Ollama can run on the laptop:

```bash
docker compose --profile laptop up -d
docker compose exec ollama ollama pull embeddinggemma-2:270m-q8_0-text
# then in .env.local: OLLAMA_HOST_URL=http://127.0.0.1:11435
```

If the pull times out because containers have no outbound network, which happened here, download on the host and copy the files in:

```bash
R=https://registry.ollama.ai/v2/library/embeddinggemma-2
curl -s -H 'Accept: application/vnd.docker.distribution.manifest.v2+json' $R/manifests/270m-q8_0-text -o manifest.json
C=docs-search-ollama-1; D=/root/.ollama/models
docker exec $C mkdir -p $D/blobs $D/manifests/registry.ollama.ai/library/embeddinggemma-2
for d in $(grep -o 'sha256:[0-9a-f]*' manifest.json); do
  f=${d/:/-}; curl -sL $R/blobs/$d -o $f
  [ "$(shasum -a 256 $f | cut -d' ' -f1)" = "${d#sha256:}" ] && docker cp $f $C:$D/blobs/$f || echo "BAD DIGEST $d"
done
docker cp manifest.json $C:$D/manifests/registry.ollama.ai/library/embeddinggemma-2/270m-q8_0-text
docker compose --profile laptop restart ollama
```

Measured on a laptop-class Intel i5-8500B with no GPU, inside Docker:

| | Laptop | LAN host |
|---|---:|---:|
| Embedding a question, median of 23, direct to Ollama | 46 ms | not measured the same way |
| Whole search, median, fresh set | 89 ms | 126 ms |
| Whole search, median, held-out set | 93 ms | 129 ms |
| Recall, top 1 to top 20, both sets | same | same |
| Recall, top 50, reworded, held-out | 89.4% | 87.2% |

The two machines' vectors are almost identical (cosine 0.99997), not bit-identical, and one question moved at depth 50.

The model takes 2.2 s to load after a restart, and 13.7 s the very first time. Ollama also unloads it when idle, so run one search just before a demo. The reorder button needs `gemma4`, which the laptop does not have; there the page says so and keeps the original order.

A question that was asked before keeps its vector, so vector search still works for it with no model running. The page says when that happens. A new question with no model falls back to keywords, and the page says that too.

**Another provider: Google Gemini.** Ollama is the default. Gemini has a free embedding tier and is a configuration change, not a code change:

1. In `config/packages/ai.yaml`, comment out the `ollama` platform, uncomment `gemini`, and set the vectorizer to `platform: 'ai.platform.gemini'` and `model: '%app.embedding_model%?dimensions=768'` (the `vector(768)` column needs 768; Gemini's default is 3072).
2. In `.env.local`:

   ```bash
   GEMINI_API_KEY=...
   EMBEDDING_MODEL=gemini-embedding-001
   RERANK_MODEL=gemini-2.5-flash
   RERANK_OPTIONS='{"maxOutputTokens":60,"thinkingConfig":{"thinkingBudget":0}}'
   DATABASE_URL=...docs_search_gemini...   # a separate database keeps the Ollama index intact
   ```

> **Warning: rebuild before serving.** Search does not check which model built the index. Change `EMBEDDING_MODEL` on a database built with another model and the page keeps answering, labelled "live", with rankings from two unrelated vector spaces (both are 768 dimensions, so nothing rejects them), and the saved question vectors are overwritten with the new model's. Use a separate database, or empty `chunks`, `chunk_terms`, `query_vectors` and `index_meta`, and run ingest before the first search.

3. `php bin/console doctrine:migrations:migrate -n && php bin/console app:docs:ingest`. The shipped vectors belong to the default model and are skipped, so every chunk is embedded once. A rate-limit stop loses nothing: each batch is committed, and running ingest again resumes. An index built with one model refuses another model's vectors.

`tests/Docs/ProviderPayloadTest.php` pins the exact requests: the Ollama ones are byte-for-byte what built the shipped vectors, and the Gemini ones prove the commented configuration is valid. Not measured: Gemini's recall on the question sets (`app:docs:eval` once you have a key), and whether the `title: … | text: …` prefixes, written for embeddinggemma, help or hurt it. Keep in mind that every visitor's question, and the docs at ingest, then go to Google, and that a paid key behind a public search box spends money per question. Only one platform is configured at a time; mixing (Gemini embeddings, Ollama reranker) needs `#[Target]` on the reranker's platform argument.

## 4. How it was built, step by step

### Step 1. Define "found" before building anything

Hit = a top-k result from an accepted page that literally contains the answer string. "Accepted page" means the whole path matches the question's regex: an earlier version anchored only the end, so `lock\.rst` also accepted `components/clock.rst` (31 of 244 questions affected, one held-out question credited at rank 1 from the wrong page).

A second, looser number is reported next to it: a page hit, where the answer is anywhere on the hit's page. It is what a reader gets by following the hit's link to symfony.com, and it shows how many misses are the right page with the wrong chunk.

This matters because the definition does not depend on how pages were chunked, so different chunkers can be compared fairly. It also fails when an answer was split across two chunks, which is exactly the failure a chunker must not cause.

Questions are grouped by the job they test: `para` for reworded questions and `ident` for exact identifiers. Everything is reported per group, because a blended score hides that they fail differently.

### Step 2. Measure a naive baseline

Fixed windows of 4,000 characters with 400 overlap, on a subset of the docs:

| | Top 1 | Top 10 | Answers intact |
|---|---:|---:|---:|
| Naive windows | 49% | 88% | 96/100 |
| Heading-based, 512 tokens | 58% | 92% | 100/100 |

Top-10 recall looks close, which is why measuring only top-10 misleads. Naive windows split 4 answers in two, and each result hands the reader about 1,000 tokens of raw markup. Heading-based chunks put the right passage first more often and keep every answer whole.

### Step 3. Chunk along the document's structure

The decisions, each of which came from reading what failed:

- **Split at headings, then pack whole blocks.** A code block stays whole with its directive. A paragraph ending in `:` stays with what it introduces.
- **Inline `.. include::`.** Every validation constraint's shared options live in include files. Skipping them loses real answers.
- **Embed one configuration format, keep all for the reader.** Symfony shows the same config as YAML, XML and PHP. Embedding all three is noise: top-5 recall on reworded questions was 79% with the first format and 76% with all. XML is never the embedded format.
- **Put the breadcrumb in the model's `title:` field.** Without it, top-1 fell from 58% to 45%. A passage like "Options > message" means nothing without knowing the page it belongs to.
- **Parse by lines, not with a full RST parser.** docutils fails on Sphinx directives unless Sphinx is loaded, and these docs are regular enough for line rules.

Chunk size, on the same subset:

| Budget | Top 1 | Top 10 |
|---|---:|---:|
| 256 tokens | 53% | 89% |
| **512 tokens** | **58%** | **92%** |
| 1024 tokens | 57% | 93% |

512 and 1024 are a tie: a 1-point difference on 100 questions is noise. 512 costs less to embed and gives the reader less to wade through.

The budget is also well below a limit found in testing: Ollama silently truncated input at 2,048 tokens with no error, despite the model card's 8K. A larger chunk would have embedded only its beginning and given no warning.

### Step 4. Use the model's input format

| Query input | Reworded top 10, held-out |
|---|---:|
| `task: search result \| query: ...` | 83% |
| `task: code retrieval \| query: ...` | 79% |
| no prefix | 79% |

Documents use `title: {breadcrumb} | text: {text}`. The gain is modest, about 2 questions in 47, and it costs nothing. Dimensions stay at 768: cutting to 256 dropped held-out reworded recall from 83% to 72%.

### Step 5. Find out what each search method is good at

| Top 10 | Reworded, held-out | Identifiers, held-out |
|---|---:|---:|
| Vectors only | 83% | 95% |
| Keywords only | 55% | 100% |
| Always fuse both | 68% | 100% |
| **Route by question shape** | **83%** | **100%** |

Neither method is enough. The tempting answer, fusing them on every query, loses 15 points on reworded questions: keyword hits for common words push good vector results down.

So a query only uses keywords when it looks like code (`::`, `\`, `_`, `#[`, `$`, `a:b`, `a/b`, `a+b`, camelCase) or has at most two words. Otherwise it stays vector only.

### Step 6. Test on questions the tuning never saw

This is the most important lesson in the project. The first routing rule also treated acronyms and hyphens as code. On reworded tuning questions it scored 92%. On new reworded questions it scored 77%, worse than plain vectors at 83%, because plain questions contain words like "HTTPS" and "multi-line". The rule was fixed, and a test now pins those exact questions.

So there are three question sets:

| Set | Questions | How it was made |
|---|---:|---|
| `tuning.tsv` | 100 | Used to make decisions. Its numbers are flattering and not reported as results |
| `holdout.tsv` | 67 | Written later, checked after decisions were made |
| `fresh.tsv` | 77 | Pages picked at random with a seed, accepted pages fixed before any search ran |

### Step 7. Try to beat it, and keep what survives

Everything below was measured. Most of it lost.

| Idea | Result | Decision |
|---|---|---|
| LLM rerank of top 20 (gemma4) | Top 5 on fresh 84% to 95%, held-out 72% to 79%; top 1 on fresh 49% to 60%, held-out 40% to 53%; 53-55 s per query on CPU. Held-out top 10 stays at 83% because the answer was never in the 20 it reads. Identifiers: top 1 fresh 85% to 80% (one question drops to top 3), top 3 held-out 90% to 100% | Opt-in button |
| LLM rerank with a faster model (lfm3) | Lowered held-out top 5 from 72% to 68% | Rejected |
| LLM rerank of top 50 | About 2 minutes per query | Rejected |
| HyDE: embed an LLM's imagined answer | gemma4: better top ranks, worse top 10 on held-out (81%), 12 s per query. lfm3: lost on held-out | Rejected |
| LLM-written questions per chunk at index time | Looked like 96.5% with rivals barely expanded. With the top-20 rivals expanded too, 91%/83%, and top 5 down on held-out. About 8.5 hours of LLM time | Rejected |
| Several vectors per chunk | At most 1 extra question | Rejected |
| Returning neighbouring chunks | 1 extra question at twice the tokens | Rejected |
| Demote word-for-word repeats (an included option such as `payload` appears on 76 pages) | Fresh top 10 87.7% to 89.5%, held-out unchanged, tuning top 20 down 1 question; no repeat paragraph fills the list | Kept |
| At most 3 hits per page, the rest moved down | Tuning top 10 85.5% to 88.7%, but held-out 83.0% to 76.6% and one identifier lost: the answer is often the 4th chunk of a page | Rejected |
| Fuse BM25 into long questions with a low weight (0.03 to 1, or only its top 1-3) | Every variant lost at top 1-5 on tuning, none gained at top 10 | Rejected |
| Drop the `contributing/` pages | One held-out question at top 20, nothing in the top 10; would break chunk parity with Python | Rejected |
| A larger model (qwen3) | 30 tokens/s against 400-500, about 10 hours for the corpus. Stopped before a result, to stay on embeddinggemma-2 | Not measured |

The question-expansion result is worth remembering. A partial experiment flatters itself: if only the correct chunk gets extra text, of course it ranks higher. Give its rivals the same treatment before believing the gain.

### Step 8. Move to Postgres without losing anything

The research ran in Python and SQLite. Moving it was a chance to lose all of it silently, so the port was held to two checks:

1. **The PHP chunker must produce the same chunks.** `ChunkerTest` compares all 6,370 chunk ids, their order, and a hash of each `source` against the Python output. This caught a real bug during the port: Python skipped XML when picking the embedded format and the first PHP version did not, which produced 6,371 chunks instead of 6,370.
2. **The app must reproduce the measured recall.** It does, to three decimals, on the fresh and held-out sets: reworded vectors 0.877 and 0.830, keywords 0.544 and 0.553. Later runs moved one tuning question at rank 1 (0.452 to 0.435, stable over three repeats); the cause was not established, and the host's vectors are bit-identical on repeat.

Identical ids also meant the vectors could ship with the app (`data/seed_vectors.bin`, 19.6 MB), so setup takes seconds rather than most of an hour.

### Step 9. Decide what Symfony AI should do, based on measurement

**Platform: all model calls, configured in `ai.yaml`.** The embedder takes the bundle's `VectorizerInterface` (`ai.vectorizer.docs`), the reranker takes `PlatformInterface`, and neither knows which provider is behind them. Model names come from `.env` (`EMBEDDING_MODEL`, `RERANK_MODEL`), provider-specific call options from `RERANK_OPTIONS`. The JSON schema the reranker asks for goes through `response_format`, which every bridge translates (Ollama `format`, Gemini `responseJsonSchema`), and the bundle's structured-output subscriber hands the reply back decoded. The cost: with a name instead of a model object, Ollama's catalog sends a `POST /api/show` before each call, a few milliseconds on the LAN.

**Store: vectors, yes.** `VectorQuery` with cosine distance does exactly what is needed, and writes go through it too.

**Store: `HybridQuery` and `TextQuery`, no.** They were the obvious choice, so they were measured before being dismissed:

| Top 10, reworded | Fresh | Held-out |
|---|---:|---:|
| `HybridQuery` | 5% | 0% |
| `TextQuery` | 5% | 0% |
| This app | 89% | 83% |

Reading the store's SQL explains it. `plainto_tsquery` ANDs every word, and `WHERE ... @@` then discards every row that lacks one of them. A reworded question has words the page does not, so no row survives, and the vector half never gets a say:

```sql
select count(*) from chunks
where to_tsvector('english', metadata->>'_text')
   @@ plainto_tsquery('english', 'send people somewhere else when they type a wrong password');
-- 0
```

This is not a bug report: on identifiers those queries do well, at 100% in the top 10, and they suit a query that really is keywords. They also compute `to_tsvector` per row with no index, at about 650-800 ms per query.

**BM25 in SQL instead of `ts_rank`.** `ts_rank` ignores how rare a word is, so a rare identifier would count no more than "the". That would also make the comparison dishonest: a weak keyword baseline flatters vectors. `chunk_terms` is a small inverted index, and `Retriever::keyword` computes the same formula as SQLite FTS5 (k1 = 1.2, b = 0.75) in one query. `rotating_file` stays one term. It matched the SQLite numbers.

**Fusion by rank, not score.** Cosine similarity and BM25 are on unrelated scales, so adding them requires a weight that needs tuning. Reciprocal rank fusion only uses positions.

### Step 10. Decide on the vector index with numbers

Measured on 104 reworded questions, on a copy of the data:

| | Median query | Rows returned for `LIMIT 50` | Same top 10 as exact | Reworded top 10 |
|---|---:|---:|---:|---:|
| **Exact scan** | **18.8 ms** | **50** | **100%** | **85.6%** |
| HNSW, `ef_search` 40 (default) | 1.1 ms | 40 | 98.3% | 85.6% |
| HNSW, `ef_search` 100 | 1.4 ms | 50 | 99.6% | 85.6% |
| HNSW, `ef_search` 200 | 1.9 ms | 50 | 99.9% | 85.6% |

HNSW at `ef_search` 100 is about 13 times faster and lost nothing measurable in the top 10. It still is not used: it would save about 17 ms out of a search of about 130 ms, and the model call dominates. It also adds a 25 MB index and a setting to manage. Note the trap in the second row: with the default `ef_search`, asking for 50 rows quietly returns 40.

An exact scan of ten times the rows (63,700) took 232 ms, about as long as embedding a question. That is the point to add HNSW, with `ef_search` of at least 100, and the Store's `setup()` can create the index.

## 5. Tests

`php bin/phpunit`: unit tests for pure logic, feature tests against a real Postgres, and a fake model.

- **Chunker:** parity with Python across the full corpus, plus each parsing rule.
- **Retriever:** exact RRF order, rare terms beat common ones, identifiers stay one term, quotes in a query are data, a repeated paragraph moves behind different ones.
- **Measurement:** the file regex must match the whole path; a page hit counts the answer anywhere on the page; a rerank run whose reorder was busy or failed is refused instead of saved.
- **ChunkIndex:** shipped vectors cost no model calls, a second sync does nothing, a docs path with no chunks cannot wipe the index, short or wrong-sized model replies are refused.
- **Embedder:** model-card format, unit length, saved-vector fallback when the host is down.
- **Reranker:** only integers are read from the reply, so an injected instruction changes nothing but order.
- **Web:** 400 for empty, overlong or non-string input before any model call; 403 when another site makes the browser call `/search`; each hit links to its section on symfony.com.
- **Failure paths:** a model host that is off is given up on within 1 s, one that connects and then stalls after 15 s on an embedding (`app.ai_http_client` scopes the timeouts: the rerank keeps 300 s, because on CPU it is silent for about a minute); a visitor gets 10 searches a minute (`SEARCH_LIMIT`, `symfony/rate-limiter`, per IP), so a script cannot turn the search box into a model bill; a failed reorder keeps the original order and says so; only one reorder runs at a time (`symfony/lock`), a second one keeps the original order and says so instead of taking a web worker for a minute; an index built with another model refuses new vectors instead of mixing two vector spaces.

The LLM's quality is measured by `app:docs:eval`, not asserted in tests. The tests cover everything deterministic around it.

## 6. Limits

- **Reworded recall is not exact.** A wrong vocabulary guess still fails, as the "standard edition" example shows. Some misses are also strict labels: the answer is on another accepted-looking page.
- **The questions were written by the builder.** Real users will ask differently. The right next step is real query logs.
- **The set sizes are small,** so differences of a point or two mean nothing.
- **The chunker is specific to this RST.** The method carries over to other docs, the parser does not.
- **BM25 terms are simple:** lower-cased words with no stemming, and diacritics are kept (unlike FTS5). This did not change a measured number.
- **Retrieval only.** It finds passages and does not write answers. An LLM answer step would need its own evaluation.

## 7. For the interview

**The pitch.** "A 303 MB model on an ordinary CPU finds the right passage for reworded questions 83-89% of the time in the top 10, where keyword search manages about 55%. It runs in Postgres with Symfony AI, with no cloud API and no per-query cost. And I can show you how I know."

**The demo, in five minutes.**

1. Search "send people somewhere else when they type a wrong password". Keywords miss it, vectors put it first, and the phrase "redirect" never appears in the question.
2. Search "PhpArrayAdapter". The caption changes to vectors plus keywords: the router saw code.
3. Search "move an old standard edition project to the new recipe tool". Vectors miss, keywords find it at 2. Say so before they ask.
4. Scroll to the measured tables. Point out the set where pages were fixed before searching.
5. Open `Retriever.php`. The logic fits on one screen.

**The story that lands.** "My first router scored 92% on reworded questions. On ones it hadn't seen it scored 77%, worse than doing nothing. So I built held-out sets, and that discipline decided everything afterwards, including rejecting several fashionable techniques."

**Questions to expect.**

| Question | Answer |
|---|---|
| Why not just use Symfony AI's hybrid query? | I measured it: 5% and 0% on reworded questions. It filters on every word first. I use its vector query and write the keyword half myself. |
| Why no vector index? | Measured: 17 ms saved of about 130, no change in top 10. Exact scan until about 60,000 chunks, where it costs about as much as embedding the question. |
| Why BM25 by hand instead of Postgres full text? | `ts_rank` ignores word rarity. It would have made my keyword baseline artificially weak and my comparison unfair. |
| How do you know it isn't overfit? | Three sets, one drawn at random with accepted pages fixed before searching. And an earlier rule did overfit, which is how I learned to check. |
| What happens when the docs change? | Chunk ids hash their content, so one edit re-embeds one chunk. |
| What about prompt injection? | The docs sit in the reranker's prompt, so only integers are read from its reply. The worst outcome is a different order of passages already found. Output goes to the page as text, never as HTML. |
| What if the model server dies? | Keyword search keeps working, and questions asked before still have vectors. A server that is off is given up on within a second, one that stalls after connecting within 15 s. The page says which you are getting. |
| Would it scale? | The corpus size is the limit to test. 60,000 chunks is where I'd add HNSW. Past that, the embedding host's concurrency matters first. |
| What would you do next? | Real query logs, and an answer step with its own evaluation. |

**What not to claim.** Don't call it exact. Don't quote the tuning set. Don't say the questions are independent: you wrote them.
