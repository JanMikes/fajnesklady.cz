<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Command\CustomerSignOnboardingCommand;
use App\Entity\Order;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Enum\SigningMethod;
use App\Repository\OrderRepository;
use App\Service\Order\CustomerBillingSituation;
use App\Service\Order\SigningPriceViewModel;
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
        private readonly ClockInterface $clock,
        private readonly PriceCalculator $priceCalculator,
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

        if ($request->isMethod('POST')) {
            return $this->handlePost($request, $order, $now);
        }

        return $this->renderForm($order, self::emptySubmitted(), $now);
    }

    private function handlePost(Request $request, Order $order, \DateTimeImmutable $now): Response
    {
        // Idempotency: if the order is already signed (e.g. a duplicate POST that beat the
        // token-clearing of the first one), don't re-process — route straight to the next step.
        if ($order->hasSignature()) {
            return $this->redirectAfterSigning($order);
        }

        $context = $this->computeContext($order, $now);

        $accepted = $request->request->getBoolean('accept_contract');
        $signatureData = $request->request->getString('signature_data');
        $signingMethodValue = $request->request->getString('signing_method');
        $signatureConsent = $request->request->getBoolean('signature_consent');
        $acceptVop = $request->request->getBoolean('accept_vop');
        $acceptOperatingRules = $request->request->getBoolean('accept_operating_rules');
        $acceptConsumerNotice = $request->request->getBoolean('accept_consumer_notice');
        $acceptGdpr = $request->request->getBoolean('accept_gdpr');
        $acceptRecurringPayments = $request->request->getBoolean('accept_recurring_payments');
        $acceptEarlyStartWaiver = $request->request->getBoolean('accept_early_start_waiver');
        $signingPlace = trim($request->request->getString('signing_place'));

        $errors = [];
        if (!$order->hasUploadedContract() && !$accepted) {
            $errors[] = 'Pro pokračování je nutné souhlasit se smluvními podmínkami.';
        }
        if (!$acceptVop) {
            $errors[] = 'Pro pokračování je nutné souhlasit s všeobecnými obchodními podmínkami.';
        }
        if ($context['requiresOperatingRules'] && !$acceptOperatingRules) {
            $errors[] = 'Pro pokračování je nutné souhlasit s provozním řádem.';
        }
        if (!$acceptConsumerNotice) {
            $errors[] = 'Pro pokračování je nutné souhlasit s poučením o právech spotřebitele.';
        }
        if (!$acceptGdpr) {
            $errors[] = 'Pro pokračování je nutné souhlasit se zpracováním osobních údajů.';
        }
        // MANUAL_RECURRING customers are not consenting to a stored-card charge — the
        // dedicated checkbox is hidden for them, so only AUTO requires it.
        if ($context['showRecurringConsent'] && !$acceptRecurringPayments) {
            $errors[] = 'Pro pokračování je nutné souhlasit s podmínkami opakovaných plateb.';
        }
        if ($context['requiresEarlyStartWaiver'] && !$acceptEarlyStartWaiver) {
            $errors[] = 'Pro pokračování je nutné souhlasit se vzdáním se práva na odstoupení od smlouvy ve 14denní lhůtě.';
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

            $submitted = [
                'signingPlace' => $signingPlace,
                'acceptAll' => ($order->hasUploadedContract() || $accepted) && $acceptVop && $acceptConsumerNotice
                    && $acceptGdpr && $signatureConsent
                    && (!$context['requiresOperatingRules'] || $acceptOperatingRules)
                    && (!$context['requiresEarlyStartWaiver'] || $acceptEarlyStartWaiver),
                'acceptRecurring' => $acceptRecurringPayments,
            ];

            return $this->renderForm($order, $submitted, $now);
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
                signerIpAddress: $request->getClientIp(),
                signerUserAgent: substr((string) $request->headers->get('User-Agent', ''), 0, 500) ?: null,
            ));

            return $this->redirectAfterSigning($order);
        } catch (\Exception $e) {
            $this->logger->error('Customer signing failed', ['exception' => $e]);
            $this->addFlash('error', 'Při podpisu smlouvy došlo k chybě. Zkuste to prosím znovu.');

            return $this->renderForm($order, self::emptySubmitted(), $now);
        }
    }

    /**
     * @param array{signingPlace: string, acceptAll: bool, acceptRecurring: bool} $submitted
     */
    private function renderForm(Order $order, array $submitted, \DateTimeImmutable $now): Response
    {
        $context = $this->computeContext($order, $now);

        return $this->render('public/customer_signing.html.twig', [
            'order' => $order,
            'storage' => $order->storage,
            'storageType' => $order->storage->storageType,
            'place' => $order->storage->getPlace(),
            'priceViewModel' => SigningPriceViewModel::fromOrder($order),
            'isRecurring' => $context['isRecurring'],
            'showRecurringConsent' => $context['showRecurringConsent'],
            'showManualInfo' => $context['showManualInfo'],
            'showUpfrontTrancheInfo' => $context['showUpfrontTrancheInfo'],
            'showPaymentLogos' => $context['showPaymentLogos'],
            'requiresOperatingRules' => $context['requiresOperatingRules'],
            'requiresEarlyStartWaiver' => $context['requiresEarlyStartWaiver'],
            'paymentSchedule' => $context['paymentSchedule'],
            'recurringPaymentLegalMaxInCzk' => intdiv(PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER, 100),
            'submitted' => $submitted,
        ]);
    }

    /**
     * Single source of truth for the situation-aware show/validate gates, shared by
     * the render path and the POST validation so display and validation never drift.
     *
     * @return array{
     *     isRecurring: bool,
     *     showRecurringConsent: bool,
     *     showManualInfo: bool,
     *     showUpfrontTrancheInfo: bool,
     *     showPaymentLogos: bool,
     *     requiresOperatingRules: bool,
     *     requiresEarlyStartWaiver: bool,
     *     paymentSchedule: \App\Value\PaymentSchedule|null,
     * }
     */
    private function computeContext(Order $order, \DateTimeImmutable $now): array
    {
        // GOPAY_FIRST_CHARGE means "the customer pays the first charge through us"
        // (GoPay or bank transfer) — as opposed to externally-prepaid / free, which
        // have no payment step and therefore no logos / recurring consent.
        $isPayFlow = CustomerBillingSituation::GOPAY_FIRST_CHARGE === CustomerBillingSituation::fromOrder($order);
        $isRecurring = $order->isRecurring();

        return [
            'isRecurring' => $isRecurring,
            'showRecurringConsent' => $isPayFlow && $isRecurring && BillingMode::AUTO_RECURRING === $order->billingMode,
            'showManualInfo' => $isPayFlow && $isRecurring && BillingMode::MANUAL_RECURRING === $order->billingMode,
            // Spec 078 tranches: > 12-month upfront — first payment now, further
            // tranches via payment-request e-mails; tell the customer pre-signature.
            'showUpfrontTrancheInfo' => $isPayFlow && $order->isPaidInUpfrontTranches(),
            'showPaymentLogos' => $isPayFlow && PaymentMethod::GOPAY === $order->paymentMethod,
            'requiresOperatingRules' => null !== $order->storage->getPlace()->operatingRulesPath,
            'requiresEarlyStartWaiver' => !$order->hasUploadedContract()
                && $order->startDate < $now->setTime(0, 0, 0)->modify('+14 days'),
            'paymentSchedule' => $isRecurring ? $this->priceCalculator->buildScheduleFromOrder($order) : null,
        ];
    }

    /**
     * @return array{signingPlace: string, acceptAll: bool, acceptRecurring: bool}
     */
    private static function emptySubmitted(): array
    {
        return ['signingPlace' => '', 'acceptAll' => false, 'acceptRecurring' => false];
    }

    /**
     * Next step after a (possibly already-completed) signing: external/free orders go to the
     * completion page, orders with outstanding debt to the debt payment, the rest to payment.
     */
    private function redirectAfterSigning(Order $order): Response
    {
        if (PaymentMethod::EXTERNAL === $order->paymentMethod) {
            return $this->redirectToRoute('public_customer_signing_complete', ['id' => $order->id]);
        }

        if ($order->hasUnpaidDebt()) {
            return $this->redirectToRoute('public_order_debt_payment', ['id' => $order->id]);
        }

        return $this->redirectToRoute('public_order_payment', ['id' => $order->id]);
    }
}
