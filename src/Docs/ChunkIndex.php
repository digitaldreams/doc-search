<?php

namespace App\Docs;

use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;

/**
 * Writes chunks: vectors through Symfony AI's store, terms into the BM25 table.
 *
 * Re-indexing is incremental because a chunk's id hashes its content: unchanged chunks are skipped,
 * so a one-line docs edit re-embeds one chunk rather than 6,370.
 */
final class ChunkIndex
{
    private const BATCH = 16;
    private const SEED_RECORD = 8 + Embedder::DIMENSIONS * 4; // 8-byte id, then float32 little-endian
    /** Longer than any real word, and short enough for a btree entry. */
    private const MAX_TERM = 200;

    public function __construct(
        private readonly Embedder $embedder,
        private readonly StoreInterface $store,
        private readonly Connection $db,
    ) {
    }

    /** @return list<string> lower-case words and numbers; `rotating_file` stays one term */
    public static function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($text), $m);

        return array_map(static fn (string $t) => mb_substr($t, 0, self::MAX_TERM), $m[0]);
    }

    /**
     * @param list<array{id: string, file: string, path: list<string>, text: string, source: string}> $chunks
     *
     * @return array{seeded: int, embedded: int, removed: int, kept: int}
     */
    public function sync(array $chunks, string $seedFile, ?callable $progress = null): array
    {
        if (!$chunks) { // sync removes whatever is gone from the docs: a wrong path would remove everything
            throw new \RuntimeException('No chunks found, index left unchanged.');
        }
        // Chunk ids hash the text, not the model: without this, a new model would keep every old vector and
        // embed only new chunks, mixing two vector spaces of the same size with no error.
        $model = $this->db->fetchOne('SELECT model FROM index_meta');
        if (false === $model) {
            $this->db->executeStatement('INSERT INTO index_meta (model) VALUES (?)', [Embedder::MODEL]);
        } elseif (Embedder::MODEL !== $model) {
            throw new \RuntimeException(\sprintf('The index was built with %s, not %s. Empty the chunks, chunk_terms, query_vectors and index_meta tables to rebuild it, index left unchanged.', $model, Embedder::MODEL));
        }
        $have = array_flip($this->db->fetchFirstColumn('SELECT id FROM chunks'));
        $wanted = array_column($chunks, null, 'id');
        $gone = array_keys(array_diff_key($have, $wanted));
        $todo = array_values(array_diff_key($wanted, $have));
        $seeds = $this->readSeeds($seedFile, array_column($todo, 'id'));

        if ($gone) {
            $this->db->executeStatement('DELETE FROM chunks WHERE id = ANY(CAST(? AS text[]))', ['{'.implode(',', $gone).'}']);
        }

        $seeded = array_values(array_filter($todo, static fn ($c) => isset($seeds[$c['id']])));
        foreach (array_chunk($seeded, 256) as $part) {
            $this->store($part, array_map(static fn ($c) => $seeds[$c['id']], $part));
            $progress && $progress(\count($part));
        }

        $missing = array_values(array_filter($todo, static fn ($c) => !isset($seeds[$c['id']])));
        usort($missing, static fn ($a, $b) => mb_strlen($a['text']) <=> mb_strlen($b['text'])); // similar lengths: less padding per batch
        foreach (array_chunk($missing, self::BATCH) as $part) {
            $this->store($part, $this->embedder->documents($part));
            $progress && $progress(\count($part));
        }

        return ['seeded' => \count($seeded), 'embedded' => \count($missing), 'removed' => \count($gone), 'kept' => \count($chunks) - \count($todo)];
    }

    /**
     * @param list<array{id: string, file: string, path: list<string>, text: string, source: string}> $chunks
     * @param list<list<float>>                                                                       $vectors
     */
    public function store(array $chunks, array $vectors): void
    {
        $this->db->transactional(function () use ($chunks, $vectors): void {
            $documents = $rows = $lengths = [];
            foreach ($chunks as $i => $c) {
                $searchable = implode(' > ', $c['path'])."\n".$c['text'];
                $documents[] = new VectorDocument($c['id'], new Vector($vectors[$i]), new Metadata([
                    'file' => $c['file'], 'path' => $c['path'], 'text' => $c['text'], 'source' => $c['source'], Metadata::KEY_TEXT => $searchable,
                ]));
                $terms = self::terms($searchable);
                $lengths[] = ['id' => $c['id'], 'tokens' => \count($terms)];
                foreach (array_count_values($terms) as $term => $tf) {
                    $rows[] = ['term' => (string) $term, 'chunk_id' => $c['id'], 'tf' => $tf];
                }
            }
            $this->store->add($documents);
            $this->db->executeStatement(
                'UPDATE chunks c SET tokens = x.tokens FROM jsonb_to_recordset(CAST(? AS jsonb)) AS x(id text, tokens int) WHERE c.id = x.id',
                [json_encode($lengths, \JSON_THROW_ON_ERROR)],
            );
            $this->db->executeStatement(
                'INSERT INTO chunk_terms (term, chunk_id, tf) SELECT term, chunk_id, tf FROM jsonb_to_recordset(CAST(? AS jsonb)) AS x(term text, chunk_id text, tf int) ON CONFLICT DO NOTHING',
                [json_encode($rows, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)],
            );
        });
    }

    /**
     * @param list<string> $ids only these are decoded; the file holds every chunk
     *
     * @return array<string, list<float>>
     */
    private function readSeeds(string $file, array $ids): array
    {
        $bytes = is_file($file) ? file_get_contents($file) : '';
        if (0 !== \strlen($bytes) % self::SEED_RECORD) {
            throw new \RuntimeException("Seed file $file is truncated.");
        }
        $wanted = array_flip($ids);
        $out = [];
        for ($at = 0; $at < \strlen($bytes); $at += self::SEED_RECORD) {
            $id = bin2hex(substr($bytes, $at, 8));
            if (isset($wanted[$id])) {
                $out[$id] = array_values(unpack('g*', substr($bytes, $at + 8, self::SEED_RECORD - 8)));
            }
        }

        return $out;
    }
}
