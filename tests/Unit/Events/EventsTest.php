<?php

namespace Ssntpl\Neev\Tests\Unit\Events;

use Illuminate\Support\Facades\Event;
use Ssntpl\Neev\Events\LoggedIn;
use Ssntpl\Neev\Events\LoggedOut;
use Ssntpl\Neev\Tests\TestCase;

class EventsTest extends TestCase
{
    // -----------------------------------------------------------------
    // LoggedIn
    // -----------------------------------------------------------------

    public function test_logged_in_event_stores_user_property(): void
    {
        $user = (object) ['id' => 1, 'name' => 'John'];

        $event = new LoggedIn($user);

        $this->assertSame($user, $event->user);
    }

    public function test_logged_in_event_user_property_is_public(): void
    {
        $user = (object) ['id' => 42];

        $event = new LoggedIn($user);

        $reflection = new \ReflectionClass($event);
        $property = $reflection->getProperty('user');

        $this->assertTrue($property->isPublic());
    }

    public function test_logged_in_event_is_dispatchable(): void
    {
        Event::fake();

        $user = (object) ['id' => 1];

        LoggedIn::dispatch($user);

        Event::assertDispatched(LoggedIn::class, function ($event) use ($user) {
            return $event->user === $user;
        });
    }

    // -----------------------------------------------------------------
    // LoggedOut
    // -----------------------------------------------------------------

    public function test_logged_out_event_stores_user_property(): void
    {
        $user = (object) ['id' => 2, 'name' => 'Jane'];

        $event = new LoggedOut($user);

        $this->assertSame($user, $event->user);
    }

    public function test_logged_out_event_user_property_is_public(): void
    {
        $user = (object) ['id' => 99];

        $event = new LoggedOut($user);

        $reflection = new \ReflectionClass($event);
        $property = $reflection->getProperty('user');

        $this->assertTrue($property->isPublic());
    }

    public function test_logged_out_event_is_dispatchable(): void
    {
        Event::fake();

        $user = (object) ['id' => 2];

        LoggedOut::dispatch($user);

        Event::assertDispatched(LoggedOut::class, function ($event) use ($user) {
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

        LoggedIn::dispatch($userA);
        LoggedOut::dispatch($userB);

        Event::assertDispatched(LoggedIn::class, 1);
        Event::assertDispatched(LoggedOut::class, 1);
    }

    // -----------------------------------------------------------------
    // Events accept null user
    // -----------------------------------------------------------------

    public function test_logged_in_event_accepts_null_user(): void
    {
        $event = new LoggedIn(null);

        $this->assertNull($event->user);
    }

    public function test_logged_out_event_accepts_null_user(): void
    {
        $event = new LoggedOut(null);

        $this->assertNull($event->user);
    }
}
