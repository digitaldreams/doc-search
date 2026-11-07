<?php

namespace App\Docs;

use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Finder\Finder;

/**
 * A reStructuredText docs tree (Symfony, Twig, Doctrine) as Symfony AI documents, one per chunk.
 */
#[AutoconfigureTag('app.docs_loader')]
#[AsTaggedItem('rst')] // a repo's `extension` in app.docs_repos
final class RstLoader implements LoaderInterface
{
    public function __construct(
        private readonly RstParser $parser = new RstParser(),
        private readonly Chunker $chunker = new Chunker(),
    ) {
    }

    /**
     * Options: `exclude`, Finder path patterns (substring, glob or regex); `product`, the name version markers
     * use ("New in Twig 3.6"); `max_tokens`, the chunk budget.
     *
     * @param string|null                                                       $source  the docs root
     * @param array{exclude?: list<string>, product?: string, max_tokens?: int} $options
     *
     * @return iterable<TextDocument>
     */
    public function load(?string $source = null, array $options = []): iterable
    {
        null !== $source || throw new InvalidArgumentException('RstLoader needs the docs root as source.');
        // dot files and dirs are skipped by Finder's default
        foreach (Finder::create()->files()->in($source)->name('*.rst')->notPath($options['exclude'] ?? [])->sortByName() as $file) {
            $rel = $file->getRelativePathname();
            $sections = $this->parser->sections($rel, $source, $options['product'] ?? 'Symfony');
            foreach ($this->chunker->chunks($rel, $sections, $options['max_tokens'] ?? 512) as $chunk) {
                yield Chunker::document($chunk);
            }
        }
    }
}
