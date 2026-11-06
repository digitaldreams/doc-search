<?php

namespace App\Tests\Docs;

final class EmbedderTest extends SearchTestCase
{
    public function testQueryIsSentInTheModelCardFormatAndNormalized(): void
    {
        $query = $this->embedder()->query('zebra zebra');

        self::assertSame('live', $query['source']);
        self::assertSame(['task: search result | query: zebra zebra'], FakeEmbeddings::$inputs);
        self::assertEqualsWithDelta(1.0, sqrt(array_sum(array_map(static fn ($x) => $x * $x, $query['vector']))), 1e-9);
    }

    public function testASavedVectorAnswersWhenTheHostIsUnreachableAndSaysSo(): void
    {
        $saved = $this->embedder()->query('known question')['vector'];

        $query = $this->embedder(down: true)->query('known question');

        self::assertSame('saved', $query['source']);
        self::assertEqualsWithDelta($saved[crc32('known') % 768], $query['vector'][crc32('known') % 768], 1e-6);
    }

    public function testAnUnknownQuestionWithAnUnreachableHostHasNoVector(): void
    {
        $query = $this->embedder(down: true)->query('never asked');

        self::assertSame('none', $query['source']);
        self::assertNull($query['vector']);
    }

    public function testAModelHostThatIsOffIsGivenUpOnWithinASecond(): void
    {
        // 192.0.2.1 is TEST-NET-1 (RFC 5737): never routed, so a connect attempt hangs until something cuts it off
        $client = self::getContainer()->get('app.ai_http_client');

        $start = microtime(true);
        try {
            $client->request('GET', 'http://192.0.2.1:11434/api/version')->getStatusCode();
            self::fail('an unroutable host cannot answer');
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
        }

        self::assertLessThan(3, microtime(true) - $start);
    }

    public function testAModelHostThatConnectsButNeverAnswersAnEmbeddingIsGivenUpOn(): void
    {
        // listening but never accepting: the connect succeeds and no reply ever comes, like a stuck Ollama
        $stalled = stream_socket_server('tcp://127.0.0.1:0');
        $client = self::getContainer()->get('app.ai_http_client');

        $start = microtime(true);
        try {
            $client->request('POST', 'http://'.stream_socket_get_name($stalled, false).'/api/embed')->getStatusCode();
            self::fail('a host that never answers cannot return a status');
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {
        }

        self::assertLessThan(3, microtime(true) - $start); // the test env's embedding timeout is 1 s
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
