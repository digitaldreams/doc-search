<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record which embedding model built the index, so vectors from two models never share it.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE index_meta (model TEXT NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE index_meta');
    }
}
