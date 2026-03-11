<?php

namespace Ssntpl\Neev\Tests\Unit\Middleware;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Ssntpl\Neev\Http\Middleware\EnsureEmailIsVerified;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerifiedTest extends TestCase
{
    use RefreshDatabase;

    private EnsureEmailIsVerified $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new EnsureEmailIsVerified();

        Route::get('/verify', fn () => 'verify')->name('verification.notice');
    }

    private function buildRequest(string $path = '/dashboard', ?User $user = null, string $format = 'html'): Request
    {
        $server = [];
        if ($format === 'json') {
            $server['HTTP_ACCEPT'] = 'application/json';
        }

        $request = Request::create($path, 'GET', [], [], [], $server);
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function passThrough(): Closure
    {
        return fn (Request $request) => new Response('OK', 200);
    }

    public function test_passes_through_when_no_user(): void
    {
        $request = $this->buildRequest();

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_passes_through_when_email_is_verified(): void
    {
        $user = User::factory()->create();

        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_redirects_to_verification_notice_when_email_not_verified(): void
    {
        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/dashboard', $user);

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertTrue($response->isRedirection());
        $this->assertStringContainsString('/verify', $response->headers->get('Location'));
    }

    public function test_returns_403_json_when_email_not_verified_and_expects_json(): void
    {
        $user = User::factory()->create();
        $user->email->update(['verified_at' => null]);
        $user->refresh();

        $request = $this->buildRequest('/api/test', $user, 'json');

        $response = $this->middleware->handle($request, $this->passThrough());

        $this->assertEquals(403, $response->getStatusCode());
        $this->assertStringContainsString('Email not verified', $response->getContent());
    }
}
