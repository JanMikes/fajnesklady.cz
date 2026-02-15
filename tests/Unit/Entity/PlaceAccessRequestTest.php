<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Place;
use App\Entity\PlaceAccessRequest;
use App\Entity\User;
use App\Enum\PlaceAccessRequestStatus;
use App\Event\PlaceAccessRequestApproved;
use App\Event\PlaceAccessRequestDenied;
use App\Event\PlaceAccessRequested;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class PlaceAccessRequestTest extends TestCase
{
    private function createUser(string $email = 'landlord@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testCreateRecordsPlaceAccessRequestedEvent(): void
    {
        $place = $this->createPlace();
        $user = $this->createUser();
        $now = new \DateTimeImmutable();

        $request = new PlaceAccessRequest(
            id: Uuid::v7(),
            place: $place,
            requestedBy: $user,
            message: 'Please grant me access.',
            createdAt: $now,
        );

        $this->assertSame(PlaceAccessRequestStatus::PENDING, $request->status);
        $this->assertSame($place, $request->place);
        $this->assertSame($user, $request->requestedBy);
        $this->assertSame('Please grant me access.', $request->message);
        $this->assertNull($request->reviewedBy);
        $this->assertNull($request->reviewedAt);

        $events = $request->popEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PlaceAccessRequested::class, $events[0]);
        $this->assertSame($place->id, $events[0]->placeId);
        $this->assertSame($user->id, $events[0]->requestedById);
    }

    public function testCreateWithNullMessage(): void
    {
        $request = new PlaceAccessRequest(
            id: Uuid::v7(),
            place: $this->createPlace(),
            requestedBy: $this->createUser(),
            message: null,
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertNull($request->message);
    }

    public function testApproveChangesStatusAndRecordsEvent(): void
    {
        $request = new PlaceAccessRequest(
            id: Uuid::v7(),
            place: $this->createPlace(),
            requestedBy: $this->createUser(),
            message: null,
            createdAt: new \DateTimeImmutable(),
        );
        $request->popEvents(); // Clear creation event

        $admin = $this->createUser('admin@example.com');
        $now = new \DateTimeImmutable();

        $request->approve($admin, $now);

        $this->assertSame(PlaceAccessRequestStatus::APPROVED, $request->status);
        $this->assertSame($admin, $request->reviewedBy);
        $this->assertSame($now, $request->reviewedAt);

        $events = $request->popEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PlaceAccessRequestApproved::class, $events[0]);
        $this->assertSame($request->place->id, $events[0]->placeId);
        $this->assertSame($request->requestedBy->id, $events[0]->landlordId);
    }

    public function testDenyChangesStatusAndRecordsEvent(): void
    {
        $request = new PlaceAccessRequest(
            id: Uuid::v7(),
            place: $this->createPlace(),
            requestedBy: $this->createUser(),
            message: null,
            createdAt: new \DateTimeImmutable(),
        );
        $request->popEvents(); // Clear creation event

        $admin = $this->createUser('admin@example.com');
        $now = new \DateTimeImmutable();

        $request->deny($admin, $now);

        $this->assertSame(PlaceAccessRequestStatus::DENIED, $request->status);
        $this->assertSame($admin, $request->reviewedBy);
        $this->assertSame($now, $request->reviewedAt);

        $events = $request->popEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PlaceAccessRequestDenied::class, $events[0]);
        $this->assertSame($request->place->id, $events[0]->placeId);
        $this->assertSame($request->requestedBy->id, $events[0]->landlordId);
    }
}
