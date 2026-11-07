<?php

namespace App\Command;

use App\Docs\ChunkIndex;
use App\Docs\Repos;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[AsCommand('app:docs:ingest', 'Chunk one docs repo and index it; only chunks not yet indexed cost any work')]
final class IngestCommand
{
    /**
     * @param ServiceLocator<LoaderInterface> $loaders one per docs format, keyed by the repo's `extension` (#[AsTaggedItem] on each loader)
     */
    public function __construct(
        #[AutowireLocator('app.docs_loader')] private readonly ServiceLocator $loaders,
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
        $loader = $this->loaders->get($config['extension']);
        $documents = [...$loader->load($config['path'], ['exclude' => $config['exclude'] ?? [], 'product' => $config['label']])];
        $io->writeln(\sprintf('%d chunks from %s in %.1fs', \count($documents), $key, microtime(true) - $start));

        $start = microtime(true);
        $bar = $io->createProgressBar();
        $seedFile = "$this->root/data/seed_vectors/$key.bin";
        $stats = $this->index->sync($key, $documents, $seedFile, $bar->advance(...));
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
