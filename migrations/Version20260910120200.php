<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910120200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bookings table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bookings (
            id UUID NOT NULL,
            reference CHAR(11) NOT NULL,
            session_id UUID NOT NULL,
            user_id UUID NOT NULL,
            seats INT NOT NULL,
            total_amount BIGINT NOT NULL,
            total_currency CHAR(3) NOT NULL,
            status VARCHAR(16) NOT NULL,
            booked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
            cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
            PRIMARY KEY (id),
            CONSTRAINT fk_bookings_session FOREIGN KEY (session_id) REFERENCES sessions (id),
            CONSTRAINT chk_bookings_seats CHECK (seats > 0),
            CONSTRAINT chk_bookings_status CHECK (status IN (\'confirmed\', \'cancelled\'))
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_bookings_reference ON bookings (reference)');
        $this->addSql('CREATE INDEX idx_bookings_session_status ON bookings (session_id, status)');
        $this->addSql('CREATE INDEX idx_bookings_user ON bookings (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bookings');
    }
}
