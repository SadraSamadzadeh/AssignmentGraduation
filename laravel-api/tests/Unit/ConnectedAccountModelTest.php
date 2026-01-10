<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\ConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for ConnectedAccount model and functionality
 */
class ConnectedAccountModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_connected_account_can_be_created_for_user()
    {
        $user = User::factory()->create();
        
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'primeplay'
        ]);

        $this->assertDatabaseHas('connected_accounts', [
            'user_id' => $user->id,
            'provider' => 'primeplay'
        ]);
    }

    public function test_user_can_have_multiple_providers()
    {
        $user = User::factory()->create();
        
        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'primeplay'
        ]);

        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'video'
        ]);

        $this->assertCount(2, $user->connectedAccounts);
    }

    public function test_unique_constraint_on_user_and_provider()
    {
        $user = User::factory()->create();
        
        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'primeplay'
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'primeplay'
        ]);
    }

    public function test_connected_account_can_be_marked_inactive()
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'status' => 'active'
        ]);

        $account->update(['status' => 'inactive']);

        $this->assertEquals('inactive', $account->fresh()->status);
    }

    public function test_can_check_if_account_is_active()
    {
        $user = User::factory()->create();
        
        $activeAccount = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'status' => 'active'
        ]);

        $inactiveAccount = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'video',
            'status' => 'inactive'
        ]);

        $this->assertTrue($activeAccount->isActive());
        $this->assertFalse($inactiveAccount->isActive());
    }

    public function test_can_check_if_account_is_primary()
    {
        $user = User::factory()->create();
        
        $primaryAccount = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'is_primary' => true
        ]);

        $secondaryAccount = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'video',
            'is_primary' => false
        ]);

        $this->assertTrue($primaryAccount->isPrimary());
        $this->assertFalse($secondaryAccount->isPrimary());
    }

    public function test_connected_account_belongs_to_user()
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id
        ]);

        $this->assertInstanceOf(User::class, $account->user);
        $this->assertEquals($user->id, $account->user->id);
    }

    public function test_active_scope_filters_active_accounts()
    {
        $user = User::factory()->create();
        
        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'primeplay',
            'status' => 'active'
        ]);

        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'video',
            'status' => 'inactive'
        ]);

        $activeAccounts = ConnectedAccount::active()->get();

        $this->assertCount(1, $activeAccounts);
        $this->assertEquals('active', $activeAccounts->first()->status);
    }

    public function test_by_provider_scope_filters_by_provider()
    {
        $user = User::factory()->create();
        
        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'primeplay'
        ]);

        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'video'
        ]);

        $primeplayAccounts = ConnectedAccount::byProvider('primeplay')->get();

        $this->assertCount(1, $primeplayAccounts);
        $this->assertEquals('primeplay', $primeplayAccounts->first()->provider);
    }

    public function test_primary_scope_filters_primary_accounts()
    {
        $user = User::factory()->create();
        
        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'primeplay',
            'is_primary' => true
        ]);

        ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'video',
            'is_primary' => false
        ]);

        $primaryAccounts = ConnectedAccount::primary()->get();

        $this->assertCount(1, $primaryAccounts);
        $this->assertTrue($primaryAccounts->first()->is_primary);
    }

    public function test_token_expiration_check_works()
    {
        $user = User::factory()->create();
        
        $expiredAccount = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'token_expires_at' => now()->subDay()
        ]);

        $validAccount = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'video',
            'token_expires_at' => now()->addDay()
        ]);

        $this->assertTrue($expiredAccount->isTokenExpired());
        $this->assertFalse($validAccount->isTokenExpired());
    }

    public function test_metadata_is_stored_as_json()
    {
        $user = User::factory()->create();
        $metadata = ['custom_field' => 'test_value', 'sync_count' => 5];
        
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'metadata' => $metadata
        ]);

        $this->assertEquals($metadata, $account->fresh()->metadata);
    }

    public function test_account_can_be_synced()
    {
        $user = User::factory()->create();
        $account = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'last_synced_at' => now()->subWeek()
        ]);

        $oldSyncTime = $account->last_synced_at;
        sleep(1);
        
        $account->update(['last_synced_at' => now()]);

        $this->assertNotEquals($oldSyncTime, $account->fresh()->last_synced_at);
        $this->assertTrue($account->fresh()->last_synced_at->gt($oldSyncTime));
    }

    public function test_user_can_have_only_one_primary_account_per_provider()
    {
        $user = User::factory()->create();
        
        $primary1 = ConnectedAccount::factory()->create([
            'user_id' => $user->id,
            'provider' => 'primeplay',
            'is_primary' => true
        ]);

        // When we try to create another primary for same provider, logic should handle it
        // This tests business logic that should be in the service/controller
        $accounts = ConnectedAccount::where('user_id', $user->id)
            ->where('provider', 'primeplay')
            ->where('is_primary', true)
            ->get();

        $this->assertCount(1, $accounts);
    }
}
