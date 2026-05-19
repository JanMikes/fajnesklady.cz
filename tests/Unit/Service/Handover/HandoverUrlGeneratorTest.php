<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Handover;

use App\Entity\HandoverProtocol;
use App\Service\Handover\HandoverUrlGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class HandoverUrlGeneratorTest extends TestCase
{
    public function testGeneratesSignedUrlThatRoundTripsThroughUriSigner(): void
    {
        $protocolId = Uuid::fromString('01927a1e-0000-7000-8000-000000000001');
        $unsigned = 'http://localhost/predavaci-protokol/'.$protocolId->toRfc4122();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                'public_handover_view',
                ['id' => $protocolId->toRfc4122()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn($unsigned);

        $uriSigner = new UriSigner('test-secret');
        $generator = new HandoverUrlGenerator($urlGenerator, $uriSigner);

        $protocol = $this->buildProtocolWithId($protocolId);

        $signed = $generator->generateTenantView($protocol);

        self::assertStringContainsString('/predavaci-protokol/'.$protocolId->toRfc4122(), $signed);
        self::assertStringContainsString('_hash=', $signed);
        self::assertTrue(
            $uriSigner->checkRequest(Request::create($signed)),
            'Signed URL must verify against UriSigner',
        );
    }

    private function buildProtocolWithId(Uuid $id): HandoverProtocol
    {
        $reflection = new \ReflectionClass(HandoverProtocol::class);
        /** @var HandoverProtocol $protocol */
        $protocol = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('id')->setValue($protocol, $id);

        return $protocol;
    }
}
