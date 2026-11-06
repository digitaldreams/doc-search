<?php

namespace App\Tests\Docs;

final class RetrieverTest extends SearchTestCase
{
    public function testVectorSearchRanksByMeaningEvenWithNoSharedWord(): void
    {
        $this->index()->store([self::chunk('near', 'redirect after failed authentication'), self::chunk('far', 'cache pools')], [self::axis(1), self::axis(2)]);

        $found = $this->retriever(self::axis(1))->search('send people somewhere else when they type a wrong password');

        self::assertSame('vector', $found['mode']);
        self::assertSame(['near', 'far'], array_column($found['hits'], 'id'));
        self::assertSame('live', $found['embedding']);
    }

    public function testHitsCarryEverythingTheReaderAndTheRerankerNeed(): void
    {
        $this->index()->store([['id' => 'a', 'file' => 'x/y.rst', 'path' => ['Top', 'Sub'], 'text' => 'embedded', 'source' => 'all formats']], [self::axis(1)]);

        $hit = $this->retriever(self::axis(1))->search('a question with many plain words here')['hits'][0];

        self::assertSame(['id' => 'a', 'file' => 'x/y.rst', 'path' => ['Top', 'Sub'], 'text' => 'embedded', 'source' => 'all formats'], $hit);
    }

    public function testTheSameTextFromAnotherPageMovesBehindDifferentPassages(): void
    {
        // the `payload` option, inlined into every constraint page, must not fill the top results
        $this->index()->store([
            self::chunk('a', 'payload option text', ['NotBlank', 'payload'], 'NotBlank.rst'),
            self::chunk('b', 'payload option text', ['Email', 'payload'], 'Email.rst'),
            self::chunk('c', 'something different', ['Validation'], 'validation.rst'),
        ], [self::axis(1), self::axis(1, 2), self::axis(1, 3, 4)]);

        $found = $this->retriever(self::axis(1))->search('a plain question with many words in it');

        self::assertSame(['a', 'c', 'b'], array_column($found['hits'], 'id'));
    }

    public function testCodeShapedQueryPullsTheExactIdentifierAboveVectorNeighbours(): void
    {
        // vectors put "exact" last; the keyword list has it first and alone
        $chunks = [self::chunk('n1', 'near one'), self::chunk('n2', 'near two'), self::chunk('exact', 'set max_host_connections here')];
        $this->index()->store($chunks, [self::axis(1), self::axis(1, 2), self::axis(3)]);

        $found = $this->retriever(self::axis(1))->search('max_host_connections');

        self::assertSame('hybrid', $found['mode']);
        // RRF with k=60: exact = 1/63 + 1/61, n1 = 1/61, n2 = 1/62
        self::assertSame(['exact', 'n1', 'n2'], array_column($found['hits'], 'id'));
    }

    public function testPlainQuestionNeverUsesKeywords(): void
    {
        $this->index()->store([self::chunk('v', 'unrelated words'), self::chunk('k', 'plain question words here again')], [self::axis(1), self::axis(9)]);

        $found = $this->retriever(self::axis(1))->search('a plain question with words here again');

        self::assertSame('vector', $found['mode']);
        self::assertSame('v', $found['hits'][0]['id']);
    }

    public function testKeywordRankingWeighsRareTermsAboveCommonOnes(): void
    {
        $this->index()->store([
            self::chunk('common', 'the the the the the the'),
            self::chunk('rare', 'the zebra'),
            self::chunk('other1', 'the cat'),
            self::chunk('other2', 'the dog'),
        ], array_fill(0, 4, self::axis(1)));

        self::assertSame('rare', $this->retriever(self::axis(1))->keyword('the zebra', 10)[0]['id']);
    }

    public function testUnderscoreIdentifierIsOneTerm(): void
    {
        $this->index()->store([self::chunk('id', 'type rotating_file here'), self::chunk('word', 'rotating file')], [self::axis(1), self::axis(2)]);

        self::assertSame(['id'], array_column($this->retriever(self::axis(1))->keyword('rotating_file', 10), 'id'));
    }

