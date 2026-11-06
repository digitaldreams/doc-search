<?php

namespace App\Tests\Docs;

use App\Docs\ChunkIndex;
use App\Docs\Embedder;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Vectorizer;

final class ChunkIndexTest extends SearchTestCase
{
    private function seedFile(array $vectors): string
    {
        $path = tempnam(sys_get_temp_dir(), 'seed');
        $bytes = '';
        foreach ($vectors as $id => $v) {
            $bytes .= hex2bin($id).pack('g*', ...$v);
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    private function ids(): array
    {
        return $this->db->fetchFirstColumn('SELECT id FROM chunks ORDER BY id');
    }

    public function testShippedVectorsAreUsedWithoutCallingTheModel(): void
    {
        $seed = $this->seedFile(['aaaaaaaaaaaaaaaa' => self::axis(7)]);

        $stats = $this->index()->sync(self::REPO, [self::chunk('aaaaaaaaaaaaaaaa', 'seeded')], $seed);

        self::assertSame(['seeded' => 1, 'embedded' => 0, 'removed' => 0, 'kept' => 0], $stats);
        self::assertSame([], FakeEmbeddings::$inputs);
        self::assertSame(self::axis(7)[7], (float) json_decode($this->db->fetchOne('SELECT embedding FROM chunks'), true)[7]);
    }

    public function testShippedVectorsAreIgnoredWhenAnotherEmbeddingModelIsConfigured(): void
    {
        $seed = $this->seedFile(['aaaaaaaaaaaaaaaa' => self::axis(7)]);
        $index = new ChunkIndex(new Embedder(new Vectorizer(FakeEmbeddings::platform(), 'other-embedder'), $this->db, 'other-embedder'), $this->store, $this->db);

        $stats = $index->sync(self::REPO, [self::chunk('aaaaaaaaaaaaaaaa', 'seeded')], $seed);

        self::assertSame(['seeded' => 0, 'embedded' => 1, 'removed' => 0, 'kept' => 0], $stats);
        self::assertSame('other-embedder', $this->db->fetchOne('SELECT model FROM index_meta'));
    }

    public function testAChunkWithNoShippedVectorIsEmbeddedInTheDocumentFormat(): void
    {
        $stats = $this->index()->sync(self::REPO, [self::chunk('bbbbbbbbbbbbbbbb', 'new words', ['Top', 'Sub'])], $this->seedFile([]));

        self::assertSame(1, $stats['embedded']);
        self::assertSame([['title: Top > Sub | text: new words']], FakeEmbeddings::$inputs);
    }

    public function testASecondSyncDoesNoWork(): void
    {
        $seed = $this->seedFile([]);
        $this->index()->sync(self::REPO, [self::chunk('cccccccccccccccc', 'x y')], $seed);
        FakeEmbeddings::$inputs = [];

        $stats = $this->index()->sync(self::REPO, [self::chunk('cccccccccccccccc', 'x y')], $seed);

        self::assertSame(['seeded' => 0, 'embedded' => 0, 'removed' => 0, 'kept' => 1], $stats);
        self::assertSame([], FakeEmbeddings::$inputs);
    }

    public function testChunksGoneFromTheDocsAreRemovedWithTheirTerms(): void
    {
        $seed = $this->seedFile([]);
        $this->index()->sync(self::REPO, [self::chunk('dddddddddddddddd', 'old words'), self::chunk('eeeeeeeeeeeeeeee', 'kept words')], $seed);

        $stats = $this->index()->sync(self::REPO, [self::chunk('eeeeeeeeeeeeeeee', 'kept words')], $seed);

        self::assertSame(1, $stats['removed']);
        self::assertSame(['eeeeeeeeeeeeeeee'], $this->ids());
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM chunk_terms WHERE chunk_id = 'dddddddddddddddd'"));
    }

    public function testSyncingOneRepoNeverRemovesAnothersChunks(): void
    {
        // ingest runs one repo at a time: what it finds missing is missing from that repo only
        $seed = $this->seedFile([]);
        $this->index()->sync(self::REPO, [self::chunk('aaaaaaaaaaaaaaaa', 'symfony words')], $seed);

        $stats = $this->index()->sync('laravel/docs', [self::chunk('bbbbbbbbbbbbbbbb', 'laravel words')], $seed);
        self::assertSame(['seeded' => 0, 'embedded' => 1, 'removed' => 0, 'kept' => 0], $stats);

        $stats = $this->index()->sync('laravel/docs', [self::chunk('cccccccccccccccc', 'newer laravel words')], $seed);
        self::assertSame(1, $stats['removed']);
        self::assertSame(['aaaaaaaaaaaaaaaa', 'cccccccccccccccc'], $this->ids());
    }

    public function testEachChunkRemembersItsRepoAndAnchor(): void
    {
        $this->index()->store('laravel/docs', [self::chunk('a', 'routes') + ['anchor' => 'basic-routing'], self::chunk('b', 'no anchor')], [self::axis(1), self::axis(2)]);

        $rows = $this->db->fetchAllKeyValue("SELECT id, metadata->>'repo' || ' #' || coalesce(metadata->>'anchor', '-') FROM chunks ORDER BY id");
        self::assertSame(['a' => 'laravel/docs #basic-routing', 'b' => 'laravel/docs #-'], $rows);
    }

    public function testAWrongDocsPathCannotWipeTheIndex(): void
    {
        $seed = $this->seedFile([]);
        $this->index()->sync(self::REPO, [self::chunk('ffffffffffffffff', 'precious')], $seed);

        try {
            $this->index()->sync(self::REPO, [], $seed);
            self::fail('an empty chunk list must be refused');
        } catch (\RuntimeException) {
        }

        self::assertSame(['ffffffffffffffff'], $this->ids());
    }

    public function testAnIndexBuiltWithAnotherModelIsNeverMixedWithThisOne(): void
    {
        $seed = $this->seedFile([]);
        $this->index()->sync(self::REPO, [self::chunk('1111111111111111', 'old')], $seed);
        self::assertSame(Embedder::DEFAULT_MODEL, $this->db->fetchOne('SELECT model FROM index_meta'));
        $this->db->executeStatement('UPDATE index_meta SET model = ?', ['some-other-model']);
        FakeEmbeddings::$inputs = [];

        try {
            $this->index()->sync(self::REPO, [self::chunk('2222222222222222', 'new')], $seed);
            self::fail('vectors from two models must not share one index');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('some-other-model', $e->getMessage());
        }

        self::assertSame(['1111111111111111'], $this->ids());
        self::assertSame([], FakeEmbeddings::$inputs);
    }

    public function testAHostReturningTooFewVectorsStoresNothing(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn () => new VectorResult([new Vector(self::axis(1))]));
        $index = new ChunkIndex(new Embedder(new Vectorizer($platform, 'test-model'), $this->db), $this->store, $this->db);

        try {
            $index->sync(self::REPO, [self::chunk('1111111111111111', 'a'), self::chunk('2222222222222222', 'b')], $this->seedFile([]));
            self::fail('a short reply must be refused');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->ids());
    }

    public function testAVectorOfTheWrongSizeIsRefused(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn () => new VectorResult([new Vector([1.0, 0.0])]));
        $index = new ChunkIndex(new Embedder(new Vectorizer($platform, 'test-model'), $this->db), $this->store, $this->db);

        $this->expectException(\RuntimeException::class);
        $index->sync(self::REPO, [self::chunk('3333333333333333', 'a')], $this->seedFile([]));
    }

