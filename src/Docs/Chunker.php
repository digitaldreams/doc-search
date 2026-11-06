<?php

namespace App\Docs;

use Symfony\Component\Finder\Finder;

/**
 * Turns a docs tree into chunks for embeddinggemma-2. A parser per format reads a file into sections of
 * rendered blocks; packing them into chunks is the same for every format.
 *
 * A chunk's "text" is what gets embedded (one config format). Its "source" is the same content with
 * every format, which is what a reader sees. Chunk ids hash file + breadcrumb + text, so an unchanged
 * chunk keeps its vector across re-indexing.
 *
 * @phpstan-type Chunk array{id: string, file: string, path: list<string>, text: string, source: string, anchor: ?string}
 */
final class Chunker
{
    /** Measured 4.4-4.7 on this corpus via Ollama's prompt_eval_count; 4 stays on the safe side. */
    private const CHARS_PER_TOKEN = 4;
    public const SPACE = '\s\x1c-\x1f';
    /** Code-shaped tokens only: acronyms and hyphens also appear in plain questions. */
    private const CODE_SHAPED = '/::|\\\\|_|#\[|\$|\w:\w|\w\/\w|\w\+\w|[a-z][A-Z]/u';

    public function __construct(
        private readonly RstParser $rst = new RstParser(),
        private readonly MarkdownParser $md = new MarkdownParser(),
    ) {
    }

    /** @param array{path: list<string>, text: string} $chunk */
    public static function docInput(array $chunk): string
    {
        return \sprintf('title: %s | text: %s', implode(' > ', $chunk['path']) ?: 'none', $chunk['text']);
    }

    public static function queryInput(string $query): string
    {
        return 'task: search result | query: '.$query;
    }

    /**
     * Fuse keyword search with vectors only for code-shaped or one/two-word queries.
     * Measured: fusing on every query hurts reworded recall, vectors alone miss exact identifiers.
     */
    public static function wantsKeywords(string $query): bool
    {
        return 1 === preg_match(self::CODE_SHAPED, $query) || \count(preg_split('/['.self::SPACE.']+/u', self::strip($query))) <= 2;
    }

    /**
     * @param list<string> $exclude Finder path patterns: a substring, a glob or a regex
     *
     * @return list<Chunk>
     */
    public function chunkTree(string $root, string $extension, array $exclude = [], int $maxTokens = 512): array
    {
        // dot files and dirs are skipped by Finder's default
        $files = Finder::create()->files()->in($root)->name("*.$extension")->notPath($exclude)->sortByName();
        $out = [];
        foreach ($files as $file) {
            array_push($out, ...$this->chunkFile($file->getRelativePathname(), $root, $maxTokens));
        }

        return $out;
    }

    /** @return list<Chunk> */
    public function chunkFile(string $rel, string $root, int $maxTokens = 512): array
    {
        $parser = 'md' === pathinfo($rel, \PATHINFO_EXTENSION) ? $this->md : $this->rst;
        $out = [];
        foreach ($parser->sections($rel, $root) as [$path, $items, $anchor]) {
            foreach ($this->pack($items, $maxTokens) as [$text, $source]) {
                if ('' !== self::strip($text)) {
                    $id = substr(sha1($rel."\0".implode(' > ', $path)."\0".$text), 0, 16);
                    $out[] = ['id' => $id, 'file' => $rel, 'path' => $path, 'text' => $text, 'source' => $source, 'anchor' => $anchor];
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function splitText(string $text, int $limit): array
    {
        $fill = static function (array $pieces, string $sep) use ($limit): array {
            $out = [];
            $cur = '';
            foreach ($pieces as $p) {
                if ('' !== $cur && mb_strlen($cur) + \strlen($sep) + mb_strlen($p) > $limit) {
                    $out[] = $cur;
                    $cur = $p;
                } else {
                    $cur = '' !== $cur ? $cur.$sep.$p : $p;
                }
            }

            return '' !== $cur ? [...$out, $cur] : $out;
        };

        $parts = [];
        foreach ($fill(explode("\n\n", $text), "\n\n") as $para) {
            if (mb_strlen($para) <= $limit) {
                $parts[] = $para;
                continue;
            }
            foreach ($fill(explode("\n", $para), "\n") as $ln) {
                array_push($parts, ...(mb_strlen($ln) <= $limit ? [$ln] : mb_str_split($ln, $limit)));
            }
        }

        return $parts;
    }

    /** @return array{string, string} */
    private static function join(array $a, array $b): array
    {
        return [self::strip($a[0]."\n\n".$b[0]), self::strip($a[1]."\n\n".$b[1])];
    }

    /**
     * Greedily pack whole blocks up to the budget.
     *
     * @param list<array{string, string}> $blocks text, source
     *
     * @return list<array{string, string}> text, source
     */
    private function pack(array $blocks, int $maxTokens): array
    {
        $items = [];
        foreach ($blocks as $item) {
            if ($items && str_ends_with(self::rstrip($items[array_key_last($items)][0]), ':')) { // a lead-in stays with what it introduces
                $items[array_key_last($items)] = self::join($items[array_key_last($items)], $item);
            } else {
                $items[] = $item;
            }
        }
        $limit = $maxTokens * self::CHARS_PER_TOKEN;
        $out = [];
        $cur = null;
        foreach ($items as $item) {
            if (mb_strlen($item[0]) > $limit) {
                if ($cur) {
                    $out[] = $cur;
                    $cur = null;
                }
                // ponytail: split the all-format source so no format is lost; such parts embed every format
                foreach ($this->splitText($item[1], $limit) as $p) {
                    $out[] = [$p, $p];
                }
            } elseif ($cur && mb_strlen($cur[0]) + 2 + mb_strlen($item[0]) > $limit) {
                $out[] = $cur;
                $cur = $item;
            } else {
                $cur = $cur ? self::join($cur, $item) : $item;
            }
        }

        return $cur ? [...$out, $cur] : $out;
    }

    /**
     * The real path of $rel if it is a file inside $root, else null. Every docs read goes through here, so a
     * symlink or an include like `../../.env.local` in a docs repo cannot index (and publish) the host's files.
     */
    public static function inside(string $root, string $rel): ?string
    {
        $path = realpath("$root/$rel");

        return false !== $path && is_file($path) && str_starts_with($path, rtrim((string) realpath($root), '/').'/') ? $path : null;
    }

    // PHP's trim() is ASCII-only; these treat whitespace as Unicode so token budgets and breaks stay exact.
    public static function strip(string $s): string
    {
        return self::rstrip(preg_replace('/\A['.self::SPACE.']+/u', '', $s));
    }

    public static function rstrip(string $s): string
    {
        return preg_replace('/['.self::SPACE.']+\z/u', '', $s);
    }
}
