<?php

namespace App\Tests\Docs;

use App\Docs\Chunker;
use PHPUnit\Framework\TestCase;

final class ChunkerTest extends TestCase
{
    private const DOCS = __DIR__.'/../../data/docs';

    private const CONFIG = <<<'RST'
        Top
        ===

        .. configuration-block::

            .. code-block:: yaml

                # yaml sample
                a: 1

            .. code-block:: xml

                <!-- xml sample -->

            .. code-block:: php

                // php sample
        RST;

    /** @param array<string, string> $files */
    private function chunks(string $rst, array $files = [], int $maxTokens = 512): array
    {
        $root = sys_get_temp_dir().'/chunker'.bin2hex(random_bytes(6));
        foreach (['page.rst' => $rst] + $files as $name => $body) {
            @mkdir(\dirname("$root/$name"), 0777, true);
            file_put_contents("$root/$name", $body);
        }
        try {
            return (new Chunker())->chunkFile('page.rst', $root, $maxTokens);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    public function testEveryChunkMatchesThePythonChunkerThatProducedTheMeasuredVectors(): void
    {
        $expected = [];
        foreach (file(__DIR__.'/../fixtures/python_chunks.tsv', \FILE_IGNORE_NEW_LINES) as $line) {
            $expected[] = $line;
        }
        $actual = [];
        foreach ((new Chunker())->chunkTree(self::DOCS) as $c) {
            $actual[] = implode("\t", [$c['id'], $c['file'], sha1($c['source'])]);
        }

        self::assertCount(6370, $actual);
        self::assertSame($expected, $actual);
    }

    public function testHeadingHierarchyComesFromFirstSeenAdornmentOrder(): void
    {
        $cs = $this->chunks("Top\n===\n\nintro text\n\nMid\n---\n\nmid text\n\nLeaf\n~~~~\n\nleaf text\n\nMid2\n----\n\nmid2 text\n");
        self::assertSame([['Top'], ['Top', 'Mid'], ['Top', 'Mid', 'Leaf'], ['Top', 'Mid2']], array_column($cs, 'path'));
    }

    public function testOverlinedTitleIsAHeading(): void
    {
        $c = $this->chunks("=====\nTitle\n=====\n\nbody\n")[0];
        self::assertSame(['Title'], $c['path']);
        self::assertSame('body', $c['text']);
    }

    public function testUnderlineInsideIndentedCodeIsNotAHeading(): void
    {
        $cs = $this->chunks("Top\n===\n\n.. code-block:: text\n\n    Fake\n    ----\n    more\n");
        self::assertCount(1, $cs);
        self::assertSame(['Top'], $cs[0]['path']);
        self::assertStringContainsString('Fake', $cs[0]['text']);
    }

    public function testSimpleTableRuleIsDropped(): void
    {
        $cs = $this->chunks("Top\n===\n\n==========  ======\nApplies to  class\n==========  ======\n");
        self::assertCount(1, $cs);
        self::assertSame('Applies to  class', $cs[0]['text']);
    }

    public function testIncludeIsExpandedUnderTheIncludingPage(): void
    {
        $cs = $this->chunks(
            "Length\n======\n\nOptions\n-------\n\n.. include:: /inc/_payload.rst.inc\n",
            ['inc/_payload.rst.inc' => "``payload``\n~~~~~~~~~~~\n\n**type**: ``mixed``\n"],
        );
        $c = array_values(array_filter($cs, static fn ($c) => ['Length', 'Options', 'payload'] === $c['path']))[0];
        self::assertSame('type: mixed', $c['text']);
        self::assertSame('page.rst', $c['file']);
    }

    public function testIncludeCycleDoesNotHang(): void
    {
        $cs = $this->chunks("Top\n===\n\nbefore\n\n.. include:: /page.rst\n");
        self::assertStringContainsString('before', $cs[0]['text']);
    }

    public function testRolesLinksAndInlineMarkupAreFlattened(): void
    {
        $cs = $this->chunks(<<<'RST'
            Top
            ===

            See :class:`Symfony\\Component\\Foo\\Bar` and :method:`Symfony\\Foo::baz`
            with :ref:`the guide <some-label>`, :doc:`other page </x/y>`, :doc:`/x/z`,
            `Doctrine`_, `site <https://e.org>`_, ``literal`` and **bold** and *em*.
            RST);
        self::assertSame(
            "See Symfony\\Component\\Foo\\Bar and Symfony\\Foo::baz\nwith the guide, other page, /x/z,\nDoctrine, site, literal and bold and em.",
            $cs[0]['text'],
        );
    }

    public function testLabelsTargetsCommentsAndMediaAreRemoved(): void
    {
        $cs = $this->chunks(<<<'RST'
            .. _top-label:

            Top
            ===

            kept

            .. toctree::
                :maxdepth: 1

                a/b

            .. image:: /x.png
                :alt: nope

            .. raw:: html

                <b>nope</b>

            .. a plain comment

            .. rst-class:: ui-list-three-columns

            .. _`Doctrine`: https://doctrine.org
            .. _other-label:

            Sub
            ---

            sub body
            RST);
        self::assertSame(['kept', 'sub body'], array_column($cs, 'text'));
    }

    public function testSectionWithNoBodyEmitsNoChunk(): void
    {
        $cs = $this->chunks("Top\n===\n\n.. toctree::\n\n    a\n\nSub\n---\n\nbody\n");
        self::assertSame([['Top', 'Sub']], array_column($cs, 'path'));
    }

    public function testAdmonitionsAndVersionMarkersKeepTheirMeaning(): void
    {
        $cs = $this->chunks("Top\n===\n\n.. versionadded:: 8.1\n\n    The foo option was introduced in Symfony 8.1.\n\n.. note::\n\n    Mind the gap.\n\n.. deprecated:: 8.0\n\n    Bar is deprecated.\n");
        self::assertSame("New in Symfony 8.1:\nThe foo option was introduced in Symfony 8.1.\n\nNote:\nMind the gap.\n\nDeprecated in Symfony 8.0:\nBar is deprecated.", $cs[0]['text']);
    }

    public function testConfigBlockEmbedsOneFormatButSourceKeepsAll(): void
    {
        $c = $this->chunks(self::CONFIG)[0];
        self::assertStringContainsString('yaml sample', $c['text']);
        self::assertStringNotContainsString('php sample', $c['text']);
        foreach (['yaml sample', 'xml sample', 'php sample'] as $needle) {
            self::assertStringContainsString($needle, $c['source']);
        }
    }

    public function testXmlIsNeverTheEmbeddedFormatEvenWhenItComesFirst(): void
    {
        $c = $this->chunks("Top\n===\n\n.. configuration-block::\n\n    .. code-block:: xml\n\n        <!-- xml sample -->\n\n    .. code-block:: yaml\n\n        # yaml sample\n")[0];
        self::assertStringContainsString('yaml sample', $c['text']);
        self::assertStringNotContainsString('xml sample', $c['text']);
        self::assertStringContainsString('xml sample', $c['source']);
    }

    public function testCodeBlockIsDedentedAndOptionsRemoved(): void
    {
        $cs = $this->chunks("Top\n===\n\n.. code-block:: php\n    :emphasize-lines: 1\n\n    echo 1;\n\n        echo 2;\n");
        self::assertSame("echo 1;\n\n    echo 2;", $cs[0]['text']);
    }

    public function testLeadInParagraphStaysWithItsCode(): void
    {
        $filler = implode("\n\n", array_fill(0, 3, str_repeat('word ', 60)));
        $cs = $this->chunks("Top\n===\n\n$filler\n\nDo this:\n\n.. code-block:: php\n\n    run();\n", maxTokens: 250);
        $together = array_filter($cs, static fn ($c) => str_contains($c['text'], 'Do this:') && str_contains($c['text'], 'run();'));
        self::assertNotEmpty($together);
    }

    public function testPackingRespectsBudgetAndKeepsBreadcrumb(): void
    {
        $paras = [];
        for ($i = 0; $i < 9; ++$i) {
            $paras[] = "para$i ".str_repeat('word ', 80);
        }
        $cs = $this->chunks("Top\n===\n\n".implode("\n\n", $paras), maxTokens: 200);
        self::assertGreaterThan(1, \count($cs));
        foreach ($cs as $c) {
            self::assertLessThanOrEqual(800, mb_strlen($c['text']));
            self::assertSame(['Top'], $c['path']);
        }
    }

    public function testOversizeCodeSplitsOnBlankLinesWithoutLosingLines(): void
    {
        $body = [];
        for ($i = 0; $i < 200; ++$i) {
            $body[] = "    fn$i();\n    more$i();";
        }
        $cs = $this->chunks("Top\n===\n\n.. code-block:: php\n\n".implode("\n\n", $body)."\n", maxTokens: 100);
        $joined = implode("\n", array_column($cs, 'text'));
        for ($i = 0; $i < 200; ++$i) {
            self::assertStringContainsString("fn$i();", $joined);
            self::assertStringContainsString("more$i();", $joined);
        }
        foreach ($cs as $c) {
            self::assertLessThanOrEqual(400, mb_strlen($c['text']));
            self::assertStringStartsNotWith('more', $c['text']);
        }
    }

    public function testSingleHugeLineIsHardSplitUnderBudget(): void
    {
        $cs = $this->chunks("Top\n===\n\n".str_repeat('x', 5000)."\n", maxTokens: 100);
        self::assertSame(5000, array_sum(array_map(static fn ($c) => mb_strlen($c['text']), $cs)));
    }

    public function testASectionWhoseBodyIsTheStringZeroIsNotLost(): void
    {
        self::assertSame('0', $this->chunks("Top\n===\n\n0\n")[0]['text']);
    }

    public function testEmbedFormatsMatchTheModelCard(): void
    {
        self::assertSame('title: Messenger > Supervisor | text: body', Chunker::docInput(['path' => ['Messenger', 'Supervisor'], 'text' => 'body']));
        self::assertSame('title: none | text: body', Chunker::docInput(['path' => [], 'text' => 'body']));
        self::assertSame('task: search result | query: how?', Chunker::queryInput('how?'));
    }

    public function testRoleOnFirstLineOfAdmonitionIsProseNotAnOption(): void
    {
        $cs = $this->chunks("Top\n===\n\n.. warning::\n\n    :doc:`Form data transformers </form/dt>` are applied first.\n\n.. admonition:: Screencast\n    :class: screencast\n\n    Real body.\n");
        self::assertStringContainsString('Form data transformers are applied first.', $cs[0]['text']);
        self::assertStringContainsString('Real body.', $cs[0]['text']);
        self::assertStringNotContainsString(':class:', $cs[0]['text']);
    }

    public function testProseStartingWithEllipsisIsNotAComment(): void
    {
        $cs = $this->chunks("Top\n===\n\n... but you certainly won't want that.\n\n.. a real comment\n");
        self::assertSame("... but you certainly won't want that.", $cs[0]['text']);
    }

    public function testInlineMarkupWrappedAcrossLinesIsFlattened(): void
    {
        $cs = $this->chunks("Top\n===\n\nLoads files using the :doc:`Config component\n</components/config>`, which is **best for\nSymfony** apps.\n");
        self::assertSame("Loads files using the Config component, which is best for\nSymfony apps.", $cs[0]['text']);
    }

    public function testClassDirectiveArgumentDoesNotLeak(): void
    {
        self::assertSame('* Blank', $this->chunks("Top\n===\n\n.. class:: ui-list-two-columns\n\n* Blank\n")[0]['text']);
    }

    public function testKeywordSearchJoinsOnlyForCodeShapedOrShortQueries(): void
    {
        foreach (['messenger:show', '#[MapRequestPayload]', 'Assert\\Length minMessage', 'max_host_connections',
            'IsNull constraint', 'emojify filter', 'MESSENGER_TRANSPORT_DSN', 'symfony/expression-language',
            'mandrill+api', 'config:dump-reference twig', 'GreaterThanOrEqual value option array'] as $q) {
            self::assertTrue(Chunker::wantsKeywords($q), $q);
        }
        foreach (['shrink my JS and CSS files', 'browser tests fail because the local HTTPS certificate is not trusted',
            'write a multi-line string in a config file that preserves newlines',
            'reject addresses like https://foobar that have no .com ending',
            'how can I remember a value for a visitor between two page loads'] as $q) {
            self::assertFalse(Chunker::wantsKeywords($q), $q);
        }
    }
}
