<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\RegisterLandlordCommand;
use App\Form\LandlordRegistrationFormData;
use App\Form\LandlordRegistrationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/registrace-pronajimatele', name: 'app_landlord_register', methods: ['GET', 'POST'])]
final class LandlordRegisterController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'limiter.registration')]
        private readonly RateLimiterFactoryInterface $registrationLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(LandlordRegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $this->registrationLimiter->create($request->getClientIp() ?? 'unknown');
            if (false === $limiter->consume(1)->isAccepted()) {
                throw new TooManyRequestsHttpException(null, 'Příliš mnoho pokusů o registraci. Zkuste to prosím později.');
            }

            /** @var LandlordRegistrationFormData $formData */
            $formData = $form->getData();

            try {
                $command = new RegisterLandlordCommand(
                    email: $formData->email,
                    password: $formData->password,
                    firstName: $formData->firstName,
                    lastName: $formData->lastName,
                    phone: $formData->phone,
                    companyId: $formData->companyId,
                    companyName: $formData->companyName,
                    companyVatId: $formData->companyVatId,
                    billingStreet: $formData->billingStreet,
                    billingCity: $formData->billingCity,
                    billingPostalCode: $formData->billingPostalCode,
                );

                $this->commandBus->dispatch($command);

                $this->addFlash('success', 'Registrace proběhla úspěšně! Váš účet bude brzy ověřen naším týmem.');

                return $this->redirectToRoute('app_landlord_awaiting_verification');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (\Exception $e) {
                $this->addFlash('error', 'Při registraci došlo k chybě. Zkuste to prosím znovu.');
            }
        }

        return $this->render('user/landlord_register.html.twig', [
            'form' => $form,
        ]);
    }
}
