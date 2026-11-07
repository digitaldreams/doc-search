<?php

namespace App\Tests\Mcp;

use App\Docs\Chunker;
use App\Docs\ChunkIndex;
use App\Mcp\SearchDocs;
use App\Tests\Docs\FakeEmbeddings;
use App\Tests\Docs\SearchTestCase;
use Doctrine\DBAL\Connection;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

final class SearchDocsTest extends WebTestCase
{
    private const LOGIN = 'Redirect users after a failed login attempt with failure_path';

    private function seed(): void
    {
        $c = self::getContainer();
        $c->get(Connection::class)->executeStatement('TRUNCATE chunks, chunk_terms, query_vectors');
        $c->get(ChunkIndex::class)->store(SearchTestCase::REPO, [
            Chunker::document(['id' => 'a', 'file' => 'security.rst', 'path' => ['Security', 'Login'], 'text' => self::LOGIN, 'source' => self::LOGIN]),
            Chunker::document(['id' => 'b', 'file' => 'cache.rst', 'path' => ['Cache'], 'text' => 'Cache pools', 'source' => 'Cache pools']),
        ], [FakeEmbeddings::vector(self::LOGIN), FakeEmbeddings::vector('cache pools')]);
    }

    public function testAnswersWithTheBestPassageAsMarkdownWithItsLink(): void
    {
        self::bootKernel();
        $this->seed();

        $out = self::getContainer()->get(SearchDocs::class)('redirect login failed attempt users with failure', SearchTestCase::REPO, 1);

        self::assertStringStartsWith("## Security > Login\n", $out);
        self::assertStringContainsString(self::LOGIN, $out);
        self::assertStringContainsString('https://symfony.com/doc/current/security.html#login', $out);
        self::assertStringNotContainsString('Cache pools', $out, 'limit 1 shows one passage');
    }

    public function testSaysSoWhenTheEmbedderIsDownAndOnlyKeywordsWereUsed(): void
    {
        self::bootKernel();
        // a query never embedded before + a model host that fails = keyword fallback
        self::getContainer()->set('ai.vectorizer.docs', new \Symfony\AI\Store\Document\Vectorizer(
            \Symfony\AI\Platform\Test\MockPlatformFactory::createPlatform(static fn () => throw new \RuntimeException('down')), 'test-model'));
        $this->seed();

        $out = self::getContainer()->get(SearchDocs::class)('failure_path', SearchTestCase::REPO);

        self::assertStringStartsWith('Keyword-only results', $out);
        self::assertStringContainsString(self::LOGIN, $out);
    }

    public function testRejectsAnUnknownDocsSetAndNamesTheKnownOnes(): void
    {
        self::bootKernel();
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('symfony/symfony-docs');

        self::getContainer()->get(SearchDocs::class)('routes', 'nope/nope');
    }

    public function testRejectsAnEmptyOrOverlongQuery(): void
    {
        self::bootKernel();
        $tool = self::getContainer()->get(SearchDocs::class);

        foreach (['  ', str_repeat('a', 501)] as $q) {
            try {
                $tool($q, SearchTestCase::REPO);
                self::fail('accepted '.\strlen($q).' chars');
            } catch (ToolCallException $e) {
                self::assertStringContainsString('500', $e->getMessage());
            }
        }
    }

    public function testTheDocsEnumListsExactlyTheConfiguredRepos(): void
    {
        $param = new \ReflectionParameter([SearchDocs::class, '__invoke'], 'docs');
        $enum = $param->getAttributes(Schema::class)[0]->newInstance()->enum;
        // the real list, not the test env's two-repo override
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);

        self::assertSame(array_keys($config['parameters']['app.docs_repos']), $enum);
    }

    public function testAnMcpClientCanListAndCallTheTool(): void
    {
        $client = self::createClient();
        $this->seed();
        $rpc = static function (array $msg, ?string $session = null) use ($client) {
            $client->request('POST', '/mcp', server: array_filter([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json, text/event-stream',
                'HTTP_MCP_SESSION_ID' => $session,
            ]), content: json_encode(['jsonrpc' => '2.0'] + $msg));

            return $client->getResponse();
        };

        $init = $rpc(['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]]);
        $session = $init->headers->get('Mcp-Session-Id');
        self::assertNotNull($session, $init->getContent());
        $rpc(['method' => 'notifications/initialized'], $session);

        $tools = json_decode($rpc(['id' => 2, 'method' => 'tools/list'], $session)->getContent(), true)['result']['tools'];
        self::assertSame(['search_docs'], array_column($tools, 'name'));

        $call = json_decode($rpc(['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'search_docs', 'arguments' => [
            'query' => 'redirect login failed attempt users with failure', 'docs' => SearchTestCase::REPO, 'limit' => 1,
        ]]], $session)->getContent(), true)['result'];
        self::assertFalse($call['isError'] ?? false);
        self::assertStringStartsWith('## Security > Login', $call['content'][0]['text']);
    }
}