    public function testKeywordSearchMatchesTheBreadcrumb(): void
    {
        $this->index()->store([self::chunk('h', 'body words', ['Supervisor Configuration'])], [self::axis(1)]);

        self::assertSame(['h'], array_column($this->retriever(self::axis(1))->keyword('supervisor', 10), 'id'));
    }

    public function testQuotesInAQueryAreDataNotSql(): void
    {
        $this->index()->store([self::chunk('a', 'chunk words')], [self::axis(1)]);

        self::assertSame([], $this->retriever(self::axis(1))->keyword("x'); DROP TABLE chunks; --", 10));
        self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM chunks'));
    }

    public function testQueryWithNoUsableTermFindsNoKeywordHits(): void
    {
        $this->index()->store([self::chunk('a', 'chunk words')], [self::axis(1)]);

        self::assertSame([], $this->retriever(self::axis(1))->keyword('? ! a', 10));
    }

    public function testEmptyQueryFindsNothingAndCallsNoModel(): void
    {
        $found = $this->retriever(self::axis(1))->search("  \n ");

        self::assertSame([], $found['hits']);
        self::assertSame('none', $found['embedding']);
    }

    public function testKeywordsStillAnswerWhenTheModelHostIsUnreachable(): void
    {
        $this->index()->store([self::chunk('a', 'PhpArrayAdapter details')], [self::axis(1)]);
        $retriever = new \App\Docs\Retriever($this->embedder(self::axis(1), 'http://127.0.0.1:1'), $this->store, $this->db);

        $found = $retriever->search('PhpArrayAdapter');

        self::assertSame('keyword', $found['mode']);
        self::assertSame('none', $found['embedding']);
        self::assertSame(['a'], array_column($found['hits'], 'id'));
    }

    public function testAFailedReorderKeepsTheVectorOrderAndSaysSo(): void
    {
        $this->index()->store([self::chunk('first', 'a'), self::chunk('second', 'b')], [self::axis(1), self::axis(1, 2)]);
        // e.g. the laptop host, which has the embedding model but not the reorder model
        $reranker = new \App\Docs\Reranker(\Symfony\AI\Platform\Test\MockPlatformFactory::createPlatform(static fn () => throw new \RuntimeException('model "gemma4:latest" not found')));
        $retriever = new \App\Docs\Retriever($this->embedder(self::axis(1)), $this->store, $this->db, $reranker);

        $found = $retriever->search('a plain question with many words', 10, true);

        self::assertSame(['first', 'second'], array_column($found['hits'], 'id'));
        self::assertSame('failed', $found['rerank']);
        self::assertSame('off', $retriever->search('a plain question with many words')['rerank']);
    }

    public function testASecondReorderWhileOneRunsKeepsTheOrderInsteadOfQueueing(): void
    {
        // a reorder holds the CPU for about a minute: four at once would take every web worker
        $this->index()->store([self::chunk('first', 'a'), self::chunk('second', 'b')], [self::axis(1), self::axis(1, 2)]);
        $calls = 0;
        $reranker = new \App\Docs\Reranker(\Symfony\AI\Platform\Test\MockPlatformFactory::createPlatform(static function () use (&$calls) {
            ++$calls;

            return '{"best":[2,1]}';
        }));
        $locks = new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore());
        $retriever = new \App\Docs\Retriever($this->embedder(self::axis(1)), $this->store, $this->db, $reranker, $locks);

        $running = $locks->createLock('rerank');
        $running->acquire();
        $found = $retriever->search('a plain question with many words', 10, true);

        self::assertSame('busy', $found['rerank']);
        self::assertSame(['first', 'second'], array_column($found['hits'], 'id'));
        self::assertSame(0, $calls);

        $running->release();
        self::assertSame('done', $retriever->search('a plain question with many words', 10, true)['rerank']);
        self::assertSame(1, $calls);
    }
}
