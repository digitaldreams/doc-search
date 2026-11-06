<?php

namespace App\Tests\Docs;

use App\Docs\ChunkIndex;
use App\Docs\Embedder;
use App\Docs\Retriever;
use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
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

    protected function embedder(?array $queryVector = null, string $host = ''): Embedder
    {
        $platform = null === $queryVector ? FakeEmbeddings::platform() : MockPlatformFactory::createPlatform(static fn () => new VectorResult([new Vector($queryVector)]));

        return new Embedder($platform, $this->db, $host);
    }

    protected function retriever(array $queryVector): Retriever
    {
        return new Retriever($this->embedder($queryVector), $this->store, $this->db);
    }
}
