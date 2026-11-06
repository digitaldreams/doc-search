<?php

namespace App\Tests\Docs;

use App\Docs\TestSet;
use PHPUnit\Framework\TestCase;

final class TestSetTest extends TestCase
{
    private static function question(string $fileRegex, string $needle): array
    {
        return ['class' => 'para', 'file' => $fileRegex, 'needle' => $needle, 'query' => 'q'];
    }

    private static function hit(string $file, string $source, array $path = ['Top']): array
    {
        return ['id' => 'x', 'file' => $file, 'path' => $path, 'text' => '', 'source' => $source];
    }

    public function testAHitNeedsTheRightPageAndTheAnswerText(): void
    {
        $q = self::question('security\.rst', 'answer');

        self::assertSame(2, TestSet::rank($q, [self::hit('other.rst', 'answer'), self::hit('security.rst', 'the answer')]));
        self::assertNull(TestSet::rank($q, [self::hit('security.rst', 'nothing')]));
    }

    public function testFileRegexMustMatchTheWholePath(): void
    {
        // Anchored at the end only, `lock\.rst` accepted components/clock.rst and `security\.rst` the config reference.
        self::assertNull(TestSet::rank(self::question('lock\.rst', 'a'), [self::hit('components/clock.rst', 'a')]));
        self::assertNull(TestSet::rank(self::question('security\.rst', 'a'), [self::hit('reference/configuration/security.rst', 'a')]));
        self::assertNull(TestSet::rank(self::question('cache\.rst', 'a'), [self::hit('cache.rst.bak', 'a')]));
        self::assertSame(1, TestSet::rank(self::question('reference/constraints/.*\.rst', 'a'), [self::hit('reference/constraints/Time.rst', 'a')]));
    }

    public function testAPageHitIsAnyChunkOfAnAcceptedPageThatHoldsTheAnswer(): void
    {
        $q = self::question('reference/constraints/.*\.rst', '`withSeconds`');
        $pages = ['reference/constraints/Time.rst' => "Time\nwithSeconds\ntype: boolean", 'reference/constraints/Date.rst' => 'Date'];
        $hits = [self::hit('reference/constraints/Date.rst', 'Date'), self::hit('reference/constraints/Time.rst', 'Time title chunk')];

        self::assertNull(TestSet::rank($q, $hits));          // the chunk shown lacks the answer
        self::assertSame(2, TestSet::pageRank($q, $hits, $pages)); // but its page has it, one click away
        self::assertNull(TestSet::pageRank(self::question('lock\.rst', 'withSeconds'), [self::hit('components/clock.rst', 'x')], ['components/clock.rst' => 'withSeconds']));
    }

    public function testMarkupAndWhitespaceDoNotAffectMatching(): void
    {
        $q = self::question('a\.rst', '`two   words`');

        self::assertSame(1, TestSet::rank($q, [self::hit('a.rst', "two\n words")]));
    }

    public function testTheBreadcrumbCountsAsChunkText(): void
    {
        self::assertSame(1, TestSet::rank(self::question('a\.rst', 'Top Sub'), [self::hit('a.rst', 'body', ['Top', 'Sub'])]));
    }

    public function testOnlyTheTop50Count(): void
    {
        $hits = array_fill(0, 50, self::hit('a.rst', 'no'));
        $hits[] = self::hit('a.rst', 'yes');

        self::assertNull(TestSet::rank(self::question('a\.rst', 'yes'), $hits));
    }

    public function testScoresRecallAtEachCutoff(): void
    {
        $scores = TestSet::recall([1, 3, 12, null]);

        self::assertSame(['n' => 4, 'r@1' => 0.25, 'r@3' => 0.5, 'r@5' => 0.5, 'r@10' => 0.5, 'r@20' => 0.75, 'r@50' => 0.75], $scores);
    }

    public function testLoadsTheShippedQuestionSets(): void
    {
        $fresh = TestSet::load(__DIR__.'/../../data/queries/fresh.tsv');

        self::assertCount(77, $fresh);
        self::assertSame(['class', 'file', 'needle', 'query'], array_keys($fresh[0]));
    }
}
