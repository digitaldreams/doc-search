<?php

namespace App\Tests\Docs;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class IngestCommandTest extends SearchTestCase
{
    public function testARepoIsReadWithTheLoaderOfItsExtension(): void
    {
        // test env: laravel/docs is tests/fixtures, extension md
        $tester = new CommandTester((new Application(self::$kernel))->find('app:docs:ingest'));

        self::assertSame(0, $tester->execute(['repo' => 'laravel/docs']), $tester->getDisplay());
        $rows = $this->db->fetchAllKeyValue("SELECT metadata->>'anchor', metadata->>'file' FROM chunks WHERE metadata->>'repo' = 'laravel/docs' ORDER BY 1");
        self::assertSame(['connections' => 'queues.md'], $rows);
    }
}
