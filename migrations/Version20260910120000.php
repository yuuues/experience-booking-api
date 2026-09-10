<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create experiences table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE experiences (
            id UUID NOT NULL,
            title VARCHAR(150) NOT NULL,
            description TEXT NOT NULL,
            provider_id UUID NOT NULL,
            PRIMARY KEY (id)
        )');
        $this->addSql('CREATE INDEX idx_experiences_provider ON experiences (provider_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE experiences');
    }
}
