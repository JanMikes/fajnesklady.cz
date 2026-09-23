<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\MarkAnalyticsEventsPushedCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Confirms that a browser really pushed measurement events into the dataLayer.
 *
 * Called by components/_analytics_datalayer.html.twig immediately after the
 * push. Until this arrives the events stay pending, so a mail scanner that
 * fetches the status page without running JavaScript consumes nothing and the
 * customer's own click still gets the conversion.
 *
 * Marking an event delivered only ever *suppresses* a future push, so the
 * worst an attacker could do is silence a conversion — and only for an event
 * whose UUIDv7 they already know, which is revealed solely in the page behind
 * the signed status URL. The batch is capped so the endpoint can't be used to
 * walk ids in bulk.
 */
#[Route('/analytics/ack', name: 'public_analytics_ack', methods: ['POST'])]
final class AnalyticsEventAckController extends AbstractController
{
    private const int MAX_IDS_PER_CALL = 20;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || !isset($payload['ids']) || !is_array($payload['ids'])) {
            return new JsonResponse(['error' => 'Expected {"ids": [...]}.'], Response::HTTP_BAD_REQUEST);
        }

        $ids = [];
        foreach (array_slice($payload['ids'], 0, self::MAX_IDS_PER_CALL) as $id) {
            if (is_string($id) && Uuid::isValid($id)) {
                $ids[] = Uuid::fromString($id);
            }
        }

        if ([] !== $ids) {
            // Unknown ids are a no-op in the handler, so nothing here needs to
            // distinguish "already delivered" from "never existed".
            $this->commandBus->dispatch(new MarkAnalyticsEventsPushedCommand($ids));
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
