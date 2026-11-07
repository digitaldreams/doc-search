<?php

namespace App\Tests\Docs;

use App\Docs\Chunker;
use App\Docs\Embedder;
use App\Docs\Reranker;
use Symfony\AI\Platform\Bridge\Gemini\Factory as GeminiFactory;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The exact requests each provider receives: Ollama's must match what the app sent before the switch to
 * ai.yaml (same vectors, same rerank), and Gemini's prove the commented config in ai.yaml is valid.
 */
final class ProviderPayloadTest extends SearchTestCase
{
    /** @var list<array{url: string, body: array<string, mixed>}> */
    private array $sent = [];

    private function http(\Closure $reply): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use ($reply): MockResponse {
            $body = json_decode($options['body'] ?? 'null', true) ?? [];
            $this->sent[] = ['url' => $url, 'body' => $body];

            return new MockResponse(json_encode($reply($url, $body), \JSON_THROW_ON_ERROR));
        });
    }

    /** As ai-bundle wires every platform: with the structured-output subscriber, which turns a schema reply into an object. */
    private static function events(): EventDispatcher
    {
        $events = new EventDispatcher();
        $events->addSubscriber(new PlatformSubscriber());

        return $events;
    }

    private function ollama(): \Symfony\AI\Platform\PlatformInterface
    {
        return OllamaFactory::createPlatform('http://ollama.test', eventDispatcher: self::events(), httpClient: $this->http(static fn (string $url, array $body) => match (true) {
            str_ends_with($url, '/api/show') => ['capabilities' => [str_starts_with($body['model'], 'embedding') ? 'embedding' : 'completion']],
            str_ends_with($url, '/api/embed') => ['embeddings' => array_map(static fn () => FakeEmbeddings::vector('x'), (array) $body['input'])],
            default => ['message' => ['content' => '{"best":[1]}'], 'done' => true],
        }));
    }

    private function gemini(): \Symfony\AI\Platform\PlatformInterface
    {
        return GeminiFactory::createPlatform('test-key', eventDispatcher: self::events(), httpClient: $this->http(static fn (string $url, array $body) => str_contains($url, 'batchEmbedContents')
            ? ['embeddings' => array_map(static fn () => ['values' => FakeEmbeddings::vector('x')], $body['requests'])]
            : ['candidates' => [['content' => ['parts' => [['text' => '{"best":[1]}']]], 'finishReason' => 'STOP']]]));
    }

    private function last(string $urlPart): array
    {
        foreach (array_reverse($this->sent) as $request) {
            if (str_contains($request['url'], $urlPart)) {
                return $request['body'];
            }
        }
        self::fail("no request to $urlPart");
    }

    private static function hit(): array
    {
        return ['id' => 'h1', 'file' => 'f.rst', 'path' => ['Top', 'Sub'], 'text' => 'body', 'source' => 'body'];
    }

    private static function envOptions(string $json): array
    {
        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testOllamaEmbeddingRequestsAreTheOnesTheShippedVectorsCameFrom(): void
    {
        $embedder = new Embedder(new Vectorizer($this->ollama(), Embedder::DEFAULT_MODEL), $this->db);

        $embedder->documents([Chunker::document(['id' => 'a', 'file' => 'f.rst', 'path' => ['Top', 'Sub'], 'text' => 'body', 'source' => 'body'])]);
        self::assertSame(['model' => Embedder::DEFAULT_MODEL, 'input' => ['title: Top > Sub | text: body']], $this->last('/api/embed'));

        $embedder->query('how?');
        self::assertSame(['model' => Embedder::DEFAULT_MODEL, 'input' => 'task: search result | query: how?'], $this->last('/api/embed'));
    }

    public function testOllamaRerankRequestKeepsTheMeasuredSettings(): void
    {
        $options = self::envOptions('{"think":false,"num_predict":60,"num_ctx":8192}'); // RERANK_OPTIONS in .env

        $order = (new Reranker($this->ollama(), 'gemma4:latest', $options))->rerank('q', [self::hit(), ['id' => 'h2'] + self::hit()]);

        self::assertSame(['h1', 'h2'], array_column($order, 'id'));

        $chat = $this->last('/api/chat');
        self::assertSame('gemma4:latest', $chat['model']);
        self::assertFalse($chat['think']);
        self::assertSame(['best'], $chat['format']['required']);
        self::assertSame(['temperature' => 0, 'num_predict' => 60, 'num_ctx' => 8192], $chat['options']);
    }

    public function testGeminiEmbeddingsFitTheVectorColumn(): void
    {
        $embedder = new Embedder(new Vectorizer($this->gemini(), 'gemini-embedding-001?dimensions=768'), $this->db, 'gemini-embedding-001');

        $vector = $embedder->query('how?')['vector'];

        self::assertCount(768, $vector);
        $request = $this->last('gemini-embedding-001:batchEmbedContents')['requests'][0];
        self::assertSame(768, $request['outputDimensionality']);
        self::assertSame('task: search result | query: how?', $request['content']['parts'][0]['text']);
    }

    public function testGeminiRerankRequestGetsASchemaAndNoThinking(): void
    {
        $options = self::envOptions('{"maxOutputTokens":60,"thinkingConfig":{"thinkingBudget":0}}'); // Gemini RERANK_OPTIONS

        $order = (new Reranker($this->gemini(), 'gemini-2.5-flash', $options))->rerank('q', [self::hit()]);

        self::assertSame(['h1'], array_column($order, 'id'));
        $config = $this->last('gemini-2.5-flash:generateContent')['generationConfig'];
        self::assertSame(['best'], $config['responseJsonSchema']['required']);
        self::assertSame(['thinkingBudget' => 0], $config['thinkingConfig']);
        self::assertSame(60, $config['maxOutputTokens']);
        self::assertSame(0, $config['temperature']);
    }
}
