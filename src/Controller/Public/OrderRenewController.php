<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use App\Repository\OrderRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/objednavka/prodlouzit/{previousOrderId}',
    name: 'public_order_renew',
    requirements: ['previousOrderId' => '[0-9a-f-]{36}'],
)]
final class OrderRenewController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly RequestStack $requestStack,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $previousOrderId): RedirectResponse
    {
        if (!Uuid::isValid($previousOrderId)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $previous = $this->orderRepository->find(Uuid::fromString($previousOrderId));
        if (null === $previous) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $storage = $previous->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        if (!$place->isActive || !$storageType->isActive) {
            $this->addFlash('error', 'Tato pobočka nebo typ skladu už není v nabídce. Vyberte si prosím z aktuální nabídky.');

            return $this->redirectToRoute('app_home');
        }

        // Renewal only makes sense for orders the customer actually held
        // (paid / completed). Cancelled / expired / never-paid orders fall through
        // to "make a fresh order" — no PII prefill, no special routing.
        if (!in_array($previous->status, [OrderStatus::PAID, OrderStatus::COMPLETED], true)) {
            return $this->redirectToRoute('public_order_create', [
                'placeId' => $place->id->toRfc4122(),
                'storageTypeId' => $storageType->id->toRfc4122(),
                'storageId' => $storage->id->toRfc4122(),
            ]);
        }

        // Unlimited rentals don't expire on their own — there is nothing to "prolong".
        if (RentalType::UNLIMITED === $previous->rentalType) {
            $this->addFlash('info', 'Vaše smlouva je na dobu neurčitou — pokračuje automaticky. Pokud si přejete změnit pronájem, vyberte si z aktuální nabídky.');

            return $this->redirectToRoute('public_place_detail', ['id' => $place->id->toRfc4122()]);
        }

        // Limited renewal: continuous if the previous period still has time on it,
        // otherwise tomorrow. Same duration as the previous order.
        $today = $this->clock->now()->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $previousEnd = $previous->endDate ?? $tomorrow;
        $newStart = $previousEnd > $tomorrow ? $previousEnd : $tomorrow;

        // We've already redirected unlimited (endDate-null) rentals above, so endDate is set.
        \assert($previous->endDate instanceof \DateTimeImmutable);
        $previousDays = (int) $previous->startDate->diff($previous->endDate)->days;
        if ($previousDays < 1) {
            $previousDays = 30;
        }
        $newEnd = $newStart->modify(sprintf('+%d days', $previousDays));

        $formData = OrderFormData::fromUser($previous->user);
        $formData->rentalType = RentalType::LIMITED;
        $formData->startDate = $newStart;
        $formData->endDate = $newEnd;
        // Carry forward the previous order's billing mode — customer can still
        // change it on the order form before signing.
        $formData->billingMode = $previous->billingMode;

        $this->requestStack->getSession()->set('order_form_data', $formData->toSessionArray());

        return $this->redirectToRoute('public_order_create', [
            'placeId' => $place->id->toRfc4122(),
            'storageTypeId' => $storageType->id->toRfc4122(),
            'storageId' => $storage->id->toRfc4122(),
        ]);
    }
}
