<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\RegisterUserCommand;
use App\Form\RegistrationFormData;
use App\Form\RegistrationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'limiter.registration')]
        private readonly RateLimiterFactoryInterface $registrationLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check rate limit
            $limiter = $this->registrationLimiter->create($request->getClientIp() ?? 'unknown');
            if (false === $limiter->consume(1)->isAccepted()) {
                throw new TooManyRequestsHttpException(null, 'Příliš mnoho pokusů o registraci. Zkuste to prosím později.');
            }

            /** @var RegistrationFormData $formData */
            $formData = $form->getData();

            try {
                $command = new RegisterUserCommand(
                    email: $formData->email,
                    password: $formData->password,
                    firstName: $formData->firstName,
                    lastName: $formData->lastName,
                    companyName: $formData->isCompany ? $formData->companyName : null,
                    companyId: $formData->isCompany ? $formData->companyId : null,
                    companyVatId: $formData->isCompany ? $formData->companyVatId : null,
                    billingStreet: $formData->isCompany ? $formData->billingStreet : null,
                    billingCity: $formData->isCompany ? $formData->billingCity : null,
                    billingPostalCode: $formData->isCompany ? $formData->billingPostalCode : null,
                );

                $this->commandBus->dispatch($command);

                $this->addFlash('success', 'Registrace proběhla úspěšně! Zkontrolujte prosím svůj email pro ověření účtu.');

                return $this->redirectToRoute('app_verify_email_confirmation');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            } catch (\Exception $e) {
                $this->addFlash('error', 'Při registraci došlo k chybě. Zkuste to prosím znovu.');
            }
        }

        return $this->render('user/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
