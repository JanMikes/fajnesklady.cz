<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\CustomerSignOnboardingCommand;
use App\Enum\PaymentMethod;
use App\Enum\SigningMethod;
use App\Repository\OrderRepository;
use App\Service\PriceCalculator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/podpis/{token}', name: 'public_customer_signing', requirements: ['token' => '[a-f0-9]{64}'])]
final class CustomerSigningController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly PriceCalculator $priceCalculator,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $token, Request $request): Response
    {
        $order = $this->orderRepository->findBySigningToken($token);

        if (null === $order) {
            return $this->render('public/customer_signing_error.html.twig', [
                'error' => 'Neplatný odkaz. Odkaz k podpisu smlouvy nebyl nalezen nebo již byl použit.',
            ]);
        }

        $now = $this->clock->now();

        if ($order->isExpired($now)) {
            return $this->render('public/customer_signing_error.html.twig', [
                'error' => 'Platnost odkazu k podpisu smlouvy vypršela. Kontaktujte nás pro vytvoření nového odkazu.',
            ]);
        }

        $totalPrice = $this->priceCalculator->calculateFirstPaymentPrice(
            $order->storage,
            $order->startDate,
            $order->endDate,
        );

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $order);
        }

        return $this->render('public/customer_signing.html.twig', [
            'order' => $order,
            'storage' => $order->storage,
            'storageType' => $order->storage->storageType,
            'place' => $order->storage->place,
            'totalPrice' => $totalPrice,
            'isRecurring' => null === $order->endDate,
        ]);
    }

    private function handlePost(Request $request, \App\Entity\Order $order): Response
    {
        $accepted = $request->request->getBoolean('accept_contract');
        $signatureData = $request->request->getString('signature_data');
        $signingMethodValue = $request->request->getString('signing_method');
        $signatureConsent = $request->request->getBoolean('signature_consent');
        $acceptVop = $request->request->getBoolean('accept_vop');
        $acceptGdpr = $request->request->getBoolean('accept_gdpr');
        $signingPlace = trim($request->request->getString('signing_place'));

        $errors = [];
        if (!$accepted) {
            $errors[] = 'Pro pokračování je nutné souhlasit se smluvními podmínkami.';
        }
        if (!$acceptVop) {
            $errors[] = 'Pro pokračování je nutné souhlasit s všeobecnými obchodními podmínkami.';
        }
        if (!$acceptGdpr) {
            $errors[] = 'Pro pokračování je nutné souhlasit se zpracováním osobních údajů.';
        }
        if ('' === $signatureData) {
            $errors[] = 'Pro pokračování je nutné přidat podpis.';
        }
        if ('' === $signingPlace) {
            $errors[] = 'Pro pokračování je nutné vyplnit místo podpisu.';
        }
        if (!$signatureConsent) {
            $errors[] = 'Pro pokračování je nutné potvrdit souhlas s elektronickým podpisem.';
        }

        $signingMethod = SigningMethod::tryFrom($signingMethodValue);
        if (null === $signingMethod) {
            $errors[] = 'Neplatná metoda podpisu.';
        }

        if ([] !== $errors) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }

            $totalPrice = $this->priceCalculator->calculateFirstPaymentPrice(
                $order->storage,
                $order->startDate,
                $order->endDate,
            );

            return $this->render('public/customer_signing.html.twig', [
                'order' => $order,
                'storage' => $order->storage,
                'storageType' => $order->storage->storageType,
                'place' => $order->storage->place,
                'totalPrice' => $totalPrice,
                'isRecurring' => null === $order->endDate,
            ]);
        }

        try {
            $typedName = $request->request->getString('typed_name') ?: null;
            $styleId = $request->request->getString('style_id') ?: null;

            \assert($signingMethod instanceof SigningMethod);

            $this->commandBus->dispatch(new CustomerSignOnboardingCommand(
                order: $order,
                signatureDataUrl: $signatureData,
                signingMethod: $signingMethod,
                signingPlace: $signingPlace,
                typedName: $typedName,
                styleId: $styleId,
            ));

            if (PaymentMethod::GOPAY === $order->paymentMethod) {
                return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
            }

            return $this->redirectToRoute('public_customer_signing_complete', ['id' => $order->id]);
        } catch (\Exception $e) {
            $this->logger->error('Customer signing failed', ['exception' => $e]);
            $this->addFlash('error', 'Při podpisu smlouvy došlo k chybě. Zkuste to prosím znovu.');

            $totalPrice = $this->priceCalculator->calculateFirstPaymentPrice(
                $order->storage,
                $order->startDate,
                $order->endDate,
            );

            return $this->render('public/customer_signing.html.twig', [
                'order' => $order,
                'storage' => $order->storage,
                'storageType' => $order->storage->storageType,
                'place' => $order->storage->place,
                'totalPrice' => $totalPrice,
                'isRecurring' => null === $order->endDate,
            ]);
        }
    }
}
