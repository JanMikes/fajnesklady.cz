<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Form;

use App\Enum\EmailLogStatus;
use App\Service\Form\EmailLogFilterFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class EmailLogFilterFactoryTest extends TestCase
{
    private EmailLogFilterFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EmailLogFilterFactory();
    }

    public function testEmptyQueryYieldsNullFilter(): void
    {
        $filter = $this->factory->fromRequest(new Request());

        self::assertNull($filter->dateFrom);
        self::assertNull($filter->dateTo);
        self::assertNull($filter->recipient);
        self::assertNull($filter->subject);
        self::assertNull($filter->templateName);
        self::assertNull($filter->status);
    }

    public function testParsesAllFields(): void
    {
        $request = new Request([
            'date_from' => '2026-01-15',
            'date_to' => '2026-01-31',
            'recipient' => 'jan@example.com',
            'subject' => 'Faktura',
            'template' => 'invoice',
            'status' => 'sent',
        ]);

        $filter = $this->factory->fromRequest($request);

        self::assertNotNull($filter->dateFrom);
        self::assertSame('2026-01-15 00:00:00', $filter->dateFrom->format('Y-m-d H:i:s'));
        self::assertNotNull($filter->dateTo);
        self::assertSame('2026-01-31 23:59:59', $filter->dateTo->format('Y-m-d H:i:s'));
        self::assertSame('jan@example.com', $filter->recipient);
        self::assertSame('Faktura', $filter->subject);
        self::assertSame('invoice', $filter->templateName);
        self::assertSame(EmailLogStatus::SENT, $filter->status);
    }

    public function testWhitespaceStringsAreTrimmedToNull(): void
    {
        $request = new Request([
            'recipient' => '   ',
            'subject' => '',
            'template' => "\t\n",
        ]);

        $filter = $this->factory->fromRequest($request);

        self::assertNull($filter->recipient);
        self::assertNull($filter->subject);
        self::assertNull($filter->templateName);
    }

    public function testInvalidDateYieldsNull(): void
    {
        $request = new Request([
            'date_from' => 'not-a-date',
            'date_to' => 'gibberish',
        ]);

        $filter = $this->factory->fromRequest($request);

        self::assertNull($filter->dateFrom);
        self::assertNull($filter->dateTo);
    }

    public function testUnknownStatusYieldsNull(): void
    {
        $request = new Request(['status' => 'gibberish']);

        $filter = $this->factory->fromRequest($request);

        self::assertNull($filter->status);
    }
}
