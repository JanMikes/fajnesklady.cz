<?php

declare(strict_types=1);

namespace App\Controller;

use App\Command\ResendVerificationEmailCommand;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/resend-verification-email', name: 'app_resend_verification_email', methods: ['POST'])]
final class ResendVerificationEmailController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'limiter.email_verification')]
        private readonly RateLimiterFactoryInterface $emailVerificationLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $email = $request->getSession()->get('unverified_user_email');

        if ($email === null) {
            $this->addFlash('error', 'Neplatný požadavek.');

            return $this->redirectToRoute('app_login');
        }

        $limiter = $this->emailVerificationLimiter->create($request->getClientIp() ?? 'unknown');
        if (false === $limiter->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Příliš mnoho pokusů. Zkuste to prosím později.');
        }

        $this->commandBus->dispatch(new ResendVerificationEmailCommand($email));

        // Clear the session value after use
        $request->getSession()->remove('unverified_user_email');

        $this->addFlash('success', 'Ověřovací email byl odeslán. Zkontrolujte svou schránku.');

        return $this->redirectToRoute('app_login');
    }
}
