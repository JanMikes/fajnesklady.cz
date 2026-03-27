<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\AdminCreateOnboardingCommand;
use App\Entity\Order;
use App\Form\AdminCreateOnboardingFormData;
use App\Form\AdminCreateOnboardingFormType;
use App\Repository\StorageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/onboarding/digital', name: 'admin_onboarding_digital')]
#[IsGranted('ROLE_ADMIN')]
final class AdminCreateOnboardingController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly StorageRepository $storageRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $formData = new AdminCreateOnboardingFormData();
        $form = $this->createForm(AdminCreateOnboardingFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var string $storageId */
                $storageId = $formData->storageId;
                $storage = $this->storageRepository->get(Uuid::fromString($storageId));

                /** @var \DateTimeImmutable $startDate */
                $startDate = $formData->startDate;

                $envelope = $this->commandBus->dispatch(new AdminCreateOnboardingCommand(
                    email: $formData->email,
                    firstName: $formData->firstName,
                    lastName: $formData->lastName,
                    phone: $formData->phone,
                    birthDate: $formData->birthDate,
                    companyName: $formData->invoiceToCompany ? $formData->companyName : null,
                    companyId: $formData->invoiceToCompany ? $formData->companyId : null,
                    companyVatId: $formData->invoiceToCompany ? $formData->companyVatId : null,
                    billingStreet: $formData->billingStreet,
                    billingCity: $formData->billingCity,
                    billingPostalCode: $formData->billingPostalCode,
                    storage: $storage,
                    storageType: $storage->storageType,
                    place: $storage->place,
                    rentalType: $formData->rentalType,
                    startDate: $startDate,
                    endDate: $formData->endDate,
                    paymentMethod: $formData->paymentMethod,
                ));

                $handledStamp = $envelope->last(HandledStamp::class);
                $order = $handledStamp?->getResult();

                if ($order instanceof Order && null !== $order->signingToken) {
                    $signingUrl = $this->generateUrl(
                        'public_customer_signing',
                        ['token' => $order->signingToken],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    );

                    $this->addFlash('success', sprintf(
                        'Onboarding pro %s %s byl vytvořen. Odkaz k podpisu byl odeslán na %s.',
                        $formData->firstName,
                        $formData->lastName,
                        $formData->email,
                    ));
                    $this->addFlash('info', sprintf('Odkaz k podpisu: %s', $signingUrl));

                    return $this->redirectToRoute('admin_onboarding');
                }
            } catch (\Exception $e) {
                $this->logger->error('Digital onboarding creation failed', ['exception' => $e]);
                $this->addFlash('error', 'Při vytváření onboardingu došlo k chybě: '.$e->getMessage());
            }
        }

        return $this->render('admin/onboarding/digital.html.twig', [
            'form' => $form,
        ]);
    }
}
