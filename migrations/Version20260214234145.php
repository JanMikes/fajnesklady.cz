<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260214234145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change GoPay payment ID columns from INT to VARCHAR (string identifiers)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ALTER go_pay_payment_id TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE orders ALTER go_pay_parent_payment_id TYPE VARCHAR(255)');
        $this->addSql('ALTER TABLE contract ALTER go_pay_parent_payment_id TYPE VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ALTER go_pay_payment_id TYPE INT USING go_pay_payment_id::integer');
        $this->addSql('ALTER TABLE orders ALTER go_pay_parent_payment_id TYPE INT USING go_pay_parent_payment_id::integer');
        $this->addSql('ALTER TABLE contract ALTER go_pay_parent_payment_id TYPE INT USING go_pay_parent_payment_id::integer');
    }
}
