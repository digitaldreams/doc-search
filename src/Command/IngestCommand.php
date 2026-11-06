<?php

namespace App\Command;

use App\Docs\ChunkIndex;
use App\Docs\Chunker;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:docs:ingest', 'Chunk the docs and index them; only chunks not yet indexed cost any work')]
final class IngestCommand
{
    public function __construct(
        private readonly Chunker $chunker,
        private readonly ChunkIndex $index,
        #[Autowire('%kernel.project_dir%')] private readonly string $root,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folder of .rst files')] ?string $docs = null,
        #[Option('Vectors shipped with the app, used before asking the model')] ?string $seed = null,
    ): int {
        $start = microtime(true);
        $chunks = $this->chunker->chunkTree($docs ?? $this->root.'/data/docs');
        $io->writeln(\sprintf('%d chunks from the docs in %.1fs', \count($chunks), microtime(true) - $start));

        $start = microtime(true);
        $bar = $io->createProgressBar();
        $stats = $this->index->sync($chunks, $seed ?? $this->root.'/data/seed_vectors.bin', $bar->advance(...));
        $bar->finish();
        $io->newLine();
        $io->success(\sprintf('%d from shipped vectors, %d embedded, %d unchanged, %d removed, in %.1fs.', $stats['seeded'], $stats['embedded'], $stats['kept'], $stats['removed'], microtime(true) - $start));

        return 0;
    }
}
