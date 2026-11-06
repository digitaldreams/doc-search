<?php

namespace App\Tests\Docs;

use App\Docs\ChunkIndex;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SearchPageTest extends WebTestCase
{
    private function seed($client): void
    {
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        $db->executeStatement('TRUNCATE chunks, chunk_terms, query_vectors');
        $text = 'Redirect users after a failed login attempt with failure_path';
        $c->get(ChunkIndex::class)->store([
            ['id' => 'a', 'file' => 'security.rst', 'path' => ['Security', 'Login'], 'text' => $text, 'source' => $text],
            ['id' => 'b', 'file' => 'cache.rst', 'path' => ['Cache'], 'text' => 'Cache pools', 'source' => 'Cache pools'],
        ], [FakeEmbeddings::vector($text), FakeEmbeddings::vector('cache pools')]);
    }

    public function testThePageRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name=q]');
    }

    public function testSearchReturnsBothColumnsAsJson(): void
    {
        $client = self::createClient();
        $this->seed($client);
        $client->request('GET', '/search', ['q' => 'redirect login failed attempt users with failure']);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('vector', $data['meaning']['mode']);
        self::assertSame('security.rst', $data['meaning']['hits'][0]['file']);
        self::assertSame('Security > Login', $data['meaning']['hits'][0]['path']);
        self::assertSame('security.rst', $data['words']['hits'][0]['file']);
        self::assertArrayHasKey('source', $data['words']['hits'][0]);
        self::assertSame('off', $data['meaning']['rerank']);
    }

    public function testAnotherSiteCannotMakeTheBrowserRunASearch(): void
    {
        $client = self::createClient();
        FakeEmbeddings::$inputs = [];
        // what a browser sends for <img src="http://127.0.0.1:8150/search?..."> on any other page, other localhost ports included
        foreach (['cross-site', 'same-site'] as $site) {
            $client->request('GET', '/search', ['q' => 'anything at all', 'rerank' => '1'], server: ['HTTP_SEC_FETCH_SITE' => $site]);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame([], FakeEmbeddings::$inputs);
    }

    public function testTheAppsOwnPageCanSearch(): void
    {
        $client = self::createClient();
        $this->seed($client);
        $client->request('GET', '/search', ['q' => 'cache pools'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseIsSuccessful();
    }

    public function testAQuestionFromATestSetReportsWhereItsAnswerRanked(): void
    {
        $client = self::createClient();
        $this->seed($client);
        // a real question from data/queries; its answer is not in this tiny index
        $question = \App\Docs\TestSet::load(self::getContainer()->getParameter('kernel.project_dir').'/data/queries/fresh.tsv')[0]['query'];
        $client->request('GET', '/search', ['q' => $question]);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['test']['known']);
        self::assertNull($data['test']['words']);
        self::assertNull($data['test']['meaning']);
    }

    public function testAnOrdinaryQuestionHasNoAnswerKey(): void
    {
        $client = self::createClient();
        $this->seed($client);
        $client->request('GET', '/search', ['q' => 'something nobody wrote a test for']);

        self::assertFalse(json_decode($client->getResponse()->getContent(), true)['test']['known']);
    }

    public function testAnEmptyQuestionIsRefused(): void
    {
        $client = self::createClient();
        $client->request('GET', '/search', ['q' => '   ']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testAnOverlongQuestionIsRefusedBeforeAnyModelCall(): void
    {
        $client = self::createClient();
        FakeEmbeddings::$inputs = [];
        $client->request('GET', '/search', ['q' => str_repeat('x', 501)]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], FakeEmbeddings::$inputs);
    }

    public function testAnArrayInsteadOfAStringIsRefused(): void
    {
        $client = self::createClient();
        $client->request('GET', '/search', ['q' => ['a', 'b']]);

        self::assertResponseStatusCodeSame(400);
    }
}
