<?php

namespace App\Docs;

use Symfony\Component\Finder\Finder;

/**
 * Turns Symfony's reStructuredText docs into chunks for embeddinggemma-2.
 *
 * A chunk's "text" is what gets embedded (one config format). Its "source" is the same content with
 * every format, which is what a reader sees. Chunk ids hash file + breadcrumb + text, so an unchanged
 * chunk keeps its vector across re-indexing.
 *
 * ponytail: line-based parser, not a full RST parser; the docs' headings and directives are regular enough.
 *
 * @phpstan-type Chunk array{id: string, file: string, path: list<string>, text: string, source: string}
 */
final class Chunker
{
    /** Measured 4.4-4.7 on this corpus via Ollama's prompt_eval_count; 4 stays on the safe side. */
    private const CHARS_PER_TOKEN = 4;
    private const SPACE = '\s\x1c-\x1f';
    private const ADORN = '/^([=\-~^"#+*`.:_\'])\1{2,}\s*$/u';
    private const INCLUDE = '/^(\s*)\.\. include::\s+(\S+)\s*$/u';
    private const DIRECTIVE = '/^( *)\.\. ([\w:-]+)::(?:\s+(.*))?$/u';
    private const TABLE_RULE = '/^\s*[=\-+|]{3,}[=\-+|\s]*$/u';
    private const OPTION = '/^\s*:[\w-]+:(\s|$)/u'; // `:doc:`x`` opening a body is prose, not an option
    private const COMMENT = '/^\s*\.\.(\s|$)/u'; // `... but` is prose, not a comment
    private const ROLE = '/:[\w:+-]+:`([^`]+)`/u';
    private const LINK = '/`([^`<]+?)(?:\s*<[^>]+>)?`_{1,2}/u';
    private const DROP = ['toctree', 'image', 'figure', 'raw', 'index', 'contents', 'screencast', 'highlight', 'rst-class', 'class'];
    private const LABELS = ['note' => 'Note', 'tip' => 'Tip', 'caution' => 'Caution', 'warning' => 'Warning', 'danger' => 'Danger',
        'important' => 'Important', 'seealso' => 'See also', 'versionadded' => 'New in Symfony', 'deprecated' => 'Deprecated in Symfony'];
    /** Code-shaped tokens only: acronyms and hyphens also appear in plain questions. */
    private const CODE_SHAPED = '/::|\\\\|_|#\[|\$|\w:\w|\w\/\w|\w\+\w|[a-z][A-Z]/u';

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

    /** @return list<Chunk> */
    public function chunkTree(string $root, int $maxTokens = 512): array
    {
        // dot files/dirs are skipped by Finder's default; `_*` files and dirs are includes and build output
        $files = Finder::create()->files()->in($root)->name('*.rst')->notPath('#(^|/)_#')->sortByName();
        $out = [];
        foreach ($files as $file) {
            array_push($out, ...$this->chunkFile($file->getRelativePathname(), $root, $maxTokens));
        }

        return $out;
    }

    /** @return list<Chunk> */
    public function chunkFile(string $rel, string $root, int $maxTokens = 512): array
    {
        $out = [];
        foreach ($this->sections($this->expand($rel, $root)) as [$path, $body]) {
            foreach ($this->pack($body, $maxTokens) as [$text, $source]) {
                if ('' !== self::strip($text)) {
                    $id = substr(sha1($rel."\0".implode(' > ', $path)."\0".$text), 0, 16);
                    $out[] = ['id' => $id, 'file' => $rel, 'path' => $path, 'text' => $text, 'source' => $source];
                }
            }
        }

        return $out;
    }

    /**
     * Inline `.. include::`: includes hold real content (e.g. every constraint's options).
     *
     * @param list<string> $stack
     *
     * @return list<string>
     */
    private function expand(string $rel, string $root, array $stack = []): array
    {
        $path = realpath("$root/$rel");
        if (false === $path || \in_array($path, $stack, true)) {
            return [];
        }
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", file_get_contents($path))) as $line) {
            if (!preg_match(self::INCLUDE, $line, $m)) {
                $out[] = $line;
                continue;
            }
            $dir = \dirname($rel);
            $target = str_starts_with($m[2], '/') ? ltrim($m[2], '/') : ('.' === $dir ? $m[2] : "$dir/$m[2]");
            if (!is_file("$root/$target")) {
                continue;
            }
            foreach ($this->expand($target, $root, [...$stack, $path]) as $x) {
                $out[] = '' !== $x ? $m[1].$x : $x;
            }
        }

