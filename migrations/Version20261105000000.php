<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261105000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chunks with their vectors, a term index for BM25, and saved query vectors.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
        // id, metadata and embedding are what Symfony AI's Postgres store reads and writes.
        // No vector index: an exact scan of 6,370 chunks takes about 19 ms, and HNSW would save only about 17 of them. See GUIDE.md.
        $this->addSql('CREATE TABLE chunks (id TEXT PRIMARY KEY, metadata JSONB NOT NULL, embedding vector(768) NOT NULL, tokens INT NOT NULL DEFAULT 0)');
        $this->addSql('CREATE TABLE chunk_terms (term TEXT NOT NULL, chunk_id TEXT NOT NULL REFERENCES chunks (id) ON DELETE CASCADE, tf INT NOT NULL, PRIMARY KEY (term, chunk_id))');
        $this->addSql('CREATE INDEX chunk_terms_chunk_idx ON chunk_terms (chunk_id)');
        $this->addSql('CREATE TABLE query_vectors (query TEXT PRIMARY KEY, embedding vector(768) NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE query_vectors');
        $this->addSql('DROP TABLE chunk_terms');
        $this->addSql('DROP TABLE chunks');
    }
}
