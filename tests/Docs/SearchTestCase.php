<?php

namespace App\Tests\Docs;

use App\Docs\ChunkIndex;
use App\Docs\Embedder;
use App\Docs\Retriever;
use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\StoreInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class SearchTestCase extends KernelTestCase
{
    protected Connection $db;
    protected StoreInterface $store;
    protected ChunkIndex $index;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = self::getContainer()->get(Connection::class);
        $this->store = self::getContainer()->get(StoreInterface::class);
        $this->db->executeStatement('TRUNCATE chunks, chunk_terms, query_vectors, index_meta');
        FakeEmbeddings::$inputs = [];
    }

    /** A unit vector along one axis: chunks on nearby axes can be blended to set exact similarities. */
    protected static function axis(int ...$axes): array
    {
        $v = array_fill(0, 768, 0.0);
        foreach ($axes as $i => $a) {
            $v[$a] = 1.0 / ($i + 1);
        }

        return $v;
    }

    protected static function chunk(string $id, string $text, array $path = ['Page'], string $file = 'page.rst'): array
    {
        return ['id' => $id, 'file' => $file, 'path' => $path, 'text' => $text, 'source' => $text];
    }

    protected function index(?array $queryVector = null): ChunkIndex
    {
        return new ChunkIndex($this->embedder($queryVector), $this->store, $this->db);
    }

    /** @param bool $down the model host fails every call, as when it is switched off */
    protected function embedder(?array $queryVector = null, bool $down = false): Embedder
    {
        $platform = match (true) {
            $down => MockPlatformFactory::createPlatform(static fn () => throw new \RuntimeException('Couldn\'t connect to server')),
            null === $queryVector => FakeEmbeddings::platform(),
            default => MockPlatformFactory::createPlatform(static fn () => new VectorResult([new Vector($queryVector)])),
        };

        return new Embedder(new Vectorizer($platform, 'test-model'), $this->db);
    }

    protected function retriever(array $queryVector): Retriever
    {
        return new Retriever($this->embedder($queryVector), $this->store, $this->db);
    }
}
