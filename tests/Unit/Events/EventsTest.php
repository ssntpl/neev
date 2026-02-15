<?php

namespace Ssntpl\Neev\Tests\Unit\Events;

use Illuminate\Support\Facades\Event;
use Ssntpl\Neev\Events\LoggedInEvent;
use Ssntpl\Neev\Events\LoggedOutEvent;
use Ssntpl\Neev\Tests\TestCase;

class EventsTest extends TestCase
{
    // -----------------------------------------------------------------
    // LoggedInEvent
    // -----------------------------------------------------------------

    public function test_logged_in_event_stores_user_property(): void
    {
        $user = (object) ['id' => 1, 'name' => 'John'];

        $event = new LoggedInEvent($user);

        $this->assertSame($user, $event->user);
    }

    public function test_logged_in_event_user_property_is_public(): void
    {
        $user = (object) ['id' => 42];

        $event = new LoggedInEvent($user);

        $reflection = new \ReflectionClass($event);
        $property = $reflection->getProperty('user');

        $this->assertTrue($property->isPublic());
    }

    public function test_logged_in_event_is_dispatchable(): void
    {
        Event::fake();

        $user = (object) ['id' => 1];

        LoggedInEvent::dispatch($user);

        Event::assertDispatched(LoggedInEvent::class, function ($event) use ($user) {
            return $event->user === $user;
        });
    }

    // -----------------------------------------------------------------
    // LoggedOutEvent
    // -----------------------------------------------------------------

    public function test_logged_out_event_stores_user_property(): void
    {
        $user = (object) ['id' => 2, 'name' => 'Jane'];

        $event = new LoggedOutEvent($user);

        $this->assertSame($user, $event->user);
    }

    public function test_logged_out_event_user_property_is_public(): void
    {
        $user = (object) ['id' => 99];

        $event = new LoggedOutEvent($user);

        $reflection = new \ReflectionClass($event);
        $property = $reflection->getProperty('user');

        $this->assertTrue($property->isPublic());
    }

    public function test_logged_out_event_is_dispatchable(): void
    {
        Event::fake();

        $user = (object) ['id' => 2];

        LoggedOutEvent::dispatch($user);

        Event::assertDispatched(LoggedOutEvent::class, function ($event) use ($user) {
            return $event->user === $user;
        });
    }

    // -----------------------------------------------------------------
    // Both events can be dispatched independently
    // -----------------------------------------------------------------

    public function test_both_events_can_be_dispatched_independently(): void
    {
        Event::fake();

        $userA = (object) ['id' => 1];
        $userB = (object) ['id' => 2];

        LoggedInEvent::dispatch($userA);
        LoggedOutEvent::dispatch($userB);

        Event::assertDispatched(LoggedInEvent::class, 1);
        Event::assertDispatched(LoggedOutEvent::class, 1);
    }

    // -----------------------------------------------------------------
    // Events accept null user
    // -----------------------------------------------------------------

    public function test_logged_in_event_accepts_null_user(): void
    {
        $event = new LoggedInEvent(null);

        $this->assertNull($event->user);
    }

    public function test_logged_out_event_accepts_null_user(): void
    {
        $event = new LoggedOutEvent(null);

        $this->assertNull($event->user);
    }
}
