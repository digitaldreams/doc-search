<?php

namespace App\Docs;

use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\Ollama\Ollama;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Talks to the embedding model. Passing a Model skips the catalog's POST /api/show on every call.
 */
final class Embedder
{
    /** The model the shipped data/seed_vectors.bin was built with. */
    public const DEFAULT_MODEL = 'embeddinggemma-2:270m-q8_0-text';
    /** Fixed by the vector(768) column: another model must return this many dimensions. */
    public const DIMENSIONS = 768;

    /** Long enough for a cold model load (2-14 s measured), short enough that a hung host does not hang the page. */
    public const QUERY_SECONDS = 15.0;

    private ?PlatformInterface $queryPlatform = null;

    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly Connection $db,
        private readonly string $host,
        private readonly ?HttpClientInterface $http = null,
        private readonly float $querySeconds = self::QUERY_SECONDS,
        #[Autowire('%env(EMBEDDING_MODEL)%')] private readonly string $model = self::DEFAULT_MODEL,
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
        $vectors = $this->embed(array_map(Chunker::docInput(...), $chunks));
        if (\count($vectors) !== \count($chunks)) {
            throw new \RuntimeException(\sprintf('The model returned %d vectors for %d chunks.', \count($vectors), \count($chunks)));
        }

        return $vectors;
    }

    /**
     * A question's vector, live when the host answers. Vectors are saved so a question asked before
     * still gets vector search with the host down, and the caller is told which one it got.
     *
     * @return array{vector: ?list<float>, source: 'live'|'saved'|'none'}
     */
    public function query(string $query): array
    {
        if ($this->reachable()) {
            try {
                $vector = $this->embed([Chunker::queryInput($query)], $this->queryPlatform())[0] ?? null;
                if (null !== $vector) {
                    $this->db->executeStatement(
                        'INSERT INTO query_vectors (query, embedding) VALUES (?, ?) ON CONFLICT (query) DO UPDATE SET embedding = EXCLUDED.embedding',
                        [$query, self::literal($vector)],
                    );

                    return ['vector' => $vector, 'source' => 'live'];
                }
            } catch (\Exception) {
                // any failure here falls through to the saved vector: a dead model must not take keyword search down
            }
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

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>>
     */
    private function embed(array $texts, ?PlatformInterface $platform = null): array
    {
        $model = new Ollama($this->model, [Capability::EMBEDDINGS]);
        $out = [];
        foreach (($platform ?? $this->platform)->invoke($model, $texts)->asVectors() as $vector) {
            $out[] = self::unit($vector->getData());
        }

        return $out;
    }

    /** Cosine needs length 1; Ollama returns unit vectors today, and this keeps that from being an assumption. */
    private static function unit(array $vector): array
    {
        $length = sqrt(array_sum(array_map(static fn (float $x) => $x * $x, $vector)));
        if (self::DIMENSIONS !== \count($vector) || 0.0 === $length) {
            throw new \RuntimeException(\sprintf('The model returned an unusable vector of %d dimensions.', \count($vector)));
        }

        return array_map(static fn (float $x) => $x / $length, $vector);
    }

    /**
     * Indexing may wait minutes for a batch; a question may not. The reachability probe below only proves the port
     * is open, so a host that accepts and then hangs is cut off here instead, and the saved vector or keywords answer.
     */
    private function queryPlatform(): PlatformInterface
    {
        if ('' === $this->host || null === $this->http) {
            return $this->platform;
        }

        return $this->queryPlatform ??= OllamaFactory::createPlatform($this->host, httpClient: $this->http->withOptions(['max_duration' => $this->querySeconds]));
    }

    /** One second to find out the host is off, instead of the 300s a slow embedding is allowed. */
    private function reachable(): bool
    {
        if ('' === $this->host) {
            return true;
        }
        $url = parse_url($this->host);
        $socket = @fsockopen($url['host'] ?? '', $url['port'] ?? 80, $code, $message, 1.0);
        if (false === $socket) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
