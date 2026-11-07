<?php

namespace App\Docs;

/**
 * The docs repos this app searches, keyed by GitHub name (app.docs_repos), and the one used when none is named
 * (DOCS_REPO). By convention a repo's shipped vectors are data/seed_vectors/<key>.bin and its question sets
 * data/queries/<key>/*.tsv; either may be missing.
 *
 * @phpstan-type Repo array{label: string, path: string, extension: string, url: string, exclude?: list<string>, examples?: list<string>}
 */
final class Repos
{
    /** @param array<string, Repo> $repos */
    public function __construct(
        private readonly array $repos,
        private readonly string $default,
    ) {
    }

    public function key(?string $key): string
    {
        $key ??= $this->default;

        return isset($this->repos[$key]) ? $key : throw new \InvalidArgumentException(\sprintf('Unknown docs repo "%s". Known: %s.', $key, implode(', ', array_keys($this->repos))));
    }

    /** @return Repo */
    public function get(?string $key): array
    {
        return $this->repos[$this->key($key)];
    }

    /**
     * The hit's section on its docs site, so a reader whose chunk holds only part of the answer can read on.
     * The anchor is the page's own (`<a name>` in Laravel's docs); without one, the heading slug symfony.com uses.
     * ponytail: heading slug matched 310 of 312 live symfony.com anchors checked; a missing anchor still opens the page.
     *
     * @param string $pattern the repo's `url`, with {page} for the file's path without its extension
     */
    public static function url(array $hit, string $pattern): string
    {
        $url = str_replace('{page}', preg_replace('/\.[^.\/]+$/', '', $hit['file']), $pattern);
        $section = $hit['anchor'] ?? (\count($hit['path']) > 1 ? trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(end($hit['path']))), '-') : '');

        return '' !== $section ? "$url#$section" : $url;
    }

    /** @return array<string, string> key => label, in config order */
    public function labels(): array
    {
        return array_map(static fn (array $repo) => $repo['label'], $this->repos);
    }
}
