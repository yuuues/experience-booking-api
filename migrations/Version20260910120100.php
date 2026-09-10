<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sessions table with one-session-per-day unique index and capacity checks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sessions (
            id UUID NOT NULL,
            experience_id UUID NOT NULL,
            starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            day DATE NOT NULL,
            capacity INT NOT NULL,
            booked_seats INT NOT NULL DEFAULT 0,
            price_amount BIGINT NOT NULL,
            price_currency CHAR(3) NOT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_sessions_experience FOREIGN KEY (experience_id) REFERENCES experiences (id),
            CONSTRAINT chk_sessions_capacity CHECK (capacity > 0),
            CONSTRAINT chk_sessions_booked_seats CHECK (booked_seats >= 0 AND booked_seats <= capacity),
            CONSTRAINT chk_sessions_price CHECK (price_amount >= 0)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_sessions_experience_day ON sessions (experience_id, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }
}
