<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RuleControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->be(User::where('login', 'phpipuser')->firstOrFail());
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_index()
    {
        // Main page with rules list
        $response = $this->call('GET', '/rule');

        $response->assertStatus(200)
            ->assertViewHas('ruleslist')
            ->assertSeeText('Draft By');

        // Filter on Task
        $response = $this->call('GET', '/rule?Task=na');
        $response->assertStatus(200)
            ->assertSeeText('National Phase')
            ->assertSeeText('Patent')
            ->assertSeeText('Filed')
            ->assertSeeText('WO')
            ->assertViewHas('ruleslist', fn ($rules) => $rules->contains(
                fn ($rule) => $rule->task === 'NPH' && $rule->for_country === 'WO'
            ));

        // A detailed page
        $response = $this->call('GET', '/rule/5');
        $response->assertStatus(200)
            ->assertViewIs('rule.show')
            ->assertViewHas('ruleInfo', fn ($rule) => $rule->id === 5)
            ->assertViewHas('ruleComments')
            ->assertSeeText('Triggered by');

        // Autocompletion
        $response = $this->call('GET', '/event-name/autocomplete/1?term=national');
        $response->assertStatus(200)
            ->assertJson([0 => [
                'key' => 'NPH',
                'value' => 'National Phase']]);
    }

    public function test_clients_cannot_browse_staff_rule_pages(): void
    {
        $actor = Actor::create(['name' => 'Client', 'login' => 'rule-test-cli', 'default_role' => 'CLI']);
        $this->be(User::findOrFail($actor->id));

        $this->get('/rule')->assertForbidden();
        $this->get('/rule/5')->assertForbidden();
    }
}
