<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark every chunk indexed so far as Symfony\'s: ingest skips unchanged chunks, so it would never add their repo.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE chunks SET metadata = jsonb_set(metadata, '{repo}', '"symfony/symfony-docs"') WHERE metadata->>'repo' IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE chunks SET metadata = metadata - 'repo' - 'anchor'");
    }
}
