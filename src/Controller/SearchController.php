<?php

namespace App\Controller;

use App\Docs\Retriever;
use App\Docs\TestSet;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    private const MAX_QUERY = 500;
    private const SHOWN = 10;
    private const EXAMPLES = [
        'send people somewhere else when they type a wrong password',
        'stop validating a field after the first rule that fails',
        'tell if code is running in a persistent process mode rather than per request',
        'why does my post-login account check run on each API request',
        'PhpArrayAdapter',
        'move an old standard edition project to the new recipe tool',
    ];

    public function __construct(
        private readonly Retriever $retriever,
        #[Autowire('%kernel.project_dir%')] private readonly string $root,
    ) {
    }

    #[Route('/', methods: ['GET'])]
    public function page(Connection $db): Response
    {
        $results = "$this->root/data/eval_results.json";

        return $this->render('search.html.twig', [
            'examples' => self::EXAMPLES,
            'maxQuery' => self::MAX_QUERY,
            'chunks' => (int) $db->fetchOne('SELECT count(*) FROM chunks'),
            'results' => is_file($results) ? json_decode(file_get_contents($results), true, 32, \JSON_THROW_ON_ERROR) : [],
        ]);
    }

    #[Route('/search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
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

        $start = hrtime(true);
        $words = $this->retriever->keyword($query, self::SHOWN);
        $wordsMs = round((hrtime(true) - $start) / 1e6, 1);
        $meaning = $this->retriever->search($query, self::SHOWN, $request->query->getBoolean('rerank'));
        $question = TestSet::find("$this->root/data/queries", $query);

        return $this->json([
            'words' => ['hits' => array_map(self::view(...), $words), 'ms' => ['keyword' => $wordsMs]],
            'meaning' => ['hits' => array_map(self::view(...), $meaning['hits']), 'mode' => $meaning['mode'], 'embedding' => $meaning['embedding'], 'rerank' => $meaning['rerank'], 'ms' => $meaning['ms']],
            'test' => [
                'known' => null !== $question,
                'words' => $question ? TestSet::rank($question, $words) : null,
                'meaning' => $question ? TestSet::rank($question, $meaning['hits']) : null,
            ],
        ]);
    }

    private static function view(array $hit): array
    {
        return ['file' => $hit['file'], 'path' => implode(' > ', $hit['path']), 'source' => $hit['source'], 'url' => self::url($hit)];
    }

    /**
     * The hit's section on symfony.com, so a reader whose chunk holds only part of the answer can read on.
     * ponytail: heading slug matched 310 of 312 live anchors checked; a missing anchor still opens the page.
     */
    private static function url(array $hit): string
    {
        $url = 'https://symfony.com/doc/current/'.preg_replace('/\.rst$/', '.html', $hit['file']);
        $section = \count($hit['path']) > 1 ? trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(end($hit['path']))), '-') : '';

        return '' !== $section ? "$url#$section" : $url;
    }
}
