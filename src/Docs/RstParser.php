<?php

namespace App\Docs;

/**
 * Reads Sphinx-style reStructuredText docs (Symfony, Twig, Doctrine) into sections of rendered blocks.
 *
 * A block renders twice: "text" with one config format (what gets embedded), "source" with every format
 * (what a reader sees).
 *
 * ponytail: line-based parser, not a full RST parser; the docs' headings and directives are regular enough.
 */
final class RstParser
{
    private const ADORN = '/^([=\-~^"#+*`.:_\'])\1{2,}\s*$/u';
    private const INCLUDE = '/^(\s*)\.\. (include|literalinclude)::\s+(\S+)\s*$/u';
    private const DIRECTIVE = '/^( *)\.\. ([\w:-]+)::(?:\s+(.*))?$/u';
    private const TABLE_RULE = '/^\s*[=\-+|]{3,}[=\-+|\s]*$/u';
    private const OPTION = '/^\s*:[\w-]+:(\s|$)/u'; // `:doc:`x`` opening a body is prose, not an option
    private const COMMENT = '/^\s*\.\.(\s|$)/u'; // `... but` is prose, not a comment
    private const ROLE = '/:[\w:+-]+:`([^`]+)`/u';
    private const LINK = '/`([^`<]+?)(?:\s*<[^>]+>)?`_{1,2}/u';
    private const DROP = ['toctree', 'image', 'figure', 'raw', 'index', 'contents', 'screencast', 'highlight', 'rst-class', 'class', 'sectionauthor'];
    public const LABELS = ['note' => 'Note', 'tip' => 'Tip', 'caution' => 'Caution', 'warning' => 'Warning', 'danger' => 'Danger',
        'important' => 'Important', 'seealso' => 'See also', 'versionadded' => 'New in', 'deprecated' => 'Deprecated in'];

    /** @return list<array{list<string>, list<array{string, string}>, ?string}> breadcrumb, blocks as [text, source], anchor */
    /** @param string $product names the release in version markers: "New in Twig 3.6" */
    public function sections(string $rel, string $root, string $product = 'Symfony'): array
    {
        $out = [];
        foreach ($this->split($this->expand($rel, $root)) as [$path, $body]) {
            $items = [];
            foreach ($this->blocks($body) as $b) {
                $item = [$this->render($b, 'first', $product), $this->render($b, 'all', $product)];
                if ('' !== $item[0] || '' !== $item[1]) {
                    $items[] = $item;
                }
            }
            $out[] = [$path, $items, null]; // symfony.com anchors are heading slugs, made where the link is built
        }

        return $out;
    }

    /**
     * Inline `.. include::`: includes hold real content (e.g. every constraint's options).
     * `.. literalinclude::` becomes a code block of its file, so a config block can still pick one format.
     * ponytail: :lines:/:start-after: are ignored, the whole file is shown; the docs read whole files today.
     *
     * @param list<string> $stack
     *
     * @return list<string>
     */
    private function expand(string $rel, string $root, array $stack = []): array
    {
        $path = Chunker::inside($root, $rel);
        if (null === $path || \in_array($path, $stack, true)) {
            return [];
        }
        $out = [];
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", file_get_contents($path)));
        for ($i = 0; $i < \count($lines); ++$i) {
            if (!preg_match(self::INCLUDE, $lines[$i], $m)) {
                $out[] = $lines[$i];
                continue;
            }
            $dir = \dirname($rel);
            $target = str_starts_with($m[3], '/') ? ltrim($m[3], '/') : ('.' === $dir ? $m[3] : "$dir/$m[3]");
            if ('include' === $m[2]) {
                if (is_file("$root/$target")) {
                    foreach ($this->expand($target, $root, [...$stack, $path]) as $x) {
                        $out[] = '' !== $x ? $m[1].$x : $x;
                    }
                }
                continue;
            }
            $language = '';
            for (; $i + 1 < \count($lines) && preg_match(self::OPTION, $lines[$i + 1]) && self::indent($lines[$i + 1]) > \strlen($m[1]); ++$i) {
                $language = preg_match('/^\s*:language:\s*(\S+)/u', $lines[$i + 1], $l) ? $l[1] : $language;
            }
            $file = Chunker::inside($root, $target);
            if (null !== $file) {
                $out[] = rtrim("$m[1].. code-block:: $language");
                $out[] = '';
                foreach (explode("\n", rtrim(str_replace(["\r\n", "\r"], "\n", file_get_contents($file)))) as $x) {
                    $out[] = '' !== $x ? "$m[1]    $x" : $x;
                }
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
    private function render(array $lines, string $formats, string $product): string
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
                    $label = self::LABELS[$name].(\in_array($name, ['versionadded', 'deprecated'], true) ? " $product" : '');
                    $head = '' !== $arg && \in_array($name, ['versionadded', 'deprecated'], true)
                        ? Chunker::strip("$label $arg").':'
                        : $label.':'.('' !== $arg ? ' '.self::inline($arg) : '');
                    $out[] = $head."\n".$this->render($inner, $formats, $product);
                } elseif (!\in_array($name, self::DROP, true)) {
                    $out[] = ('' !== $arg ? self::inline($arg)."\n" : '').$this->render($inner, $formats, $product);
                }
                $i = $end;
            } elseif (preg_match(self::COMMENT, $line)) { // comment, label, link target, substitution
                $i = self::bodyEnd($lines, $i, self::indent($line));
            } elseif (preg_match(self::TABLE_RULE, $line)) {
                ++$i;
            } elseif (self::endsLiteral($line)) {
                $lead = substr(Chunker::rstrip($line), 0, -1);
                if ('' !== Chunker::strip($lead)) {
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

        return Chunker::strip(preg_replace('/\n{3,}/', "\n\n", implode("\n", $out)));
    }

    /**
     * Split a file into sections, each with its heading breadcrumb.
     * A heading's level is the order its adornment style first appears, per RST rules.
     *
     * @param list<string> $lines
     *
     * @return list<array{list<string>, list<string>}> breadcrumb, body lines
     */
    private function split(array $lines): array
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
        if ($isAdornment($line) && !self::blank($next) && Chunker::rstrip($after) === Chunker::rstrip($line)
            && mb_strlen(Chunker::rstrip($line)) >= mb_strlen(Chunker::strip($next))) {
            return [Chunker::strip($next), $line[0].'over', 3];
        }

        // Title
        // =====
        if (!self::blank($line) && !self::startsWithSpace($line) && !$isAdornment($line) && $isAdornment($next)
            && mb_strlen(Chunker::rstrip($next)) >= mb_strlen(Chunker::rstrip($line))) {
            return [Chunker::strip($line), $next[0].'under', 2];
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


    private static function blank(string $s): bool
    {
        return !preg_match('/[^'.Chunker::SPACE.']/u', $s);
    }

    private static function indent(string $s): int
    {
        return preg_match('/\A['.Chunker::SPACE.']*/u', $s, $m) ? mb_strlen($m[0]) : 0;
    }

    private static function startsWithSpace(string $s): bool
    {
        return 1 === preg_match('/\A['.Chunker::SPACE.']/u', $s);
    }

    private static function endsLiteral(string $line): bool
    {
        return str_ends_with(Chunker::rstrip($line), '::');
    }
}
