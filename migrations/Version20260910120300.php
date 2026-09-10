<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sent_notifications table (email idempotency)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sent_notifications (
            booking_reference CHAR(11) NOT NULL,
            type VARCHAR(32) NOT NULL,
            sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            PRIMARY KEY (booking_reference, type)
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sent_notifications');
    }
}
