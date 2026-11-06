<?php

namespace App\Tests\Docs;

use App\Command\EvalCommand;
use App\Docs\Reranker;
use App\Docs\Retriever;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class EvalCommandTest extends SearchTestCase
{
    public function testARerankRunThatWasNotReorderedIsRefusedInsteadOfSaved(): void
    {
        // a reorder started from the page holds the lock, so every eval question would come back 'busy', in vector order
        $root = sys_get_temp_dir().'/eval-'.bin2hex(random_bytes(4));
        mkdir("$root/data/queries", 0o777, true);
        file_put_contents("$root/data/queries/fresh.tsv", "class\tfile_regex\tneedle\tquery\npara\tpage\\.rst\tanswer\ta plain question with many words\n");
        $this->index()->store([self::chunk('a', 'answer')], [self::axis(1)]);
        $locks = new LockFactory(new InMemoryStore());
        $reranker = new Reranker(MockPlatformFactory::createPlatform(static fn () => new \Symfony\AI\Platform\Result\ObjectResult(['best' => [1]])), 'gemma4:latest');
        $retriever = new Retriever($this->embedder(self::axis(1)), $this->store, $this->db, $reranker, $locks);
        $command = new EvalCommand($retriever, $this->embedder(self::axis(1)), $this->store, $this->db, $root);
        $running = $locks->createLock('rerank'); // kept in a variable: a dropped Lock releases itself
        $running->acquire();

        $refused = null;
        try {
            $command(new SymfonyStyle(new ArrayInput([]), new NullOutput()), 'rerank', 'fresh');
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }
        self::assertFileDoesNotExist("$root/data/eval_results.json");
        self::assertStringContainsString('busy', (string) $refused);
    }
}
