<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

/**
 * Renders the custom error templates (templates/bundles/TwigBundle/Exception/)
 * directly through Twig. They cannot be exercised via WebTestCase: under
 * PHPUnit the kernel runs in CLI runtime mode, so RuntimeModeErrorRendererSelector
 * picks CliErrorRenderer and the Twig templates are skipped entirely. A broken
 * path() in one of them (e.g. the former app_profile link) therefore only blows
 * up in production, turning every 404 into a 500.
 */
class ErrorPageTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = static::getContainer()->get('test.twig');

        // base.html.twig reads app.request.uri; error pages render within a request.
        static::getContainer()->get('request_stack')->push(Request::create('/tato-stranka-neexistuje'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function errorTemplateProvider(): iterable
    {
        yield 'generic' => ['@Twig/Exception/error.html.twig'];
        yield '403' => ['@Twig/Exception/error403.html.twig'];
        yield '404' => ['@Twig/Exception/error404.html.twig'];
        yield '500' => ['@Twig/Exception/error500.html.twig'];
    }

    #[DataProvider('errorTemplateProvider')]
    public function testErrorTemplateRendersForAnonymousUser(string $template): void
    {
        $html = $this->twig->render($template, $this->errorContext());

        $this->assertStringContainsString('href="/"', $html);
    }

    public function testNotFoundTemplateLinksProfileForAuthenticatedUser(): void
    {
        $user = static::getContainer()->get('doctrine')->getManager()
            ->getRepository(User::class)->findOneBy(['email' => 'user@example.com']);
        \assert($user instanceof User);
        static::getContainer()->get('security.untracked_token_storage')
            ->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $html = $this->twig->render('@Twig/Exception/error404.html.twig', $this->errorContext());

        $this->assertStringContainsString('/portal/profile', $html);
    }

    /**
     * @return array{exception: FlattenException, status_code: int, status_text: string}
     */
    private function errorContext(): array
    {
        return [
            'exception' => FlattenException::createFromThrowable(new NotFoundHttpException()),
            'status_code' => 404,
            'status_text' => 'Not Found',
        ];
    }
}
