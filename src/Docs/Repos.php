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

    /** @return array<string, string> key => label, in config order */
    public function labels(): array
    {
        return array_map(static fn (array $repo) => $repo['label'], $this->repos);
    }
}
