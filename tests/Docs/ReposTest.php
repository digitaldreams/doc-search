<?php

namespace App\Tests\Docs;

use App\Docs\Repos;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ReposTest extends KernelTestCase
{
    private const CONFIG = [
        'symfony/symfony-docs' => ['label' => 'Symfony', 'path' => '/docs', 'extension' => 'rst', 'url' => 'https://symfony.com/doc/current/%s.html'],
        'laravel/docs' => ['label' => 'Laravel', 'path' => '/md', 'extension' => 'md', 'url' => 'https://laravel.com/docs/13.x/%s'],
    ];

    public function testNoKeyMeansTheDefaultRepo(): void
    {
        $repos = new Repos(self::CONFIG, 'laravel/docs');

        self::assertSame('laravel/docs', $repos->key(null));
        self::assertSame('laravel/docs', $repos->key('laravel/docs'));
        self::assertSame(['symfony/symfony-docs' => 'Symfony', 'laravel/docs' => 'Laravel'], $repos->labels());
    }

    public function testAnUnknownKeyNamesTheKnownOnes(): void
    {
        $this->expectExceptionMessage('Unknown docs repo "nope/nope". Known: symfony/symfony-docs, laravel/docs.');
        (new Repos(self::CONFIG, 'symfony/symfony-docs'))->key('nope/nope');
    }

    public function testADefaultThatIsNotConfiguredFailsLoudly(): void
    {
        // a typo in DOCS_REPO must not quietly search an empty repo
        $this->expectExceptionMessage('Unknown docs repo "symfony/docs"');
        (new Repos(self::CONFIG, 'symfony/docs'))->key(null);
    }

    public function testTheShippedConfigDefaultsToSymfony(): void
    {
        $repos = self::getContainer()->get(Repos::class);

        self::assertSame('symfony/symfony-docs', $repos->key(null));
        self::assertSame('rst', $repos->get(null)['extension']);
    }

    public function testIngestRefusesAnUnknownRepo(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:docs:ingest'));

        self::assertSame(1, $tester->execute(['repo' => 'nope/nope']));
        self::assertStringContainsString('Known: symfony/symfony-docs', $tester->getDisplay());
    }
}
