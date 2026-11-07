<?php

namespace App\Docs;

use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Vector\NullVector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Exception\UnsupportedQueryTypeException;
use Symfony\AI\Store\Query\QueryInterface;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\StoreInterface;

/**
 * Keyword search as a Symfony AI store: BM25 over the chunk_terms inverted index, answering TextQuery.
 *
 * It indexes the rows of the vector store's `chunks` table (their terms and token counts) rather than holding
 * documents of its own, so it sits behind CombinedStore, after the Postgres store that writes each row first.
 *
 * BM25 by hand (k1 = 1.2, b = 0.75), the same formula as SQLite FTS5: Postgres' own ts_rank has no inverse document
 * frequency, so a rare identifier would count no more than "the", and the keyword baseline this demo compares
 * against would be unfairly weak.
 */
final class Bm25Store implements StoreInterface
{
    /** Longer than any real word, and short enough for a btree entry. */
    private const MAX_TERM = 200;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /** @return list<string> lower-case words and numbers; `rotating_file` stays one term */
    public static function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($text), $m);

        return array_map(static fn (string $t) => mb_substr($t, 0, self::MAX_TERM), $m[0]);
    }

    /** Terms of each document's searchable text (Metadata::KEY_TEXT); its `chunks` row must already exist. */
    public function add(VectorDocumentInterface|array $documents): void
    {
        $rows = $lengths = [];
        foreach (\is_array($documents) ? $documents : [$documents] as $document) {
            $id = (string) $document->getId();
            $terms = self::terms($document->getMetadata()->getText() ?? '');
            $lengths[] = ['id' => $id, 'tokens' => \count($terms)];
            foreach (array_count_values($terms) as $term => $tf) {
                $rows[] = ['term' => (string) $term, 'chunk_id' => $id, 'tf' => $tf];
            }
        }
        $this->db->executeStatement(
            'UPDATE chunks c SET tokens = x.tokens FROM jsonb_to_recordset(CAST(? AS jsonb)) AS x(id text, tokens int) WHERE c.id = x.id',
            [json_encode($lengths, \JSON_THROW_ON_ERROR)],
        );
        $this->db->executeStatement(
            'INSERT INTO chunk_terms (term, chunk_id, tf) SELECT term, chunk_id, tf FROM jsonb_to_recordset(CAST(? AS jsonb)) AS x(term text, chunk_id text, tf int) ON CONFLICT DO NOTHING',
            [json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)],
        );
    }

    public function remove(string|array $ids, array $options = []): void
    {
        $this->db->executeStatement('DELETE FROM chunk_terms WHERE chunk_id = ANY(CAST(? AS text[]))', ['{'.implode(',', (array) $ids).'}']);
    }

    public function clear(array $options = []): void
    {
        $this->db->executeStatement('TRUNCATE chunk_terms');
    }

    /** Chunks with terms. Note: CombinedStore::count() adds this to the vector store's count; nothing here calls it. */
    public function count(): int
    {
        return (int) $this->db->fetchOne('SELECT count(DISTINCT chunk_id) FROM chunk_terms');
    }

    public function supports(string $queryClass): bool
    {
        return TextQuery::class === $queryClass;
    }

    /**
     * Rarity and average length are counted over the rows `where` selects (the caller's repo): counted over every
     * repo, one repo's common words would score as rare in another. As for the Postgres store, `where` is SQL on
     * `chunks` written by the caller, with its values bound through `params`.
     *
     * @param array{limit?: positive-int, where?: string, params?: array<string, mixed>} $options
     *
     * @return iterable<VectorDocumentInterface> no vectors (NullVector), scored by BM25
     */
    public function query(QueryInterface $query, array $options = []): iterable
    {
        if (!$query instanceof TextQuery) {
            throw new UnsupportedQueryTypeException($query::class, $this);
        }
        preg_match_all('/\w+/u', mb_strtolower($query->getText()), $m);
        $terms = array_values(array_filter($m[0], static fn (string $t) => mb_strlen($t) > 1));
        if (!$terms) {
            return [];
        }

        $where = isset($options['where']) ? "WHERE {$options['where']}" : '';
        $rows = $this->db->fetchAllAssociative(<<<SQL
            WITH candidates AS (SELECT id, tokens FROM chunks $where),
            stats AS (SELECT count(*)::float8 AS n, avg(tokens)::float8 AS avgdl FROM candidates),
            q AS (SELECT term, count(*) AS times FROM jsonb_array_elements_text(CAST(:terms AS jsonb)) AS term GROUP BY term),
            df AS (SELECT t.term, count(*)::float8 AS df FROM chunk_terms t JOIN q USING (term) JOIN candidates c ON c.id = t.chunk_id GROUP BY t.term),
            ranked AS (
                SELECT t.chunk_id, sum(
                    q.times
                    * GREATEST(ln((s.n - df.df + 0.5) / (df.df + 0.5)), 1e-6)
                    * (t.tf * 2.2) / (t.tf + 1.2 * (0.25 + 0.75 * c.tokens / s.avgdl))
                ) AS score
                FROM chunk_terms t JOIN q USING (term) JOIN df USING (term) JOIN candidates c ON c.id = t.chunk_id CROSS JOIN stats s
                GROUP BY t.chunk_id
                ORDER BY score DESC, t.chunk_id
                LIMIT :limit
            )
            SELECT c.id, c.metadata, r.score FROM ranked r JOIN chunks c ON c.id = r.chunk_id ORDER BY r.score DESC, r.chunk_id
            SQL, [
            'terms' => json_encode($terms, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'limit' => $options['limit'] ?? 5,
        ] + ($options['params'] ?? []));

        return array_map(static fn (array $r) => new VectorDocument(
            $r['id'],
            new NullVector(),
            new Metadata(json_decode($r['metadata'], true, 512, \JSON_THROW_ON_ERROR)),
            (float) $r['score'],
        ), $rows);
    }
}
