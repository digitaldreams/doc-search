<?php

namespace App\Docs;

use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * Turns docs and questions into unit vectors through whichever vectorizer ai.yaml configures.
 */
final class Embedder
{
    /** The model the shipped data/seed_vectors.bin was built with. */
    public const DEFAULT_MODEL = 'embeddinggemma-2:270m-q8_0-text';
    /** Fixed by the vector(768) column: another model must return this many dimensions. */
    public const DIMENSIONS = 768;

    /**
     * @param string $model the vectorizer's model, recorded in index_meta so two models' vectors never mix
     */
    public function __construct(
        private readonly VectorizerInterface $vectorizer,
        private readonly Connection $db,
        private readonly string $model = self::DEFAULT_MODEL,
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param list<array{path: list<string>, text: string}> $chunks
     *
     * @return list<list<float>> one unit vector per chunk, in order
     */
    public function documents(array $chunks): array
    {
        $vectors = $this->vectorizer->vectorize(array_map(Chunker::docInput(...), $chunks));
        if (\count($vectors) !== \count($chunks)) {
            throw new \RuntimeException(\sprintf('The model returned %d vectors for %d chunks.', \count($vectors), \count($chunks)));
        }

        return array_map(self::unit(...), $vectors);
    }

    /**
     * A question's vector, live when the host answers. Vectors are saved so a question asked before
     * still gets vector search with the host down, and the caller is told which one it got.
     *
     * @return array{vector: ?list<float>, source: 'live'|'saved'|'none'}
     */
    public function query(string $query): array
    {
        try {
            $vector = self::unit($this->vectorizer->vectorize(Chunker::queryInput($query)));
            $this->db->executeStatement(
                'INSERT INTO query_vectors (query, embedding) VALUES (?, ?) ON CONFLICT (query) DO UPDATE SET embedding = EXCLUDED.embedding',
                [$query, self::literal($vector)],
            );

            return ['vector' => $vector, 'source' => 'live'];
        } catch (\Exception) {
            // any failure here falls through to the saved vector: a dead model must not take keyword search down
        }

        $saved = $this->db->fetchOne('SELECT embedding FROM query_vectors WHERE query = ?', [$query]);

        return false !== $saved
            ? ['vector' => json_decode($saved, true, 2, \JSON_THROW_ON_ERROR), 'source' => 'saved']
            : ['vector' => null, 'source' => 'none'];
    }

    public static function literal(array $vector): string
    {
        return '['.implode(',', $vector).']';
    }

    /** Cosine needs length 1: Ollama returns unit vectors, Gemini's shortened 768-dimension ones are not. */
    private static function unit(Vector $vector): array
    {
        $data = $vector->getData();
        $length = sqrt(array_sum(array_map(static fn (float $x) => $x * $x, $data)));
        if (self::DIMENSIONS !== \count($data) || 0.0 === $length) {
            throw new \RuntimeException(\sprintf('The model returned an unusable vector of %d dimensions.', \count($data)));
        }

        return array_map(static fn (float $x) => $x / $length, $data);
    }
}
