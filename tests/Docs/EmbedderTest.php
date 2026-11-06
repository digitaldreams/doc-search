<?php

namespace App\Tests\Docs;

final class EmbedderTest extends SearchTestCase
{
    public function testQueryIsSentInTheModelCardFormatAndNormalized(): void
    {
        $query = $this->embedder()->query('zebra zebra');

        self::assertSame('live', $query['source']);
        self::assertSame([['task: search result | query: zebra zebra']], FakeEmbeddings::$inputs);
        self::assertEqualsWithDelta(1.0, sqrt(array_sum(array_map(static fn ($x) => $x * $x, $query['vector']))), 1e-9);
    }

    public function testASavedVectorAnswersWhenTheHostIsUnreachableAndSaysSo(): void
    {
        $saved = $this->embedder()->query('known question')['vector'];

        $query = $this->embedder(host: 'http://127.0.0.1:1')->query('known question');

        self::assertSame('saved', $query['source']);
        self::assertEqualsWithDelta($saved[crc32('known') % 768], $query['vector'][crc32('known') % 768], 1e-6);
    }

    public function testAnUnknownQuestionWithAnUnreachableHostHasNoVector(): void
    {
        $query = $this->embedder(host: 'http://127.0.0.1:1')->query('never asked');

        self::assertSame('none', $query['source']);
        self::assertNull($query['vector']);
    }

    public function testAHostThatAcceptsButNeverAnswersIsGivenUpOnQuickly(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0'); // listens, so a connection succeeds, but never answers
        $host = 'http://'.stream_socket_get_name($server, false);
        $embedder = new \App\Docs\Embedder(FakeEmbeddings::platform(), $this->db, $host, \Symfony\Component\HttpClient\HttpClient::create(), 1.0);

        $start = microtime(true);
        $query = $embedder->query('asked while the host hangs');

        self::assertSame('none', $query['source']);
        self::assertLessThan(5, microtime(true) - $start);
    }

    public function testAQueryEmbeddingOfTheWrongSizeIsNotUsed(): void
    {
        self::assertSame('none', $this->embedder([1.0, 0.0])->query('q')['source']);
    }

    public function testAnAllZeroVectorIsNotUsed(): void
    {
        self::assertSame('none', $this->embedder(array_fill(0, 768, 0.0))->query('q')['source']);
    }
}
