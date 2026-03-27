<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Command\AdminMigrateCustomerCommand;
use App\Entity\Contract;
use App\Form\AdminMigrateCustomerFormData;
use App\Form\AdminMigrateCustomerFormType;
use App\Repository\StorageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal/admin/onboarding/migrate', name: 'admin_onboarding_migrate')]
#[IsGranted('ROLE_ADMIN')]
final class AdminMigrateCustomerController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly StorageRepository $storageRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $formData = new AdminMigrateCustomerFormData();
        $form = $this->createForm(AdminMigrateCustomerFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var string $storageId */
                $storageId = $formData->storageId;
                $storage = $this->storageRepository->get(Uuid::fromString($storageId));

                /** @var float $totalPriceInCzk */
                $totalPriceInCzk = $formData->totalPriceInCzk;
                $totalPriceInHalire = (int) round($totalPriceInCzk * 100);

                /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $uploadedFile */
                $uploadedFile = $formData->contractDocument;
                $tempPath = $uploadedFile->move(
                    sys_get_temp_dir(),
                    uniqid('contract_', true).'.'.$uploadedFile->guessExtension(),
                )->getPathname();

                /** @var \DateTimeImmutable $startDate */
                $startDate = $formData->startDate;

                /** @var \DateTimeImmutable $paidAt */
                $paidAt = $formData->paidAt;

                $envelope = $this->commandBus->dispatch(new AdminMigrateCustomerCommand(
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
                    contractDocumentPath: $tempPath,
                    totalPrice: $totalPriceInHalire,
                    paidAt: $paidAt,
                ));

                $handledStamp = $envelope->last(HandledStamp::class);
                $contract = $handledStamp?->getResult();

                if ($contract instanceof Contract) {
                    $this->addFlash('success', sprintf(
                        'Zákazník %s %s byl úspěšně migrován. Smlouva vytvořena.',
                        $formData->firstName,
                        $formData->lastName,
                    ));

                    return $this->redirectToRoute('admin_orders_list');
                }
            } catch (\Exception $e) {
                $this->logger->error('Customer migration failed', ['exception' => $e]);
                $this->addFlash('error', 'Při migraci zákazníka došlo k chybě: '.$e->getMessage());
            }
        }

        return $this->render('admin/onboarding/migrate.html.twig', [
            'form' => $form,
        ]);
    }
}
