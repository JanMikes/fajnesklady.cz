<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\AcceptOrderTermsCommand;
use App\Command\SignOrderCommand;
use App\Enum\OrderStatus;
use App\Enum\SigningMethod;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/objednavka/{id}/prijmout', name: 'public_order_accept')]
final class OrderAcceptController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        $order = $this->orderRepository->find(Uuid::fromString($id));

        if (null === $order) {
            throw new NotFoundHttpException('Objednávka nenalezena.');
        }

        // Already completed - show completion page
        if (OrderStatus::COMPLETED === $order->status) {
            return $this->redirectToRoute('public_order_complete', ['id' => $order->id]);
        }

        // Terms already accepted and signed - go to payment
        if ($order->hasAcceptedTerms() && $order->hasSignature()) {
            return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
        }

        // Only reserved orders can accept terms
        if (OrderStatus::RESERVED !== $order->status) {
            $this->addFlash('error', 'Tuto objednávku nelze dokončit.');

            return $this->redirectToRoute($this->getUser() ? 'portal_browse_places' : 'app_home');
        }

        $storage = $order->storage;
        $storageType = $storage->storageType;
        $place = $storage->getPlace();

        // Handle contract acceptance + signature
        if ($request->isMethod('POST')) {
            $accepted = $request->request->getBoolean('accept_contract');
            $signatureData = $request->request->getString('signature_data');
            $signingMethodValue = $request->request->getString('signing_method');
            $signatureConsent = $request->request->getBoolean('signature_consent');

            $errors = [];
            if (!$accepted) {
                $errors[] = 'Pro pokračování k platbě je nutné souhlasit se smluvními podmínkami.';
            }
            if ('' === $signatureData) {
                $errors[] = 'Pro pokračování je nutné přidat podpis.';
            }
            if (!$signatureConsent) {
                $errors[] = 'Pro pokračování je nutné potvrdit souhlas s elektronickým podpisem.';
            }

            $signingMethod = SigningMethod::tryFrom($signingMethodValue);
            if ('' !== $signingMethodValue && null === $signingMethod) {
                $errors[] = 'Neplatná metoda podpisu.';
            }

            if ([] !== $errors) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }

                return $this->render('public/order_accept.html.twig', [
                    'order' => $order,
                    'storage' => $storage,
                    'storageType' => $storageType,
                    'place' => $place,
                ]);
            }

            /** @var SigningMethod $signingMethod */
            $typedName = $request->request->getString('typed_name') ?: null;
            $styleId = $request->request->getString('style_id') ?: null;

            $this->commandBus->dispatch(new SignOrderCommand(
                order: $order,
                signatureDataUrl: $signatureData,
                signingMethod: $signingMethod,
                typedName: $typedName,
                styleId: $styleId,
            ));

            $this->commandBus->dispatch(new AcceptOrderTermsCommand($order));

            $this->addFlash('success', 'Smluvní podmínky byly přijaty a smlouva podepsána. Pokračujte k platbě.');

            return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
        }

        return $this->render('public/order_accept.html.twig', [
            'order' => $order,
            'storage' => $storage,
            'storageType' => $storageType,
            'place' => $place,
        ]);
    }
}
