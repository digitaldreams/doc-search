<?php

namespace App\Docs;

use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;

/**
 * Keeps a repo's chunks in the docs store (app.docs_store: vectors and BM25 terms, written together).
 *
 * Re-indexing is incremental because a chunk's id hashes its content: unchanged chunks are skipped,
 * so a one-line docs edit re-embeds one chunk rather than 6,370.
 */
final class ChunkIndex
{
    private const BATCH = 16;
    private const SEED_RECORD = 8 + Embedder::DIMENSIONS * 4; // 8-byte id, then float32 little-endian

    public function __construct(
        private readonly Embedder $embedder,
        private readonly StoreInterface $store,
        private readonly Connection $db,
    ) {
    }

    /**
     * Make the index hold exactly these chunks for this repo; other repos' chunks are never touched.
     *
     * ponytail: ids hash file + breadcrumb + text, not the repo, so the shipped vectors keep matching. Two repos with
     * the same file, breadcrumb and text would share one row; hash the repo into the id if that ever happens.
     *
     * @param list<TextDocument> $documents from the repo's loader, one per chunk
     *
     * @return array{seeded: int, embedded: int, removed: int, kept: int}
     */
    public function sync(string $repo, array $documents, string $seedFile, ?callable $progress = null): array
    {
        if (!$documents) { // sync removes whatever is gone from the docs: a wrong path would remove everything
            throw new \RuntimeException('No chunks found, index left unchanged.');
        }
        // Chunk ids hash the text, not the model: without this, a new model would keep every old vector and
        // embed only new chunks, mixing two vector spaces of the same size with no error.
        $model = $this->embedder->model();
        $built = $this->db->fetchOne('SELECT model FROM index_meta');
        if (false === $built) {
            $this->db->executeStatement('INSERT INTO index_meta (model) VALUES (?)', [$model]);
        } elseif ($model !== $built) {
            throw new \RuntimeException(\sprintf('The index was built with %s, not %s. Empty the chunks, chunk_terms, query_vectors and index_meta tables to rebuild it, index left unchanged.', $built, $model));
        }
        $have = array_flip($this->db->fetchFirstColumn("SELECT id FROM chunks WHERE metadata->>'repo' = ?", [$repo]));
        $wanted = [];
        foreach ($documents as $document) {
            $wanted[$document->getId()] = $document;
        }
        $gone = array_keys(array_diff_key($have, $wanted));
        $todo = array_values(array_diff_key($wanted, $have));
        // shipped vectors live in the default model's space; another model must embed everything itself
        $seeds = Embedder::DEFAULT_MODEL === $model ? $this->readSeeds($seedFile, array_map(static fn ($d) => (string) $d->getId(), $todo)) : [];

        if ($gone) {
            $this->store->remove(array_map(strval(...), $gone)); // array keys: an all-digit id came back as an int
        }

        $seeded = array_values(array_filter($todo, static fn ($d) => isset($seeds[$d->getId()])));
        foreach (array_chunk($seeded, 256) as $part) {
            $this->store($repo, $part, array_map(static fn ($d) => $seeds[$d->getId()], $part));
            $progress && $progress(\count($part));
        }

        $missing = array_values(array_filter($todo, static fn ($d) => !isset($seeds[$d->getId()])));
        usort($missing, static fn ($a, $b) => mb_strlen($a->getMetadata()['text']) <=> mb_strlen($b->getMetadata()['text'])); // similar lengths: less padding per batch
        foreach (array_chunk($missing, self::BATCH) as $part) {
            $this->store($repo, $part, $this->embedder->documents($part));
            $progress && $progress(\count($part));
        }

        return ['seeded' => \count($seeded), 'embedded' => \count($missing), 'removed' => \count($gone), 'kept' => \count($documents) - \count($todo)];
    }

    /**
     * One transaction per batch, so a chunk is never stored without its terms: a later sync would skip it as done.
     * The Postgres store runs on DBAL's own PDO (ai.yaml `dbal_connection`), so its writes join this transaction.
     *
     * @param list<TextDocument> $documents
     * @param list<list<float>>  $vectors   one per document, in order
     */
    public function store(string $repo, array $documents, array $vectors): void
    {
        $stored = [];
        foreach ($documents as $i => $document) {
            $m = $document->getMetadata()->getArrayCopy();
            $stored[] = new VectorDocument((string) $document->getId(), new Vector($vectors[$i]), new Metadata(
                ['repo' => $repo] + $m + [Metadata::KEY_TEXT => implode(' > ', $m['path'])."\n".$m['text']], // what keyword search reads
            ));
        }
        $this->db->transactional(fn () => $this->store->add($stored));
    }

    /**
     * Write this repo's vectors as its shipped vectors (readSeeds' format, ordered by id), so a new install
     * seeds them instead of embedding the repo again. Only vectors of the default model: those are the only
     * ones sync reads.
     *
     * @return int vectors written
     */
    public function exportSeeds(string $repo, string $file): int
    {
        $built = $this->db->fetchOne('SELECT model FROM index_meta');
        if (Embedder::DEFAULT_MODEL !== $built) {
            throw new \RuntimeException(\sprintf('The index was built with %s; shipped vectors must come from %s. Nothing exported.', $built ?: 'no model', Embedder::DEFAULT_MODEL));
        }
        $bytes = '';
        foreach ($this->db->fetchAllKeyValue("SELECT id, embedding FROM chunks WHERE metadata->>'repo' = ? ORDER BY id", [$repo]) as $id => $vector) {
            $bytes .= hex2bin($id).pack('g*', ...json_decode($vector, true, 2, \JSON_THROW_ON_ERROR));
        }
        if ('' === $bytes) {
            throw new \RuntimeException("No chunks of $repo are indexed. Nothing exported.");
        }
        is_dir(\dirname($file)) || mkdir(\dirname($file), 0o755, true);
        file_put_contents("$file.tmp", $bytes);
        rename("$file.tmp", $file); // a failed export never leaves a truncated seed file behind

        return \strlen($bytes) / self::SEED_RECORD;
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
