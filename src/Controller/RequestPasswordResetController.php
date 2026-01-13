<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\RequestPasswordResetCommand;
use App\Form\RequestPasswordResetFormData;
use App\Form\RequestPasswordResetFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/reset-password/request', name: 'app_request_password_reset')]
final class RequestPasswordResetController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'limiter.password_reset')]
        private readonly RateLimiterFactoryInterface $passwordResetLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $form = $this->createForm(RequestPasswordResetFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check rate limit
            $limiter = $this->passwordResetLimiter->create($request->getClientIp() ?? 'unknown');
            if (false === $limiter->consume(1)->isAccepted()) {
                throw new TooManyRequestsHttpException(null, 'Příliš mnoho pokusů o obnovení hesla. Zkuste to prosím později.');
            }

            /** @var RequestPasswordResetFormData $formData */
            $formData = $form->getData();

            $command = new RequestPasswordResetCommand(email: $formData->email);
            $this->commandBus->dispatch($command);

            // Always show success message (security: don't reveal if email exists)
            $this->addFlash('success', 'Pokud účet s tímto emailem existuje, obdržíte odkaz pro obnovení hesla.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('user/reset_password/request.html.twig', [
            'form' => $form,
        ]);
    }
}
