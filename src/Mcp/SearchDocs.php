<?php

namespace App\Mcp;

use App\Docs\Repos;
use App\Docs\Retriever;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * The docs search as one MCP tool for coding agents. Markdown out, not JSON: small models read it better and it
 * costs fewer tokens. No rerank: about a minute per question is longer than any agent loop waits.
 */
#[McpTool(
    name: 'search_docs',
    description: 'Search the official framework documentation on this machine. Call it BEFORE writing code that uses '
        .'Symfony (8.x), Laravel (13.x), Twig (3.x) or Doctrine ORM (3.7) / DBAL, and whenever unsure of a class, '
        .'attribute, option or command. Ask in plain words ("validate a form field is an email") or with an exact name '
        .'("MapRequestPayload"). Returns the best matching passages with code examples and a link to each.',
)]
final class SearchDocs
{
    private const MAX_QUERY = 500;

    public function __construct(
        private readonly Retriever $retriever,
        private readonly Repos $repos,
    ) {
    }

    /**
     * @param string $query what you want to do, or an exact class/option name
     * @param string $docs  which documentation to search
     * @param int    $limit how many passages to return
     */
    public function __invoke(
        string $query,
        // ponytail: literal list, kept equal to app.docs_repos by SearchDocsTest
        #[Schema(enum: ['symfony/symfony-docs', 'laravel/docs', 'twigphp/Twig', 'doctrine/orm', 'doctrine/dbal'])]
        string $docs,
        #[Schema(minimum: 1, maximum: 10)]
        int $limit = 5,
    ): string {
        $query = trim($query);
        if ('' === $query || mb_strlen($query) > self::MAX_QUERY) {
            throw new ToolCallException(\sprintf('Ask a question of 1 to %d characters.', self::MAX_QUERY));
        }
        try {
            $url = $this->repos->get($docs)['url'];
        } catch (\InvalidArgumentException $e) {
            throw new ToolCallException($e->getMessage());
        }

        $result = $this->retriever->search($query, $docs, max(1, min(10, $limit)));
        if ([] === $result['hits']) {
            return 'No passage found. Try other words or an exact class name.';
        }

        $out = 'keyword' === $result['mode'] ? "Keyword-only results (the embedding model is offline), so they may miss reworded questions.\n\n" : '';

        return $out.implode("\n\n---\n\n", array_map(
            static fn (array $hit) => \sprintf("## %s\n\n%s\n\nSource: %s", implode(' > ', $hit['path']), trim($hit['source']), Repos::url($hit, $url)),
            $result['hits'],
        ));
    }
}
