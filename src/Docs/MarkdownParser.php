<?php

namespace App\Docs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\AbstractStringContainer;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser as CommonMark;

/**
 * Reads Markdown docs into sections of rendered blocks, written against Laravel's docs (github.com/laravel/docs).
 *
 * Headings sit at the top of CommonMark's tree, next to the blocks they head, so a section is everything between
 * two top-level headings. Tabbed fences (```php tab=Pest, then tab=PHPUnit) are like RST's configuration-block:
 * the first tab is embedded, every tab is shown.
 */
final class MarkdownParser
{
    private const ANCHOR = '/<a\s+name="([^"]+)"/u';
    private const ATTRIBUTES = '/\s*\{[.#][^}]*\}$/u'; // `#### append() {.collection-method}`
    private const ADMONITION = '/^\[!(\w+)\]\s*/u';

    private readonly CommonMark $parser;

    public function __construct()
    {
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $this->parser = new CommonMark($env);
    }

    /** @return list<array{list<string>, list<array{string, string}>, ?string}> breadcrumb, blocks as [text, source], anchor */
    public function sections(string $rel, string $root): array
    {
        $sections = [];
        $open = [];     // level => title of each heading above the current block
        $items = [];
        $anchor = $next = null; // this section's anchor; the one waiting for the next heading
        $tabbed = false;         // the last item is a tab group that a following tab joins
        $path = Chunker::inside($root, $rel);
        foreach (null === $path ? [] : $this->parser->parse(file_get_contents($path))->children() as $block) {
            if ($block instanceof Heading) {
                if ($open || $items) {
                    $sections[] = [array_values($open), $items, $anchor];
                }
                $open = array_filter($open, static fn ($l) => $l < $block->getLevel(), \ARRAY_FILTER_USE_KEY);
                $open[$block->getLevel()] = preg_replace(self::ATTRIBUTES, '', Chunker::strip($this->inline($block)));
                [$items, $anchor, $next, $tabbed] = [[], $next, null, false];
                continue;
            }
            if ($found = self::anchor($block)) {
                $next = $found;
                continue;
            }
            if ($block instanceof FencedCode && str_contains($block->getInfo() ?? '', 'tab=')) {
                $code = self::code($block);
                if ($tabbed) {
                    $last = array_key_last($items);
                    $items[$last][1] .= "\n\n".$code;
                } else {
                    $items[] = [$code, $code];
                }
                $tabbed = true;
                continue;
            }
            $tabbed = false;
            $text = $this->block($block);
            if ('' !== $text) {
                $items[] = [$text, $text];
            }
        }
        $sections[] = [array_values($open), $items, $anchor];

        return $sections;
    }

    /** `<a name="x"></a>` on its own line: a paragraph of inline HTML, or an HTML block after a blank line. */
    private static function anchor(Node $block): ?string
    {
        $html = match (true) {
            $block instanceof HtmlBlock => $block->getLiteral(),
            $block instanceof Paragraph => self::onlyHtml($block),
            default => null,
        };

        return null !== $html && preg_match(self::ANCHOR, $html, $m) ? $m[1] : null;
    }

    private static function onlyHtml(Paragraph $p): ?string
    {
        $html = '';
        foreach ($p->children() as $node) {
            if ($node instanceof HtmlInline) {
                $html .= $node->getLiteral();
            } elseif (!($node instanceof AbstractStringContainer && '' === Chunker::strip($node->getLiteral()))) {
                return null;
            }
        }

        return $html;
    }

    /** A block as plain text: code kept verbatim, markup and HTML removed. */
    private function block(Node $block): string
    {
        return Chunker::strip(match (true) {
            $block instanceof FencedCode, $block instanceof IndentedCode => self::code($block),
            $block instanceof HtmlBlock, $block instanceof ThematicBreak => '',
            $block instanceof ListBlock => self::isNavigation($block) ? '' : $this->listBlock($block),
            $block instanceof BlockQuote => $this->quote($block),
            $block instanceof Table => $this->table($block),
            $block instanceof Paragraph, $block instanceof Heading => $this->inline($block),
            default => $this->children($block),
        });
    }

    private function children(Node $node, string $sep = "\n\n"): string
    {
        $parts = [];
        foreach ($node->children() as $child) {
            $text = $this->block($child);
            if ('' !== $text) {
                $parts[] = $text;
            }
        }

        return implode($sep, $parts);
    }

    private static function code(FencedCode|IndentedCode $block): string
    {
        return Chunker::rstrip($block->getLiteral());
    }

    private function listBlock(ListBlock $list): string
    {
        $data = $list->getListData();
        $number = $data->start ?? 1;
        $lines = [];
        foreach ($list->children() as $item) {
            $marker = ListBlock::TYPE_ORDERED === $data->type ? ($number++).'. ' : '- ';
            $lines[] = $marker.str_replace("\n", "\n  ", $this->children($item, "\n")); // nested lines under their item
        }

        return implode("\n", $lines);
    }

    /** A table of contents or a nav menu: every item is one link, maybe with a nested list of the same. */
    private static function isNavigation(ListBlock $list): bool
    {
        foreach ($list->children() as $item) {
            foreach ($item->children() as $child) {
                $isLink = $child instanceof Paragraph && 1 === \count(array_filter(
                    iterator_to_array($child->children(), false),
                    static fn (Node $n) => !($n instanceof AbstractStringContainer && '' === Chunker::strip($n->getLiteral())),
                )) && $child->firstChild() instanceof Link;
                if (!$isLink && !($child instanceof ListBlock && self::isNavigation($child))) {
                    return false;
                }
            }
        }

        return true;
    }

    /** `> [!NOTE]` is GitHub's admonition: it reads as RST's `.. note::` does. */
    private function quote(BlockQuote $quote): string
    {
        $text = $this->children($quote);
        if (preg_match(self::ADMONITION, $text, $m) && isset(RstParser::LABELS[strtolower($m[1])])) {
            return RstParser::LABELS[strtolower($m[1])].":\n".Chunker::strip(substr($text, \strlen($m[0])));
        }

        return $text;
    }

    private function table(Table $table): string
    {
        $rows = [];
        foreach ($table->iterator() as $node) {
            if ($node instanceof TableRow) {
                $rows[] = implode(' | ', array_map(fn (Node $cell) => Chunker::strip($this->inline($cell)), iterator_to_array($node->children(), false)));
            }
        }

        return implode("\n", $rows);
    }

    private function inline(Node $node): string
    {
        $out = '';
        foreach ($node->children() as $child) {
            $out .= match (true) {
                $child instanceof Image, $child instanceof HtmlInline => '',
                $child instanceof Newline => "\n",
                $child instanceof AbstractStringContainer => $child->getLiteral(),
                default => $this->inline($child),
            };
        }

        return $out;
    }
}
