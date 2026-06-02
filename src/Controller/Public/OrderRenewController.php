<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Form\OrderFormData;
use App\Repository\OrderRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
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
        private readonly UriSigner $uriSigner,
    ) {
    }

    public function __invoke(Request $request, string $previousOrderId): RedirectResponse
    {
        if (!Uuid::isValid($previousOrderId)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $previous = $this->orderRepository->find(Uuid::fromString($previousOrderId));
        if (null === $previous) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        // The renewal page prefills the previous customer's personal data
        // (name, address, birth date, billing details) into the visitor's
        // session, so the visitor must be entitled to it: either the
        // authenticated owner of the previous order (portal "Prodloužit"
        // button — links unsigned) OR someone following the HMAC-signed link
        // mailed to the customer. A stranger with a guessed or forwarded order
        // id and no valid signature is rejected.
        $currentUser = $this->getUser();
        $isOwner = $currentUser instanceof User && $previous->user->id->equals($currentUser->id);

        if (!$isOwner && !$this->uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Neplatný nebo expirovaný odkaz.');
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

        $today = $this->clock->now()->setTime(0, 0);
        $tomorrow = $today->modify('+1 day');
        $previousEnd = $previous->endDate ?? $tomorrow;
        $newStart = $previousEnd > $tomorrow ? $previousEnd : $tomorrow;

        $formData = OrderFormData::fromUser($previous->user);
        $formData->billingMode = $previous->billingMode;

        if (RentalType::UNLIMITED === $previous->rentalType) {
            $formData->rentalType = RentalType::UNLIMITED;
            $formData->startDate = $newStart;
        } else {
            $previousDays = (int) $previous->startDate->diff($previous->endDate ?? $tomorrow)->days;
            if ($previousDays < 1) {
                $previousDays = 30;
            }
            $newEnd = $newStart->modify(sprintf('+%d days', $previousDays));

            $formData->rentalType = RentalType::LIMITED;
            $formData->startDate = $newStart;
            $formData->endDate = $newEnd;
        }

        $this->requestStack->getSession()->set('order_form_data', $formData->toSessionArray());

        return $this->redirectToRoute('public_order_create', [
            'placeId' => $place->id->toRfc4122(),
            'storageTypeId' => $storageType->id->toRfc4122(),
            'storageId' => $storage->id->toRfc4122(),
        ]);
    }
}
