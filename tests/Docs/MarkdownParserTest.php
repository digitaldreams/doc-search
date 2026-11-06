<?php

namespace App\Tests\Docs;

use App\Docs\Chunker;
use PHPUnit\Framework\TestCase;

/** Markdown as Laravel's docs write it (github.com/laravel/docs), read through the chunker like ingest does. */
final class MarkdownParserTest extends TestCase
{
    private function chunks(string $md): array
    {
        $root = sys_get_temp_dir().'/md'.bin2hex(random_bytes(6));
        mkdir($root);
        file_put_contents("$root/page.md", $md);
        try {
            return (new Chunker())->chunkFile('page.md', $root);
        } finally {
            exec('rm -rf '.escapeshellarg($root));
        }
    }

    public function testHeadingLevelsBuildTheBreadcrumb(): void
    {
        $cs = $this->chunks("# Top\n\nintro\n\n## Mid\n\nmid\n\n### Leaf\n\nleaf\n\n## Mid2\n\nmid2\n");
        self::assertSame([['Top'], ['Top', 'Mid'], ['Top', 'Mid', 'Leaf'], ['Top', 'Mid2']], array_column($cs, 'path'));
        self::assertSame(['intro', 'mid', 'leaf', 'mid2'], array_column($cs, 'text'));
    }

    public function testHashInsideAFenceIsCodeNotAHeading(): void
    {
        $cs = $this->chunks("# Top\n\n```shell\n# not a heading\nls\n```\n");
        self::assertCount(1, $cs);
        self::assertSame(['Top'], $cs[0]['path']);
        self::assertSame("# not a heading\nls", $cs[0]['text']);
    }

    public function testTheAnchorTagBeforeAHeadingIsTheSectionsLinkAndLeavesNoText(): void
    {
        // laravel.com links #parameters-optional-parameters, which no slug of "Optional Parameters" would give
        $cs = $this->chunks("# Routing\n\n<a name=\"parameters-optional-parameters\"></a>\n## Optional Parameters\n\nbody\n\n## No Anchor\n\nmore\n");
        self::assertSame([['Routing', 'Optional Parameters'], ['Routing', 'No Anchor']], array_column($cs, 'path'));
        self::assertSame('body', $cs[0]['text']);
        self::assertSame('parameters-optional-parameters', $cs[0]['anchor']);
        self::assertNull($cs[1]['anchor']);
    }

    public function testHeadingAttributesAreDropped(): void
    {
        $cs = $this->chunks("#### `append(\$attributes)` {.collection-method .first-collection-method}\n\nbody\n");
        self::assertSame(['append($attributes)'], $cs[0]['path']);
    }

    public function testAListOfOnlyLinksIsNavigationAndDropped(): void
    {
        // every page opens with its table of contents: kept, it would match every question about the page
        $cs = $this->chunks("# Routing\n\n- [Basic Routing](#basic-routing)\n    - [Redirect Routes](#redirect-routes)\n- [Views](/docs/{{version}}/views)\n\n## Basic Routing\n\n- `connected` - Successfully connected.\n- plain item\n    - nested item\n");
        self::assertSame([['Routing', 'Basic Routing']], array_column($cs, 'path'));
        self::assertSame("- connected - Successfully connected.\n- plain item\n  - nested item", $cs[0]['text']);
    }

    public function testGithubAdmonitionBecomesALabel(): void
    {
        $cs = $this->chunks("# Top\n\n> [!WARNING]\n> Do not do this.\n\n> A plain quote.\n");
        self::assertSame("Warning:\nDo not do this.\n\nA plain quote.", $cs[0]['text']);
    }

    public function testInlineMarkupIsFlattened(): void
    {
        $cs = $this->chunks("# Top\n\nUse the [service container](/docs/{{version}}/container), **bold**, _em_ and `Route::get`.\n\n![diagram](img.png)\n\nA <kbd>key</kbd> press.\n");
        self::assertSame("Use the service container, bold, em and Route::get.\n\nA key press.", $cs[0]['text']);
    }

    public function testTabbedFencesEmbedTheFirstTabAndShowEvery(): void
    {
        $cs = $this->chunks("# Top\n\nTest it:\n\n```php tab=Pest\npest code\n```\n\n```php tab=PHPUnit\nphpunit code\n```\n\nAfter.\n");
        self::assertSame("Test it:\n\npest code\n\nAfter.", $cs[0]['text']);
        self::assertSame("Test it:\n\npest code\n\nphpunit code\n\nAfter.", $cs[0]['source']);
    }

    public function testHtmlBlocksAreDroppedButMarkdownInsideADivStays(): void
    {
        $cs = $this->chunks("# Top\n\n<style>\n.x { color: red }\n</style>\n\n<div class=\"content-list\" markdown=\"1\">\n\n- `a` - first\n\n</div>\n");
        self::assertSame('- a - first', $cs[0]['text']);
    }

    public function testTableRowsBecomeLinesOfCells(): void
    {
        $cs = $this->chunks("# Top\n\n| Name | Command |\n| --- | --- |\n| `server` | `php artisan serve` |\n");
        self::assertSame("Name | Command\nserver | php artisan serve", $cs[0]['text']);
    }

    public function testASectionWithOnlyAnAnchorAndNavigationEmitsNoChunk(): void
    {
        self::assertSame([], $this->chunks("# Title\n\n- [A](#a)\n\n<a name=\"a\"></a>\n"));
    }
}