    public function testATruncatedSeedFileIsRefused(): void
    {
        $seed = tempnam(sys_get_temp_dir(), 'seed');
        file_put_contents($seed, str_repeat("\0", 100));

        $this->expectException(\RuntimeException::class);
        $this->index()->sync(self::REPO, [self::chunk('4444444444444444', 'a')], $seed);
    }

    public function testExportedVectorsRebuildTheRepoWithoutCallingTheModel(): void
    {
        $chunks = [self::chunk('1111111111111111', 'laravel queues'), self::chunk('2222222222222222', 'laravel routes')];
        $this->index()->sync('laravel/docs', $chunks, $this->seedFile([]));
        $this->index()->sync(self::REPO, [self::chunk('3333333333333333', 'symfony words')], $this->seedFile([]));
        $before = $this->db->fetchAllKeyValue('SELECT id, embedding FROM chunks ORDER BY id');
        $file = tempnam(sys_get_temp_dir(), 'export');

        self::assertSame(2, $this->index()->exportSeeds('laravel/docs', $file));
        self::assertSame(2 * (8 + Embedder::DIMENSIONS * 4), filesize($file)); // the other repo is not in it

        $this->db->executeStatement("DELETE FROM chunks WHERE metadata->>'repo' = 'laravel/docs'");
        FakeEmbeddings::$inputs = [];
        $stats = $this->index()->sync('laravel/docs', $chunks, $file);

        self::assertSame(['seeded' => 2, 'embedded' => 0, 'removed' => 0, 'kept' => 0], $stats);
        self::assertSame([], FakeEmbeddings::$inputs);
        self::assertSame($before, $this->db->fetchAllKeyValue('SELECT id, embedding FROM chunks ORDER BY id'));
    }

    public function testVectorsOfAnotherModelAreNeverExportedAsShippedOnes(): void
    {
        // shipped vectors are only read under the default model, so other ones would be read as if they were its
        $index = new ChunkIndex(new Embedder(new Vectorizer(FakeEmbeddings::platform(), 'other-embedder'), $this->db, 'other-embedder'), $this->store, $this->db);
        $index->sync(self::REPO, [self::chunk('5555555555555555', 'a')], $this->seedFile([]));
        $file = sys_get_temp_dir().'/export'.bin2hex(random_bytes(6));

        try {
            $index->exportSeeds(self::REPO, $file);
            self::fail('exported');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('other-embedder', $e->getMessage());
        }
        self::assertFileDoesNotExist($file);
    }
}
