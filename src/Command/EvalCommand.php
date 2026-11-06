<?php

namespace App\Command;

use App\Docs\Embedder;
use App\Docs\Retriever;
use App\Docs\TestSet;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\AI\Store\Query\TextQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:docs:eval', 'Measure recall on the question sets and save it to data/eval_results.json')]
final class EvalCommand
{
    private const SETS = ['fresh', 'holdout', 'tuning'];
    private const MODES = ['ours', 'vector', 'keyword', 'store-hybrid', 'store-text'];

    public function __construct(
        private readonly Retriever $retriever,
        private readonly Embedder $embedder,
        private readonly StoreInterface $store,
        #[Autowire('%kernel.project_dir%')] private readonly string $root,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Comma-separated: ours, vector, keyword, store-hybrid, store-text, rerank')] ?string $modes = null,
        #[Option('Comma-separated: fresh, holdout, tuning')] ?string $sets = null,
    ): int {
        $path = $this->root.'/data/eval_results.json';
        $results = is_file($path) ? json_decode(file_get_contents($path), true, 32, \JSON_THROW_ON_ERROR) : [];

        foreach ($sets ? explode(',', $sets) : self::SETS as $set) {
            $questions = TestSet::load("$this->root/data/queries/$set.tsv");
            foreach ($modes ? explode(',', $modes) : self::MODES as $mode) {
                $ranks = $took = [];
                foreach ($questions as $q) {
                    $start = hrtime(true);
                    $ranks[] = TestSet::rank($q, $this->run($mode, $q['query']));
                    $took[] = (hrtime(true) - $start) / 1e6;
                }
                sort($took);
                $row = ['median_ms' => round($took[(int) (\count($took) / 2)], 1), 'ranks' => $ranks];
                foreach (['para', 'ident'] as $class) {
                    $row[$class] = TestSet::recall(array_values(array_intersect_key($ranks, array_filter($questions, static fn ($q) => $q['class'] === $class))));
                    $io->writeln(\sprintf('%-8s %-13s %-6s %s', $set, $mode, $class, json_encode($row[$class])));
                }
                $results[$set][$mode] = $row;
                file_put_contents($path, json_encode($results, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
            }
        }

        return 0;
    }

    private function run(string $mode, string $query): array
    {
        return match ($mode) {
            'ours' => $this->retriever->search($query, 50)['hits'],
            'rerank' => $this->retriever->search($query, 50, true)['hits'],
            'keyword' => $this->retriever->keyword($query, 50),
            'vector' => $this->retriever->vector($this->vector($query), 50),
            'store-text' => $this->documents(new TextQuery($query)),
            'store-hybrid' => $this->documents(new HybridQuery(new Vector($this->vector($query)), $query)),
            default => throw new \InvalidArgumentException("Unknown mode $mode"),
        };
    }

    private function vector(string $query): array
    {
        return $this->embedder->query($query)['vector'] ?? throw new \RuntimeException('The embedding host is unreachable and this question has no saved vector.');
    }

    /** Symfony AI's own text and hybrid queries, measured to justify not using them. */
    private function documents(object $query): array
    {
        $hits = [];
        /** @var VectorDocumentInterface $d */
        foreach ($this->store->query($query, ['limit' => 50]) as $d) {
            $hits[] = ['file' => $d->getMetadata()['file'], 'path' => $d->getMetadata()['path'], 'source' => $d->getMetadata()['source']];
        }

        return $hits;
    }
}
