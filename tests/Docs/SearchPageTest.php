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
        $c->get(ChunkIndex::class)->store(SearchTestCase::REPO, [
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

    public function testSearchReturnsOnlyTheMeaningResultsAsJson(): void
    {
        $client = self::createClient();
        $this->seed($client);
        $client->request('GET', '/search', ['q' => 'redirect login failed attempt users with failure']);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('vector', $data['meaning']['mode']);
        self::assertSame('security.rst', $data['meaning']['hits'][0]['file']);
        self::assertSame('Security > Login', $data['meaning']['hits'][0]['path']);
        self::assertArrayHasKey('source', $data['meaning']['hits'][0]);
        self::assertArrayNotHasKey('words', $data);
        self::assertArrayNotHasKey('words', $data['test']);
        self::assertSame('off', $data['meaning']['rerank']);
    }

    public function testEachHitLinksToItsSectionOnSymfonyCom(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        $c->get(Connection::class)->executeStatement('TRUNCATE chunks, chunk_terms, query_vectors');
        $c->get(ChunkIndex::class)->store(SearchTestCase::REPO, [
            ['id' => 'a', 'file' => 'mailer.rst', 'path' => ['Sending Emails with Mailer', 'Using a 3rd Party Transport'], 'text' => 'mailer transport', 'source' => 's'],
            ['id' => 'b', 'file' => 'reference/constraints/Time.rst', 'path' => ['Time'], 'text' => 'mailer time', 'source' => 's'],
        ], [FakeEmbeddings::vector('mailer transport'), FakeEmbeddings::vector('mailer time')]);
        $client->request('GET', '/search', ['q' => 'mailer']);

        $urls = array_column(json_decode($client->getResponse()->getContent(), true)['meaning']['hits'], 'url', 'file');
        // anchors are the heading slugs symfony.com uses; a page's own title needs none
        self::assertSame('https://symfony.com/doc/current/mailer.html#using-a-3rd-party-transport', $urls['mailer.rst']);
        self::assertSame('https://symfony.com/doc/current/reference/constraints/Time.html', $urls['reference/constraints/Time.rst']);
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
        $question = \App\Docs\TestSet::load(self::getContainer()->getParameter('kernel.project_dir').'/data/queries/symfony/symfony-docs/fresh.tsv')[0]['query'];
        $client->request('GET', '/search', ['q' => $question]);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['test']['known']);
        self::assertNull($data['test']['meaning']);

        // a Symfony question asked of another repo has no answer key there
        $client->request('GET', '/search', ['q' => $question, 'repo' => 'laravel/docs']);
        self::assertFalse(json_decode($client->getResponse()->getContent(), true)['test']['known']);
    }

    public function testSearchRunsOnTheChosenRepoAndLinksToItsSite(): void
    {
        $client = self::createClient();
        $c = self::getContainer();
        $c->get(Connection::class)->executeStatement('TRUNCATE chunks, chunk_terms, query_vectors');
        $c->get(ChunkIndex::class)->store(SearchTestCase::REPO, [
            ['id' => 's', 'file' => 'routing.rst', 'path' => ['Routing'], 'text' => 'route parameters', 'source' => 's'],
        ], [FakeEmbeddings::vector('route parameters')]);
        $c->get(ChunkIndex::class)->store('laravel/docs', [
            ['id' => 'l', 'file' => 'routing.md', 'path' => ['Routing', 'Optional Parameters'], 'text' => 'route parameters', 'source' => 's', 'anchor' => 'parameters-optional-parameters'],
        ], [FakeEmbeddings::vector('route parameters')]);

        $client->request('GET', '/search', ['q' => 'route parameters', 'repo' => 'laravel/docs']);
        $hits = json_decode($client->getResponse()->getContent(), true)['meaning']['hits'];
        self::assertSame(['https://laravel.com/docs/13.x/routing#parameters-optional-parameters'], array_column($hits, 'url'));

        $client->request('GET', '/search', ['q' => 'route parameters']); // no repo: DOCS_REPO
        self::assertSame(['routing.rst'], array_column(json_decode($client->getResponse()->getContent(), true)['meaning']['hits'], 'file'));
    }

    public function testAnUnknownRepoIsRefusedBeforeAnyModelCall(): void
    {
        $client = self::createClient();
        FakeEmbeddings::$inputs = [];
        foreach (['/search' => 400, '/' => 404] as $url => $status) {
            $client->request('GET', $url, ['q' => 'anything at all', 'repo' => "x' OR 1=1 --"]);
            self::assertResponseStatusCodeSame($status);
        }
        self::assertSame([], FakeEmbeddings::$inputs);
    }

    public function testThePageOffersEveryRepoWithTheChosenOneSelected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        self::assertSelectorTextSame('select[name=repo] option[selected]', 'Symfony');

        $client->request('GET', '/', ['repo' => 'laravel/docs']);
        self::assertSelectorTextSame('select[name=repo] option[selected]', 'Laravel');
        self::assertSelectorTextContains('h1', 'Laravel');
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

    public function testAVisitorGetsSearchLimitSearchesAMinute(): void
    {
        // each search can be a paid model call: a script looping on the endpoint must not run up the bill
        $client = self::createClient();
        $client->disableReboot(); // one container for every request, so the count carries over
        $this->seed($client);
        $limit = self::getContainer()->getParameter('app.search_limit');
        self::assertSame(10, $limit); // SEARCH_LIMIT in .env: the page's six examples fit in a minute

        for ($i = 1; $i <= $limit; ++$i) {
            $client->request('GET', '/search', ['q' => "question $i"]);
            self::assertResponseIsSuccessful();
        }
        $client->request('GET', '/search', ['q' => 'one too many']);
        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('minute', json_decode($client->getResponse()->getContent(), true)['error']);

        $client->request('GET', '/search', ['q' => 'another visitor'], server: ['REMOTE_ADDR' => '10.0.0.2']);
        self::assertResponseIsSuccessful();
    }
}