        return $out;
    }

    private static function inline(string $line): string
    {
        $parts = preg_split('/``(.+?)``/su', $line, -1, \PREG_SPLIT_DELIM_CAPTURE); // odd indexes are literals, left untouched
        foreach ($parts as $i => $p) {
            if (1 === $i % 2) {
                continue;
            }
            $p = preg_replace_callback(self::ROLE, static function (array $m): string {
                $text = preg_replace('/\s*<[^>]+>$/u', '', $m[1]);

                return ltrim(str_replace('\\\\', '\\', '' !== $text ? $text : $m[1]), '~!');
            }, $p);
            $p = preg_replace(self::LINK, '$1', $p);
            $p = preg_replace('/\*\*(.+?)\*\*/su', '$1', $p);
            $parts[$i] = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '$1', $p);
        }

        return implode('', $parts);
    }

    /** @param list<string> $lines */
    private static function bodyEnd(array $lines, int $i, int $indent): int
    {
        $j = $i + 1;
        while ($j < \count($lines) && (self::blank($lines[$j]) || self::indent($lines[$j]) > $indent)) {
            ++$j;
        }

        return $j;
    }

    /**
     * @param list<string> $body
     *
     * @return list<string>
     */
    private static function dedented(array $body): array
    {
        while ($body && (self::blank($body[0]) || preg_match(self::OPTION, $body[0]))) {
            array_shift($body);
        }

        return explode("\n", trim(self::dedent(implode("\n", $body)), "\n"));
    }

    private static function dedent(string $text): string
    {
        $text = preg_replace('/^[ \t]+$/m', '', $text);
        preg_match_all('/^([ \t]*)[^ \t\n]/m', $text, $m);
        $margin = null;
        foreach ($m[1] as $indent) {
            if (null === $margin || str_starts_with($margin, $indent)) {
                $margin = $indent;
            } elseif (!str_starts_with($indent, $margin)) {
                $margin = substr($margin, 0, strspn($margin ^ $indent, "\0"));
            }
        }

        return $margin ? preg_replace('/^'.$margin.'/m', '', $text) : $text;
    }

    /** @param list<string> $inner */
    private function configBlock(array $inner, string $formats): string
    {
        $children = [];
        for ($i = 0; $i < \count($inner);) {
            if (preg_match(self::DIRECTIVE, $inner[$i], $m) && \in_array($m[2], ['code-block', 'code'], true)) {
                $end = self::bodyEnd($inner, $i, \strlen($m[1]));
                if ('first' !== $formats || 'xml' !== ($m[3] ?? '')) { // XML is the noisiest format to embed
                    $children[] = implode("\n", self::dedented(\array_slice($inner, $i + 1, $end - $i - 1)));
                }
                $i = $end;
            } else {
                ++$i;
            }
        }

        return implode("\n\n", 'first' === $formats ? \array_slice($children, 0, 1) : $children);
    }

    /**
     * An RST block as plain text: code kept verbatim, markup removed.
     *
     * @param list<string> $lines
     */
    private function render(array $lines, string $formats): string
    {
        $out = [];
        for ($i = 0; $i < \count($lines);) {
            $line = $lines[$i];
            if (preg_match(self::DIRECTIVE, $line, $m)) {
                [$indent, $name, $arg] = [\strlen($m[1]), $m[2], $m[3] ?? ''];
                $end = self::bodyEnd($lines, $i, $indent);
                $inner = self::dedented(\array_slice($lines, $i + 1, $end - $i - 1));
                if (\in_array($name, ['code-block', 'code'], true)) {
                    $out[] = implode("\n", $inner);
                } elseif ('configuration-block' === $name) {
                    $out[] = $this->configBlock($inner, $formats);
                } elseif (isset(self::LABELS[$name])) {
                    $label = self::LABELS[$name];
                    $head = '' !== $arg && \in_array($name, ['versionadded', 'deprecated'], true)
                        ? self::strip("$label $arg").':'
                        : $label.':'.('' !== $arg ? ' '.self::inline($arg) : '');
                    $out[] = $head."\n".$this->render($inner, $formats);
                } elseif (!\in_array($name, self::DROP, true)) {
                    $out[] = ('' !== $arg ? self::inline($arg)."\n" : '').$this->render($inner, $formats);
                }
                $i = $end;
            } elseif (preg_match(self::COMMENT, $line)) { // comment, label, link target, substitution
                $i = self::bodyEnd($lines, $i, self::indent($line));
            } elseif (preg_match(self::TABLE_RULE, $line)) {
                ++$i;
            } elseif (self::endsLiteral($line)) {
                $lead = substr(self::rstrip($line), 0, -1);
                if ('' !== self::strip($lead)) {
                    $out[] = self::inline($lead);
                }
                $end = self::bodyEnd($lines, $i, self::indent($line));
                $out[] = implode("\n", self::dedented(\array_slice($lines, $i + 1, $end - $i - 1)));
                $i = $end;
            } else {
                // the whole paragraph at once: roles and literals wrap across lines
                $j = $i + 1;
                while ($j < \count($lines) && !self::blank($lines[$j]) && !(preg_match(self::DIRECTIVE, $lines[$j])
                    || preg_match(self::COMMENT, $lines[$j]) || preg_match(self::TABLE_RULE, $lines[$j]) || self::endsLiteral($lines[$j]))) {
                    ++$j;
                }
                $out[] = self::inline(implode("\n", \array_slice($lines, $i, $j - $i)));
                $i = $j;
            }
        }

        return self::strip(preg_replace('/\n{3,}/', "\n\n", implode("\n", $out)));
    }

    /**
     * Split a file into sections, each with its heading breadcrumb.
     * A heading's level is the order its adornment style first appears, per RST rules.
     *
     * @param list<string> $lines
     *
     * @return list<array{list<string>, list<string>}> breadcrumb, body lines
     */
    private function sections(array $lines): array
    {
        $sections = [];
        $styles = [];   // adornment styles in first-seen order: index = level
        $open = [];     // level => title of each heading above the current line
        $body = [];
        for ($i = 0; $i < \count($lines);) {
            $heading = self::heading($lines, $i);
            if (null === $heading) {
                $body[] = $lines[$i++];
                continue;
            }
            [$title, $style, $height] = $heading;

            $hasContent = array_filter($body, static fn ($line) => !self::blank($line));
            if ($open || $hasContent) { // skip an empty preamble before the first heading
                $sections[] = [array_values($open), $body];
            }

            $level = array_search($style, $styles, true);
            if (false === $level) {
                $styles[] = $style;
                $level = array_key_last($styles);
            }
            // a heading closes every open heading at its level or deeper
            $open = array_filter($open, static fn ($l) => $l < $level, \ARRAY_FILTER_USE_KEY);
            $open[$level] = self::inline($title);

            $body = [];
            $i += $height;
        }
        $sections[] = [array_values($open), $body];

        return $sections;
    }

    /**
     * The heading starting at line $i, if there is one.
     *
     * @param list<string> $lines
     *
     * @return array{string, string, int}|null title, adornment style, lines it spans
     */
    private static function heading(array $lines, int $i): ?array
    {
        [$line, $next, $after] = [$lines[$i], $lines[$i + 1] ?? '', $lines[$i + 2] ?? ''];
        $isAdornment = static fn (string $s): bool => 1 === preg_match(self::ADORN, $s);

        // =====
        // Title
        // =====
        if ($isAdornment($line) && !self::blank($next) && self::rstrip($after) === self::rstrip($line)
            && mb_strlen(self::rstrip($line)) >= mb_strlen(self::strip($next))) {
            return [self::strip($next), $line[0].'over', 3];
        }

        // Title
        // =====
        if (!self::blank($line) && !self::startsWithSpace($line) && !$isAdornment($line) && $isAdornment($next)
            && mb_strlen(self::rstrip($next)) >= mb_strlen(self::rstrip($line))) {
            return [self::strip($line), $next[0].'under', 2];
        }

        return null;
    }

    /**
     * Split at column-0 lines that follow a blank: a directive stays whole with its indented body.
     *
     * @param list<string> $body
     *
     * @return list<list<string>>
     */
    private function blocks(array $body): array
    {
        $out = $cur = [];
        foreach ($body as $k => $line) {
            if (!self::blank($line) && !self::startsWithSpace($line) && $cur && self::blank($body[$k - 1])) {
                $out[] = $cur;
                $cur = [];
            }
            $cur[] = $line;
        }

        return $cur ? [...$out, $cur] : $out;
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
     * @param list<string> $body
     *
     * @return list<array{string, string}> text, source
     */
    private function pack(array $body, int $maxTokens): array
    {
        $items = [];
        foreach ($this->blocks($body) as $b) {
            $item = [$this->render($b, 'first'), $this->render($b, 'all')];
            if ('' === $item[0] && '' === $item[1]) {
                continue;
            }
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

    // PHP's trim() is ASCII-only; these treat whitespace as Unicode so token budgets and breaks stay exact.
    private static function strip(string $s): string
    {
        return self::rstrip(preg_replace('/\A['.self::SPACE.']+/u', '', $s));
    }

    private static function rstrip(string $s): string
    {
        return preg_replace('/['.self::SPACE.']+\z/u', '', $s);
    }

    private static function blank(string $s): bool
    {
        return !preg_match('/[^'.self::SPACE.']/u', $s);
    }

    private static function indent(string $s): int
    {
        return preg_match('/\A['.self::SPACE.']*/u', $s, $m) ? mb_strlen($m[0]) : 0;
    }

    private static function startsWithSpace(string $s): bool
    {
        return 1 === preg_match('/\A['.self::SPACE.']/u', $s);
    }

    private static function endsLiteral(string $line): bool
    {
        return str_ends_with(self::rstrip($line), '::');
    }
}
