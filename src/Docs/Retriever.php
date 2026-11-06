<?php

namespace App\Docs;

use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;

/**
 * The search itself: vectors for meaning, BM25 for exact identifiers, routed by the shape of the question.
 *
 * @phpstan-type Hit array{id: string, file: string, path: list<string>, text: string, source: string}
 */
final class Retriever
{
    /** Each ranking is this deep before fusing, whatever the caller shows. */
    private const POOL = 50;
    private const RRF_K = 60;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly StoreInterface $store,
        private readonly Connection $db,
        private readonly ?Reranker $reranker = null,
    ) {
    }

    /**
     * @return array{hits: list<Hit>, mode: 'vector'|'hybrid'|'keyword'|'none', embedding: 'live'|'saved'|'none', rerank: 'off'|'done'|'failed', ms: array<string, float>}
     */
    public function search(string $query, int $k = 10, bool $rerank = false): array
    {
        $ms = [];
        if ('' === trim($query)) {
            return ['hits' => [], 'mode' => 'none', 'embedding' => 'none', 'rerank' => 'off', 'ms' => $ms];
        }

        $embedded = self::timed($ms, 'embed', fn () => $this->embedder->query($query));
        $useKeywords = Chunker::wantsKeywords($query) || null === $embedded['vector'];
        $vector = null === $embedded['vector'] ? [] : self::timed($ms, 'vector', fn () => $this->vector($embedded['vector'], self::POOL));
        $keyword = $useKeywords ? self::timed($ms, 'keyword', fn () => $this->keyword($query, self::POOL)) : [];

        $hits = match (true) {
            null === $embedded['vector'] => $keyword,
            $useKeywords => self::fuse($vector, $keyword),
            default => $vector,
        };
        $mode = null === $embedded['vector'] ? 'keyword' : ($useKeywords ? 'hybrid' : 'vector');

        $reordered = 'off';
        if ($rerank && $this->reranker) {
            try {
                $hits = self::timed($ms, 'rerank', fn () => $this->reranker->rerank($query, $hits));
                $reordered = 'done';
            } catch (\Exception) {
                $reordered = 'failed'; // e.g. the reorder model is not on this host: the search itself still stands
            }
        }

        return ['hits' => \array_slice($hits, 0, $k), 'mode' => $mode, 'embedding' => $embedded['source'], 'rerank' => $reordered, 'ms' => $ms];
    }

    /**
     * Exact cosine scan through Symfony AI's store.
     *
     * @param list<float> $vector
     *
     * @return list<Hit>
     */
    public function vector(array $vector, int $limit): array
    {
        $hits = [];
        foreach ($this->store->query(new VectorQuery(new Vector($vector)), ['limit' => $limit]) as $document) {
            $hits[] = self::hit($document);
        }

        return $hits;
    }

    /**
     * BM25 in SQL (k1 = 1.2, b = 0.75), same formula as SQLite FTS5. Postgres' own ts_rank has no inverse document
     * frequency, so a rare identifier would count no more than "the", and the keyword baseline
     * this demo compares against would be unfairly weak.
     *
     * @return list<Hit>
     */
    public function keyword(string $query, int $limit): array
    {
        preg_match_all('/\w+/u', mb_strtolower($query), $m);
        $terms = array_values(array_filter($m[0], static fn (string $t) => mb_strlen($t) > 1));
        if (!$terms) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(<<<'SQL'
            WITH stats AS (SELECT count(*)::float8 AS n, avg(tokens)::float8 AS avgdl FROM chunks),
            q AS (SELECT term, count(*) AS times FROM jsonb_array_elements_text(CAST(:terms AS jsonb)) AS term GROUP BY term),
            df AS (SELECT t.term, count(*)::float8 AS df FROM chunk_terms t JOIN q USING (term) GROUP BY t.term),
            ranked AS (
                SELECT t.chunk_id, sum(
                    q.times
                    * GREATEST(ln((s.n - df.df + 0.5) / (df.df + 0.5)), 1e-6)
                    * (t.tf * 2.2) / (t.tf + 1.2 * (0.25 + 0.75 * c.tokens / s.avgdl))
                ) AS score
                FROM chunk_terms t JOIN q USING (term) JOIN df USING (term) JOIN chunks c ON c.id = t.chunk_id CROSS JOIN stats s
                GROUP BY t.chunk_id
                ORDER BY score DESC, t.chunk_id
                LIMIT :limit
            )
            SELECT c.id, c.metadata FROM ranked r JOIN chunks c ON c.id = r.chunk_id ORDER BY r.score DESC, r.chunk_id
            SQL, [
            'terms' => json_encode($terms, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'limit' => $limit,
        ]);

        return array_map(static fn (array $r) => self::fromMetadata($r['id'], json_decode($r['metadata'], true, 512, \JSON_THROW_ON_ERROR)), $rows);
    }

    /**
     * Reciprocal rank fusion: ranks add up, raw scores from two different scales never meet.
     *
     * @param list<Hit> ...$rankings
     *
     * @return list<Hit>
     */
    private static function fuse(array ...$rankings): array
    {
        $scores = $hits = [];
        foreach ($rankings as $ranking) {
            foreach ($ranking as $position => $hit) {
                $scores[$hit['id']] = ($scores[$hit['id']] ?? 0) + 1 / (self::RRF_K + $position + 1);
                $hits[$hit['id']] = $hit;
            }
        }
        arsort($scores); // stable: on a tie, the vector ranking's order wins

        return array_map(static fn ($id) => $hits[$id], array_keys($scores));
    }

    private static function hit(VectorDocumentInterface $document): array
    {
        return self::fromMetadata((string) $document->getId(), $document->getMetadata()->getArrayCopy());
    }

    private static function fromMetadata(string $id, array $m): array
    {
        return ['id' => $id, 'file' => $m['file'], 'path' => $m['path'], 'text' => $m['text'], 'source' => $m['source']];
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
