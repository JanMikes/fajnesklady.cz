<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Command\ConfirmOrderPaymentCommand;
use App\Entity\Order;
use App\Service\OrderStatusUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * End-to-end proof that the events reach the page the agency's GTM reads.
 *
 * The unit/outbox tests prove the right rows are written; this proves the rows
 * actually turn into a dataLayer.push() in the delivered HTML, which is the
 * only part their tag configuration can see.
 */
final class AnalyticsDataLayerRenderTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private OrderStatusUrlGenerator $urlGenerator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $this->urlGenerator = $container->get(OrderStatusUrlGenerator::class);
    }

    public function testConfirmedPaymentAppearsAsADataLayerPushOnTheStatusPage(): void
    {
        $order = $this->paidOrder();

        $this->client->request('GET', $this->urlGenerator->generate($order));
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('window.dataLayer.push(', $html, 'Na stránce musí být push do dataLayer.');
        self::assertStringContainsString('"event":"first_payment_success"', $html);
        self::assertStringContainsString('"currency":"CZK"', $html);
        self::assertStringContainsString(sprintf('"order_id":"%s"', $order->id->toRfc4122()), $html);
    }

    public function testTheValueIsRenderedInCrownsNotHalere(): void
    {
        $order = $this->paidOrder();
        $expected = round($order->firstPaymentPrice / 100, 2);

        $this->client->request('GET', $this->urlGenerator->generate($order));
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString(
            sprintf('"value":%s', json_encode($expected)),
            $html,
            'Hodnota v dataLayer musí být v korunách — v haléřích by Ads viděly stonásobek.',
        );
        self::assertStringNotContainsString(
            sprintf('"value":%d', $order->firstPaymentPrice),
            $html,
            'Surová haléřová částka se do dataLayer nesmí dostat.',
        );
    }

    public function testReloadWithoutAcknowledgementStillOffersTheConversion(): void
    {
        $order = $this->paidOrder();
        $url = $this->urlGenerator->generate($order);

        $this->client->request('GET', $url);
        self::assertStringContainsString('first_payment_success', (string) $this->client->getResponse()->getContent());

        // A mail scanner fetching the page runs no JavaScript and therefore
        // never acknowledges. The conversion must survive for the real visit.
        $this->client->request('GET', $url);
        self::assertStringContainsString(
            'first_payment_success',
            (string) $this->client->getResponse()->getContent(),
            'Bez potvrzení z prohlížeče musí konverze zůstat k dispozici.',
        );
    }

    public function testOnceTheBrowserAcknowledgesTheConversionIsNotPushedAgain(): void
    {
        $order = $this->paidOrder();
        $url = $this->urlGenerator->generate($order);

        $this->client->request('GET', $url);
        $html = (string) $this->client->getResponse()->getContent();

        preg_match_all('/ids:\s*(\[[^\]]*\])/', $html, $matches);
        self::assertNotEmpty($matches[1], 'Stránka musí prohlížeči předat id událostí k potvrzení.');

        $this->client->request(
            'POST',
            '/analytics/ack',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: sprintf('{"ids": %s}', $matches[1][0]),
        );
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', $url);
        self::assertStringNotContainsString(
            'first_payment_success',
            (string) $this->client->getResponse()->getContent(),
            'Po potvrzení se konverze už posílat nesmí.',
        );
    }

    public function testAcknowledgementRejectsAMalformedBody(): void
    {
        $this->client->request('POST', '/analytics/ack', server: ['CONTENT_TYPE' => 'application/json'], content: 'not json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAcknowledgementIgnoresUnknownIds(): void
    {
        $this->client->request(
            'POST',
            '/analytics/ack',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{"ids": ["01a0cdfb-0000-7000-8000-000000000000", "nonsense"]}',
        );
        self::assertResponseStatusCodeSame(204);
    }

    private function paidOrder(): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', 'B1')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($order instanceof Order);

        $this->commandBus->dispatch(new ConfirmOrderPaymentCommand($order));

        return $order;
    }
}
