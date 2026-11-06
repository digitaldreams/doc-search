<?php

namespace App\Docs;

/**
 * The question sets and the definition of a correct result.
 *
 * Hit = a result from an accepted page that literally contains the answer string. That definition does
 * not depend on how pages were chunked, and it fails when an answer was split across two chunks.
 */
final class TestSet
{
    public const CUTOFFS = [1, 3, 5, 10, 20, 50];

    /** @return list<array{class: string, file: string, needle: string, query: string}> */
    public static function load(string $path): array
    {
        $rows = [];
        foreach (\array_slice(file($path, \FILE_IGNORE_NEW_LINES), 1) as $line) {
            $rows[] = array_combine(['class', 'file', 'needle', 'query'], explode("\t", $line));
        }

        return $rows;
    }

    /** @return ?array{class: string, file: string, needle: string, query: string} the test question with this exact wording */
    public static function find(string $directory, string $query): ?array
    {
        foreach (glob($directory.'/*.tsv') as $file) {
            foreach (self::load($file) as $question) {
                if ($question['query'] === $query) {
                    return $question;
                }
            }
        }

        return null;
    }

    /**
     * @param array{file: string, needle: string}                 $question
     * @param list<array{file: string, path: list<string>, source: string}> $hits
     *
     * @return ?int 1-based position of the first correct result in the top 50
     */
    public static function rank(array $question, array $hits): ?int
    {
        $needle = self::normalize($question['needle']);
        $file = '~'.str_replace('~', '\~', $question['file']).'$~D';
        foreach (\array_slice($hits, 0, 50) as $i => $hit) {
            if (preg_match($file, $hit['file']) && str_contains(self::normalize(implode(' ', $hit['path'])."\n".$hit['source']), $needle)) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * @param list<?int> $ranks
     *
     * @return array<string, int|float>
     */
    public static function recall(array $ranks): array
    {
        $out = ['n' => \count($ranks)];
        foreach (self::CUTOFFS as $k) {
            $out["r@$k"] = $ranks ? round(\count(array_filter($ranks, static fn ($r) => null !== $r && $r <= $k)) / \count($ranks), 3) : 0.0;
        }

        return $out;
    }

    private static function normalize(string $s): string
    {
        return preg_replace('/\s+/u', ' ', str_replace(['`', '*', '\\\\'], ['', '', '\\'], $s));
    }
}
