<?php

namespace App\Command;

use App\Docs\Embedder;
use App\Docs\Repos;
use App\Docs\Retriever;
use App\Docs\TestSet;
use Doctrine\DBAL\Connection;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:docs:eval', 'Measure recall on a repo\'s question sets (data/queries/<repo>/) and save it to data/eval_results/<repo>.json')]
final class EvalCommand
{
    private const SETS = ['fresh', 'holdout', 'tuning'];
    private const MODES = ['ours', 'vector', 'keyword', 'store-hybrid', 'store-text'];

    public function __construct(
        private readonly Retriever $retriever,
        private readonly Embedder $embedder,
        private readonly StoreInterface $store,
        private readonly Connection $db,
        private readonly Repos $repos,
        #[Autowire('%kernel.project_dir%')] private readonly string $root,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Comma-separated: ours, vector, keyword, store-hybrid, store-text, rerank')] ?string $modes = null,
        #[Option('Comma-separated: fresh, holdout, tuning')] ?string $sets = null,
        #[Argument('A key of app.docs_repos; DOCS_REPO when left out')] ?string $repo = null,
    ): int {
        $repo = $this->repos->key($repo);
        $sets = $sets ? explode(',', $sets) : self::SETS;
        foreach ($sets as $set) {
            is_file("$this->root/data/queries/$repo/$set.tsv") || throw new \RuntimeException("No question set data/queries/$repo/$set.tsv, nothing measured.");
        }
        $path = "$this->root/data/eval_results/$repo.json";
        $results = is_file($path) ? json_decode(file_get_contents($path), true, 32, \JSON_THROW_ON_ERROR) : [];
        $pages = [];
        foreach ($this->db->iterateNumeric("SELECT metadata->>'file', metadata->>'path', metadata->>'source' FROM chunks WHERE metadata->>'repo' = ?", [$repo]) as [$file, $crumbs, $source]) {
            $pages[$file] = ($pages[$file] ?? '').implode(' ', json_decode($crumbs, true))."\n".$source."\n";
        }

        foreach ($sets as $set) {
            $questions = TestSet::load("$this->root/data/queries/$repo/$set.tsv");
            foreach ($modes ? explode(',', $modes) : self::MODES as $mode) {
                $ranks = $pageRanks = $took = [];
                foreach ($questions as $q) {
                    $start = hrtime(true);
                    $hits = $this->run($mode, $q['query'], $repo);
                    $took[] = (hrtime(true) - $start) / 1e6;
                    $ranks[] = TestSet::rank($q, $hits);
                    $pageRanks[] = TestSet::pageRank($q, $hits, $pages);
                }
                sort($took);
                $row = ['median_ms' => round($took[(int) (\count($took) / 2)], 1), 'ranks' => $ranks, 'page_ranks' => $pageRanks];
                foreach (['para', 'ident'] as $class) {
                    $of = array_filter($questions, static fn ($q) => $q['class'] === $class);
                    $row[$class] = TestSet::recall(array_values(array_intersect_key($ranks, $of)));
                    // the chunk holds the answer (above), or only its page does: one click away through the hit's link
                    $row["$class-page"] = TestSet::recall(array_values(array_intersect_key($pageRanks, $of)));
                    $io->writeln(\sprintf('%-8s %-13s %-11s %s', $set, $mode, $class, json_encode($row[$class])));
                    $io->writeln(\sprintf('%-8s %-13s %-11s %s', $set, $mode, "$class-page", json_encode($row["$class-page"])));
                }
                $results[$set][$mode] = $row;
                @mkdir(\dirname($path), 0o777, true);
                file_put_contents($path, json_encode($results, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
            }
        }

        return 0;
    }

    private function run(string $mode, string $query, string $repo): array
    {
        return match ($mode) {
            'ours' => $this->retriever->search($query, $repo, 50)['hits'],
            'rerank' => $this->reranked($query, $repo),
            'keyword' => $this->retriever->keyword($query, 50, $repo),
            'vector' => $this->retriever->vector($this->vector($query), 50, $repo),
            'store-text' => $this->documents(new TextQuery($query), $repo),
            'store-hybrid' => $this->documents(new HybridQuery(new Vector($this->vector($query)), $query), $repo),
            default => throw new \InvalidArgumentException("Unknown mode $mode"),
        };
    }

    /** A reorder that failed or found another one running returns vector order; scoring that as "rerank" would be a lie. */
    private function reranked(string $query, string $repo): array
    {
        $found = $this->retriever->search($query, $repo, 50, true);

        return 'done' === $found['rerank'] ? $found['hits'] : throw new \RuntimeException("Reorder was {$found['rerank']}, not done, for \"$query\"; nothing saved for this mode.");
    }

    private function vector(string $query): array
    {
        return $this->embedder->query($query)['vector'] ?? throw new \RuntimeException('The embedding host is unreachable and this question has no saved vector.');
    }

    /**
     * Symfony AI's own text and hybrid queries, measured to justify not using them.
     * ponytail: the Postgres store applies `where` to vector queries only, so these take a deep pool and keep the repo's
     * hits; exact unless other repos fill 450 of the top 500.
     */
    private function documents(object $query, string $repo): array
    {
        $hits = [];
        /** @var VectorDocumentInterface $d */
        foreach ($this->store->query($query, ['limit' => 500]) as $d) {
            if ($repo !== $d->getMetadata()['repo'] || 50 === \count($hits)) {
                continue;
            }
            $hits[] = ['file' => $d->getMetadata()['file'], 'path' => $d->getMetadata()['path'], 'source' => $d->getMetadata()['source']];
        }

        return $hits;
    }
}
