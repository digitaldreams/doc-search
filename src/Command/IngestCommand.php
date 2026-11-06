<?php

namespace App\Command;

use App\Docs\ChunkIndex;
use App\Docs\Chunker;
use App\Docs\Repos;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:docs:ingest', 'Chunk one docs repo and index it; only chunks not yet indexed cost any work')]
final class IngestCommand
{
    public function __construct(
        private readonly Chunker $chunker,
        private readonly ChunkIndex $index,
        private readonly Repos $repos,
        #[Autowire('%kernel.project_dir%')] private readonly string $root,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('A key of app.docs_repos, e.g. laravel/docs; DOCS_REPO when left out')] ?string $repo = null,
        #[Option('Then write the repo\'s vectors to data/seed_vectors/<repo>.bin, so a new install skips embedding it')] bool $exportSeeds = false,
    ): int {
        try {
            $key = $this->repos->key($repo);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $config = $this->repos->get($key);

        $start = microtime(true);
        $chunks = $this->chunker->chunkTree($config['path'], $config['extension'], $config['exclude'] ?? [], $config['label']);
        $io->writeln(\sprintf('%d chunks from %s in %.1fs', \count($chunks), $key, microtime(true) - $start));

        $start = microtime(true);
        $bar = $io->createProgressBar();
        $seedFile = "$this->root/data/seed_vectors/$key.bin";
        $stats = $this->index->sync($key, $chunks, $seedFile, $bar->advance(...));
        $bar->finish();
        $io->newLine();
        $io->success(\sprintf('%d from shipped vectors, %d embedded, %d unchanged, %d removed, in %.1fs.', $stats['seeded'], $stats['embedded'], $stats['kept'], $stats['removed'], microtime(true) - $start));

        if ($exportSeeds) {
            try {
                $io->success(\sprintf('%d vectors written to %s.', $this->index->exportSeeds($key, $seedFile), $seedFile));
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
