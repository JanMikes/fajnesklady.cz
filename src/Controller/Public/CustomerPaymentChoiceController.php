<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\OrderRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Spec 088: the dedicated payment-choice step a deferred admin onboarding lands
 * on before signing. The signing token is the authorization (no login) — same
 * model as {@see CustomerSigningController}.
 */
#[Route('/podpis/{token}/zpusob-platby', name: 'public_customer_payment_choice', requirements: ['token' => '[a-f0-9]{64}'])]
final class CustomerPaymentChoiceController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(string $token): Response
    {
        $order = $this->orderRepository->findBySigningToken($token);

        if (null === $order) {
            return $this->render('public/customer_signing_error.html.twig', [
                'error' => 'Neplatný odkaz. Odkaz k podpisu smlouvy nebyl nalezen nebo již byl použit.',
            ]);
        }

        if ($order->isExpired($this->clock->now())) {
            return $this->render('public/customer_signing_error.html.twig', [
                'error' => 'Platnost odkazu k podpisu smlouvy vypršela. Kontaktujte nás pro vytvoření nového odkazu.',
            ]);
        }

        // Not a deferred order (nothing to choose), or already signed → straight to signing.
        if (!$order->customerChoosesPayment || $order->hasSignature()) {
            return $this->redirectToRoute('public_customer_signing', ['token' => $token]);
        }

        // Deferred but no longer payable (cancelled / expired-status): the link is
        // dead — show the error page rather than a choice form that can't complete.
        if (!$order->canBePaid()) {
            return $this->render('public/customer_signing_error.html.twig', [
                'error' => 'Tato objednávka již není aktivní. Kontaktujte nás pro další informace.',
            ]);
        }

        return $this->render('public/customer_payment_choice.html.twig', [
            'order' => $order,
            'storage' => $order->storage,
            'storageType' => $order->storage->storageType,
            'place' => $order->storage->getPlace(),
            'token' => $token,
        ]);
    }
}
