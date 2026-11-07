<?php

namespace App\Controller;

use App\Docs\Repos;
use App\Docs\Retriever;
use App\Docs\TestSet;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    private const MAX_QUERY = 500;
    private const SHOWN = 10;

    public function __construct(
        private readonly Retriever $retriever,
        private readonly Repos $repos,
        #[Autowire('%kernel.project_dir%')] private readonly string $root,
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function page(Request $request, Connection $db): Response
    {
        $repo = $this->repo($request) ?? throw $this->createNotFoundException('Unknown docs repo.');
        $results = "$this->root/data/eval_results/$repo.json";

        return $this->render('search.html.twig', [
            'repo' => $repo,
            'repos' => $this->repos->labels(),
            'examples' => $this->repos->get($repo)['examples'] ?? [],
            'maxQuery' => self::MAX_QUERY,
            'chunks' => (int) $db->fetchOne("SELECT count(*) FROM chunks WHERE metadata->>'repo' = ?", [$repo]),
            'results' => is_file($results) ? json_decode(file_get_contents($results), true, 32, \JSON_THROW_ON_ERROR) : [],
        ]);
    }

    #[Route('/search', methods: ['GET'])]
    public function search(Request $request, RateLimiterFactoryInterface $searchLimiter): JsonResponse
    {
        // Any page the visitor has open could otherwise point an <img> here and run model work on this machine.
        // ponytail: Fetch Metadata only; browsers too old to send it are not stopped, a token would be the upgrade.
        if (!\in_array($request->headers->get('Sec-Fetch-Site', 'none'), ['same-origin', 'none'], true)) {
            return $this->json(['error' => 'Search from this app\'s own page.'], 403);
        }

        $query = trim($request->query->getString('q'));
        if ('' === $query || mb_strlen($query) > self::MAX_QUERY) {
            return $this->json(['error' => \sprintf('Ask a question of 1 to %d characters.', self::MAX_QUERY)], 400);
        }
        $repo = $this->repo($request);
        if (null === $repo) {
            return $this->json(['error' => 'Unknown docs repo. Pick one from the list.'], 400);
        }
        if (!$searchLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['error' => 'Too many searches this minute. Wait a moment and ask again.'], 429);
        }

        $meaning = $this->retriever->search($query, $repo, self::SHOWN, $request->query->getBoolean('rerank'));
        $question = TestSet::find("$this->root/data/queries/$repo", $query);
        $url = $this->repos->get($repo)['url'];

        return $this->json([
            'meaning' => ['hits' => array_map(static fn (array $hit) => self::view($hit, $url), $meaning['hits']), 'mode' => $meaning['mode'], 'embedding' => $meaning['embedding'], 'rerank' => $meaning['rerank'], 'ms' => $meaning['ms']],
            'test' => [
                'known' => null !== $question,
                'meaning' => $question ? TestSet::rank($question, $meaning['hits']) : null,
            ],
        ]);
    }

    /** The `repo` query value if it names a configured repo, DOCS_REPO when absent, null when unknown. */
    private function repo(Request $request): ?string
    {
        $key = $request->query->getString('repo');
        try {
            return $this->repos->key('' === $key ? null : $key);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function view(array $hit, string $url): array
    {
        return ['file' => $hit['file'], 'path' => implode(' > ', $hit['path']), 'source' => $hit['source'], 'url' => Repos::url($hit, $url)];
    }
}
