<?php

namespace App\Tests\Docs;

use App\Docs\ChunkIndex;
use App\Docs\Embedder;
use Symfony\AI\Platform\Result\VectorResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\AI\Platform\Vector\Vector;

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

        $stats = $this->index()->sync([self::chunk('aaaaaaaaaaaaaaaa', 'seeded')], $seed);

        self::assertSame(['seeded' => 1, 'embedded' => 0, 'removed' => 0, 'kept' => 0], $stats);
        self::assertSame([], FakeEmbeddings::$inputs);
        self::assertSame(self::axis(7)[7], (float) json_decode($this->db->fetchOne('SELECT embedding FROM chunks'), true)[7]);
    }

    public function testAChunkWithNoShippedVectorIsEmbeddedInTheDocumentFormat(): void
    {
        $stats = $this->index()->sync([self::chunk('bbbbbbbbbbbbbbbb', 'new words', ['Top', 'Sub'])], $this->seedFile([]));

        self::assertSame(1, $stats['embedded']);
        self::assertSame([['title: Top > Sub | text: new words']], FakeEmbeddings::$inputs);
    }

    public function testASecondSyncDoesNoWork(): void
    {
        $seed = $this->seedFile([]);
        $this->index()->sync([self::chunk('cccccccccccccccc', 'x y')], $seed);
        FakeEmbeddings::$inputs = [];

        $stats = $this->index()->sync([self::chunk('cccccccccccccccc', 'x y')], $seed);

        self::assertSame(['seeded' => 0, 'embedded' => 0, 'removed' => 0, 'kept' => 1], $stats);
        self::assertSame([], FakeEmbeddings::$inputs);
    }

    public function testChunksGoneFromTheDocsAreRemovedWithTheirTerms(): void
    {
        $seed = $this->seedFile([]);
        $this->index()->sync([self::chunk('dddddddddddddddd', 'old words'), self::chunk('eeeeeeeeeeeeeeee', 'kept words')], $seed);

        $stats = $this->index()->sync([self::chunk('eeeeeeeeeeeeeeee', 'kept words')], $seed);

        self::assertSame(1, $stats['removed']);
        self::assertSame(['eeeeeeeeeeeeeeee'], $this->ids());
        self::assertSame(0, (int) $this->db->fetchOne("SELECT count(*) FROM chunk_terms WHERE chunk_id = 'dddddddddddddddd'"));
    }

    public function testAWrongDocsPathCannotWipeTheIndex(): void
    {
        $seed = $this->seedFile([]);
        $this->index()->sync([self::chunk('ffffffffffffffff', 'precious')], $seed);

        try {
            $this->index()->sync([], $seed);
            self::fail('an empty chunk list must be refused');
        } catch (\RuntimeException) {
        }

        self::assertSame(['ffffffffffffffff'], $this->ids());
    }

    public function testAnIndexBuiltWithAnotherModelIsNeverMixedWithThisOne(): void
    {
        $seed = $this->seedFile([]);
        $this->index()->sync([self::chunk('1111111111111111', 'old')], $seed);
        self::assertSame(Embedder::MODEL, $this->db->fetchOne('SELECT model FROM index_meta'));
        $this->db->executeStatement('UPDATE index_meta SET model = ?', ['some-other-model']);
        FakeEmbeddings::$inputs = [];

        try {
            $this->index()->sync([self::chunk('2222222222222222', 'new')], $seed);
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
        $index = new ChunkIndex(new Embedder($platform, $this->db, ''), $this->store, $this->db);

        try {
            $index->sync([self::chunk('1111111111111111', 'a'), self::chunk('2222222222222222', 'b')], $this->seedFile([]));
            self::fail('a short reply must be refused');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->ids());
    }

    public function testAVectorOfTheWrongSizeIsRefused(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn () => new VectorResult([new Vector([1.0, 0.0])]));
        $index = new ChunkIndex(new Embedder($platform, $this->db, ''), $this->store, $this->db);

        $this->expectException(\RuntimeException::class);
        $index->sync([self::chunk('3333333333333333', 'a')], $this->seedFile([]));
    }

    public function testATruncatedSeedFileIsRefused(): void
    {
        $seed = tempnam(sys_get_temp_dir(), 'seed');
        file_put_contents($seed, str_repeat("\0", 100));

        $this->expectException(\RuntimeException::class);
        $this->index()->sync([self::chunk('4444444444444444', 'a')], $seed);
    }
}
