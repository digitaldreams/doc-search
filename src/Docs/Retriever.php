<?php

namespace App\Docs;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The search itself: vectors for meaning, BM25 for exact identifiers, routed by the shape of the question.
 * Every search runs inside one docs repo, and every query goes to one Symfony AI store (app.docs_store: the
 * Postgres vector store and Bm25Store behind CombinedStore, which fuses a HybridQuery by reciprocal rank).
 *
 * @phpstan-type Hit array{id: string, file: string, path: list<string>, text: string, source: string, anchor: ?string}
 */
final class Retriever
{
    /** Each ranking is this deep before fusing, whatever the caller shows. */
    private const POOL = 50;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly StoreInterface $store,
        private readonly ?Reranker $reranker = null,
        private readonly ?LockFactory $locks = null,
    ) {
    }

    /**
     * @return array{hits: list<Hit>, mode: 'vector'|'hybrid'|'keyword'|'none', embedding: 'live'|'saved'|'none', rerank: 'off'|'done'|'failed'|'busy', ms: array<string, float>}
     */
    public function search(string $query, string $repo, int $k = 10, bool $rerank = false): array
    {
        $ms = [];
        if ('' === trim($query)) {
            return ['hits' => [], 'mode' => 'none', 'embedding' => 'none', 'rerank' => 'off', 'ms' => $ms];
        }

        $embedded = self::timed($ms, 'embed', fn () => $this->embedder->query($query));
        [$mode, $wanted] = match (true) {
            null === $embedded['vector'] => ['keyword', new TextQuery($query)], // the model host is down and the question is new
            Chunker::wantsKeywords($query) => ['hybrid', new HybridQuery(new Vector($embedded['vector']), $query)],
            default => ['vector', new VectorQuery(new Vector($embedded['vector']))],
        };
        $hits = self::demoteRepeats(self::timed($ms, $mode, fn () => $this->find($wanted, self::POOL, $repo)));

        $reordered = 'off';
        if ($rerank && $this->reranker) {
            // One at a time: a reorder holds the CPU for about a minute, and queued ones would take every web worker.
            $lock = $this->locks?->createLock('rerank', 300);
            if ($lock && !$lock->acquire()) {
                $reordered = 'busy';
            } else {
                try {
                    $hits = self::timed($ms, 'rerank', fn () => $this->reranker->rerank($query, $hits));
                    $reordered = 'done';
                } catch (\Exception) {
                    $reordered = 'failed'; // e.g. the reorder model is not on this host: the search itself still stands
                } finally {
                    $lock?->release();
                }
            }
        }

        return ['hits' => \array_slice($hits, 0, $k), 'mode' => $mode, 'embedding' => $embedded['source'], 'rerank' => $reordered, 'ms' => $ms];
    }

    /**
     * Exact cosine scan of the repo's chunks.
     *
     * @param list<float> $vector
     *
     * @return list<Hit>
     */
    public function vector(array $vector, int $limit, string $repo): array
    {
        return $this->find(new VectorQuery(new Vector($vector)), $limit, $repo);
    }

    /**
     * BM25 over the repo's chunks (Bm25Store).
     *
     * @return list<Hit>
     */
    public function keyword(string $query, int $limit, string $repo): array
    {
        return $this->find(new TextQuery($query), $limit, $repo);
    }

    /** @return list<Hit> */
    private function find(QueryInterface $query, int $limit, string $repo): array
    {
        // the repo is bound, never inlined; both stores apply this `where` to the chunks table
        $options = ['limit' => $limit, 'where' => "metadata->>'repo' = :repo", 'params' => ['repo' => $repo]];

        return array_map(self::hit(...), [...$this->store->query($query, $options)]);
    }

    /**
     * Inlined includes repeat option text word for word on many pages (`payload` 76 times), so one question could
     * fill the top 10 with the same paragraph. Later copies move behind everything else; none is dropped.
     * Measured: no loss in the top 10 on any set. Capping hits per page was also tried and rejected: it won on
     * tuning and lost 3 held-out questions in the top 10.
     *
     * @param list<Hit> $hits
     *
     * @return list<Hit>
     */
    private static function demoteRepeats(array $hits): array
    {
        $first = $repeats = $seen = [];
        foreach ($hits as $hit) {
            isset($seen[$hit['text']]) ? $repeats[] = $hit : $first[] = $hit;
            $seen[$hit['text']] = true;
        }

        return [...$first, ...$repeats];
    }

    /** @return Hit */
    private static function hit(VectorDocumentInterface $document): array
    {
        $m = $document->getMetadata();

        return ['id' => (string) $document->getId(), 'file' => $m['file'], 'path' => $m['path'], 'text' => $m['text'], 'source' => $m['source'], 'anchor' => $m['anchor'] ?? null];
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private static function timed(array &$ms, string $label, callable $work): mixed
    {
        $start = hrtime(true);
        $result = $work();
        $ms[$label] = round((hrtime(true) - $start) / 1e6, 1);

        return $result;
    }
}
