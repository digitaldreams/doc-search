<?php

namespace App\Docs;

use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Finder\Finder;

/**
 * A Markdown docs tree (Laravel) as Symfony AI documents, one per chunk.
 *
 * ponytail: the walk repeats RstLoader's three lines; a DocBook loader will walk an include graph instead, so no base class.
 */
#[AutoconfigureTag('app.docs_loader')]
#[AsTaggedItem('md')] // a repo's `extension` in app.docs_repos
final class MarkdownLoader implements LoaderInterface
{
    public function __construct(
        private readonly MarkdownParser $parser = new MarkdownParser(),
        private readonly Chunker $chunker = new Chunker(),
    ) {
    }

    /**
     * Options: `exclude`, Finder path patterns (substring, glob or regex); `max_tokens`, the chunk budget.
     *
     * @param string|null                                     $source  the docs root
     * @param array{exclude?: list<string>, max_tokens?: int} $options
     *
     * @return iterable<TextDocument>
     */
    public function load(?string $source = null, array $options = []): iterable
    {
        null !== $source || throw new InvalidArgumentException('MarkdownLoader needs the docs root as source.');
        // dot files and dirs are skipped by Finder's default
        foreach (Finder::create()->files()->in($source)->name('*.md')->notPath($options['exclude'] ?? [])->sortByName() as $file) {
            $rel = $file->getRelativePathname();
            foreach ($this->chunker->chunks($rel, $this->parser->sections($rel, $source), $options['max_tokens'] ?? 512) as $chunk) {
                yield Chunker::document($chunk);
            }
        }
    }
}
