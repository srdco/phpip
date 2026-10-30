<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ActorControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(SampleSeeder::class);
        $this->be(User::where('login', 'phpipuser')->firstOrFail());
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_index()
    {
        // Main page with actors list
        $response = $this->call('GET', '/actor?Name=Tesla');

        $response->assertStatus(200)
            ->assertViewHas('actorslist')
            ->assertSeeText('Tesla Motors Inc.');

        // A detailed page
        $response = $this->call('GET', '/actor/124');
        $response->assertStatus(200)
            ->assertViewIs('actor.show')
            ->assertViewHas('actorInfo', fn ($actor) => $actor->id === 124 && $actor->name === 'Tesla Motors Inc.')
            ->assertSee('value="Tesla Motors Inc."', false);

        // A page used-in
        $response = $this->call('GET', '/actor/124/usedin');
        $response->assertStatus(200)
            ->assertViewHas('matter_dependencies')
            ->assertViewHas('other_dependencies')
            ->assertSeeText('Matter Dependencies');

        // Autocompletion
        $response = $this->call('GET', '/actor/autocomplete?term=Tes');
        $response->assertStatus(200)
            ->assertJson([0 => [
                'key' => 124,
                'value' => 'Tesla']]);
    }

    public function test_clients_cannot_browse_staff_actor_pages(): void
    {
        $actor = Actor::create(['name' => 'Client', 'login' => 'actor-test-cli', 'default_role' => 'CLI']);
        $this->be(User::findOrFail($actor->id));

        $this->get('/actor')->assertForbidden();
        $this->get('/actor/124')->assertForbidden();
    }
}
