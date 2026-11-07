<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107070125 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the repo of each chunk: every search and sync filters on it.';
    }

    public function up(Schema $schema): void
    {
        // Without it every query reads all 11.8k JSONB rows to find one repo's: the 234 DBAL chunks took 27.7 ms
        // of vector scan, 1.5 ms with it. Symfony (54% of rows) is still scanned, rightly.
        $this->addSql("CREATE INDEX chunks_repo_idx ON chunks ((metadata->>'repo'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX chunks_repo_idx');
    }
}
