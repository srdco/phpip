<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\AutheliaAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Socialite\Facades\Socialite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutheliaLoginTest extends TestCase
{
    use DatabaseTransactions;

    private MockHandler $http;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.authelia' => [
            'enabled' => true,
            'base_url' => 'https://auth.example.test',
            'client_id' => 'phpip-test',
            'client_secret' => 'test-only-secret',
            'redirect' => 'https://phpip.example.test/auth/authelia/callback',
            'auto_provision' => true,
            'default_role' => 'DBRO',
        ]]);

        $this->http = new MockHandler;
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->requests));
        $client = new Client(['handler' => $stack]);
        $manager = Socialite::getFacadeRoot();
        // Resolve a fresh real provider with each request, as in PHP-FPM. Only
        // network transport is replaced; state, PKCE and token exchange are real.
        Socialite::shouldReceive('driver')->with('authelia')->andReturnUsing(function () use ($manager, $client) {
            $manager->forgetDrivers();

            return $manager->driver('authelia')->setHttpClient($client);
        });
    }

    public function test_password_login_remains_available_when_authelia_is_disabled(): void
    {
        config(['services.authelia.enabled' => false]);
        $this->get('/login')->assertOk()->assertDontSee('Sign in with Authelia');
        $this->get('/auth/authelia')->assertNotFound();
        $this->get('/auth/authelia/callback')->assertNotFound();
        $this->post('/login', ['login' => 'phpipuser', 'password' => 'changeme'])->assertRedirect('/home');
        $this->assertAuthenticatedAs(User::where('login', 'phpipuser')->firstOrFail());
    }

    public function test_sign_in_option_uses_state_and_s256_pkce_with_minimal_scopes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sign in with Authelia');
        $response = $this->get('/auth/authelia')->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('https://auth.example.test/api/oidc/authorization?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(session('state'), $query['state']);
        $expected = rtrim(strtr(base64_encode(hash('sha256', session('code_verifier'), true)), '+/', '-_'), '=');
        $this->assertSame($expected, $query['code_challenge']);
    }

    public function test_enabled_but_incomplete_or_insecure_configuration_fails_closed(): void
    {
        config(['services.authelia.client_secret' => '']);
        $this->get('/auth/authelia')->assertStatus(503);
        config(['services.authelia.client_secret' => 'test', 'services.authelia.base_url' => 'http://auth.example.test']);
        $this->get('/auth/authelia')->assertStatus(503);
    }

    public function test_authorized_user_is_provisioned_without_taking_over_matching_username_or_groups(): void
    {
        $this->identity('subject-1', ['preferred_username' => 'phpipuser', 'groups' => ['DBA']]);
        $state = $this->startSignIn();
        $verifier = session('code_verifier');
        $sessionId = session()->getId();
        $this->withSession(['url.intended' => '/matter'])->get($this->callbackUrl($state))->assertRedirect('/matter');

        $user = auth()->user();
        $this->assertSame('DBRO', $user->default_role);
        $this->assertNotSame('phpipuser', $user->login);
        $this->assertSame(16, strlen($user->login));
        $this->assertNull($user->password);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertDatabaseHas('authelia_accounts', ['subject' => 'subject-1', 'actor_id' => $user->id]);
        $this->assertSame('https://auth.example.test/api/oidc/token', (string) $this->requests[0]['request']->getUri());
        parse_str((string) $this->requests[0]['request']->getBody(), $tokenRequest);
        $this->assertSame($verifier, $tokenRequest['code_verifier']);
        $this->assertSame('test-only-secret', $tokenRequest['client_secret']);
        $this->assertSame('authorization_code', $tokenRequest['grant_type']);
        $this->assertSame('Bearer test-access-token', $this->requests[1]['request']->getHeaderLine('Authorization'));
        $this->assertFalse(session()->has('state'));
        $this->assertFalse(session()->has('code_verifier'));
        $this->assertFalse(session()->has('authelia_started_at'));
    }

    public function test_linked_existing_account_keeps_its_role_and_scope(): void
    {
        $actor = Actor::create(['name' => 'Client', 'login' => 'sso-client', 'default_role' => 'CLI', 'company_id' => 1]);
        $this->link('client-subject', $actor->id);
        $this->identity('client-subject', ['groups' => ['DBA']]);
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/home');
        $this->assertAuthenticatedAs(User::findOrFail($actor->id));
        $this->assertSame('CLI', auth()->user()->default_role);
        $this->assertSame(1, auth()->user()->clientCompanyId());
    }

    public function test_existing_subject_is_reused_without_changing_permissions(): void
    {
        $user = User::where('login', 'phpipuser')->firstOrFail();
        $this->link('existing-subject', $user->id);
        $count = Actor::count();
        config(['services.authelia.default_role' => 'CLI']);
        $this->identity('existing-subject');
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('DBA', auth()->user()->default_role);
        $this->assertSame($count, Actor::count());
    }

    public function test_provisioning_can_be_disabled_for_unlinked_accounts(): void
    {
        config(['services.authelia.auto_provision' => false]);
        $this->identity('not-linked');
        $count = Actor::count();
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/login')->assertSessionHasErrors('authelia');
        $this->assertGuest();
        $this->assertSame($count, Actor::count());
    }

    #[DataProvider('provisioningRoles')]
    public function test_default_role_is_configurable(string $role): void
    {
        config(['services.authelia.default_role' => $role]);
        $this->identity('new-subject');
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/home');
        $this->assertSame($role, auth()->user()->default_role);
    }

    public static function provisioningRoles(): array
    {
        return [['DBRW'], ['CLI']];
    }

    public function test_a_callback_cannot_be_replayed(): void
    {
        config(['services.authelia.auto_provision' => false]);
        $this->identity('not-linked');
        $state = $this->startSignIn();
        $this->get($this->callbackUrl($state))->assertRedirect('/login');
        $this->get($this->callbackUrl($state))->assertRedirect('/login');
        $this->assertCount(2, $this->requests);
        $this->assertGuest();
    }

    public function test_a_link_to_another_issuer_cannot_be_used(): void
    {
        config(['services.authelia.auto_provision' => false]);
        $this->link('same-subject', User::where('login', 'phpipuser')->firstOrFail()->id, 'https://other.example.test');
        $this->identity('same-subject');
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/login')->assertSessionHasErrors('authelia');
        $this->assertGuest();
    }

    public function test_subject_matching_is_case_sensitive(): void
    {
        config(['services.authelia.auto_provision' => false]);
        $this->link('Subject', User::where('login', 'phpipuser')->firstOrFail()->id);
        $this->identity('subject');
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_removed_local_login_does_not_get_reprovisioned(): void
    {
        $actor = Actor::create(['name' => 'Removed', 'default_role' => 'DBRO']);
        $this->link('removed-subject', $actor->id);
        $this->identity('removed-subject');
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/login')->assertSessionHasErrors('authelia');
        $this->assertGuest();
    }

    public function test_invalid_state_is_rejected_before_any_network_request(): void
    {
        $this->startSignIn();
        $this->get($this->callbackUrl('wrong-state'))->assertRedirect('/login')->assertSessionHasErrors('authelia');
        $this->assertGuest();
        $this->assertSame([], $this->requests);
    }

    public function test_unsolicited_expired_and_cancelled_callbacks_are_rejected(): void
    {
        $this->get($this->callbackUrl('unsolicited'))->assertRedirect('/login');
        $state = $this->startSignIn();
        $this->withSession(['authelia_started_at' => time() - 601])->get($this->callbackUrl($state))->assertRedirect('/login');
        $state = $this->startSignIn();
        $this->get('/auth/authelia/callback?error=access_denied&state='.$state)->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame([], $this->requests);
    }

    public function test_provider_denial_and_network_errors_do_not_leak_secrets(): void
    {
        $this->http->append(new Response(400, [], 'private-upstream-detail test-only-secret'));
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertDontSee('private-upstream-detail')->assertDontSee('test-only-secret');
        $this->http->append(new ConnectException('connection failed', new GuzzleRequest('POST', 'https://auth.example.test/api/oidc/token')));
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/login')->assertSessionHasErrors('authelia');
        $this->assertGuest();
    }

    public function test_malformed_userinfo_cannot_authenticate(): void
    {
        $this->http->append(new Response(200, [], json_encode(['access_token' => 'test-access-token'])), new Response(200, [], '{broken-json'));
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/login')->assertSessionHasErrors('authelia');
        $this->assertGuest();
    }

    public function test_new_accounts_cannot_be_auto_provisioned_as_administrators(): void
    {
        config(['services.authelia.default_role' => 'DBA']);
        $this->identity('new-admin');
        $this->get($this->callbackUrl($this->startSignIn()))->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseMissing('authelia_accounts', ['subject' => 'new-admin']);
    }

    public function test_link_command_is_idempotent_and_refuses_reassignment(): void
    {
        $this->artisan('authelia:link phpipuser command-subject')->assertSuccessful();
        $this->artisan('authelia:link phpipuser command-subject')->assertSuccessful();
        Actor::create(['name' => 'Other', 'login' => 'other-login']);
        $this->artisan('authelia:link other-login command-subject')->assertFailed();
        $this->artisan('authelia:link phpipuser command-subject --unlink')->assertSuccessful();
        $this->assertDatabaseMissing('authelia_accounts', ['subject' => 'command-subject']);
    }

    private function startSignIn(): string
    {
        $this->get('/auth/authelia')->assertRedirect();

        return session('state');
    }

    private function callbackUrl(string $state): string
    {
        return '/auth/authelia/callback?'.http_build_query(['code' => 'test-code', 'state' => $state]);
    }

    private function identity(string $subject, array $claims = []): void
    {
        $this->http->append(
            new Response(200, [], json_encode(['access_token' => 'test-access-token', 'token_type' => 'Bearer'])),
            new Response(200, [], json_encode($claims + ['sub' => $subject, 'preferred_username' => 'someone', 'name' => 'SSO User'])),
        );
    }

    private function link(string $subject, int $actorId, string $issuer = 'https://auth.example.test'): void
    {
        AutheliaAccount::create(['issuer' => $issuer, 'subject' => $subject, 'actor_id' => $actorId]);
    }
}
