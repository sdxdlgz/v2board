<?php

namespace Tests\Feature;

use App\Models\ServerTrojan;
use App\Models\ServerUpstream;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UpstreamTest extends TestCase
{
    private $prefix;
    private $admin;
    private $member;
    private const SOURCE = 'vless://11111111-2222-4333-8444-555555555555@upstream.example:443?security=reality&pbk=private-test-value&sni=example.com#trial';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (app('router')->getRoutes() as $route) {
            if (substr($route->uri(), -22) === '/server/upstream/fetch') $this->prefix = '/' . substr($route->uri(), 0, -5);
        }
        // Route prefix was registered before changing this test's encryption key.
        if (!$this->prefix) {
            foreach (app('router')->getRoutes() as $route) {
                if (strpos($route->uri(), '/server/upstream/fetch') !== false) $this->prefix = '/' . substr($route->uri(), 0, -5);
            }
        }
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'upstream.enabled' => true, 'cache.default' => 'array', 'app.key' => 'base64:' . base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
        app()->forgetInstance('encrypter');
        foreach (['v2_server_trojan', 'v2_server_vless'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->increments('id'); $t->string('name');
                foreach (['host', 'port', 'group_id', 'rate', 'network', 'server_name'] as $c) $t->string($c)->nullable();
                foreach (['parent_id', 'show', 'server_port', 'created_at', 'updated_at'] as $c) $t->integer($c)->nullable();
            });
        }
        Schema::create('v2_user', function (Blueprint $t) {
            $t->increments('id'); $t->string('email'); $t->string('token');
            $t->boolean('is_admin')->default(false); $t->boolean('is_staff')->default(false);
        });
        require_once base_path('database/migrations/2026_10_16_000001_create_server_upstreams_table.php');
        (new \CreateServerUpstreamsTable())->up();
        $this->admin = $this->auth(true, 'admin@example.invalid');
        $this->member = $this->auth(false, 'member@example.invalid');
        ServerTrojan::create(['name' => 'Gateway', 'host' => 'entry.example', 'port' => '443',
            'server_port' => 443, 'network' => 'tcp', 'group_id' => [1], 'rate' => '1.25', 'show' => 1]);
    }

    private function auth($admin, $email)
    {
        $user = new User(); $user->timestamps = false;
        $user->email = $email; $user->token = bin2hex(random_bytes(16)); $user->is_admin = $admin; $user->save();
        $data = (new AuthService($user))->generateAuthData(Request::create('/test'));
        return ['Authorization' => $data['auth_data']];
    }

    private function payload(array $extra = [])
    {
        return array_merge(['node_type' => 'trojan', 'node_id' => 1, 'source' => self::SOURCE,
            'selector' => '', 'refresh_interval' => 3600, 'enabled' => true], $extra);
    }

    private function createBinding(array $extra = [])
    {
        return $this->postJson($this->prefix . 'save', $this->payload($extra), $this->admin)
            ->assertOk()->json('data');
    }

    private function agent($action, $id = 1) { return '/api/v1/server/Upstream/' . $action . '?id=' . $id; }

    public function test_feature_is_opt_in_and_every_management_mutation_requires_admin()
    {
        config(['upstream.enabled' => false]);
        $this->getJson($this->prefix . 'fetch', $this->admin)->assertNotFound();
        config(['upstream.enabled' => true]);
        $this->flushHeaders()->getJson($this->prefix . 'fetch')->assertForbidden();
        foreach (['save', 'refresh', 'rotateToken', 'drop'] as $action) {
            $this->postJson($this->prefix . $action, $this->payload(), $this->member)->assertForbidden();
        }
        $this->assertSame(0, ServerUpstream::count());
    }

    public function test_import_encrypts_source_and_agent_is_scoped_to_one_binding()
    {
        $data = $this->createBinding();
        $this->assertSame(64, strlen($data['agent_token']));
        $this->assertArrayNotHasKey('source_encrypted', $data['item']);
        $item = ServerUpstream::first();
        $this->assertSame(self::SOURCE, Crypt::decryptString($item->source_encrypted));
        $this->assertNotSame($data['agent_token'], $item->agent_token_hash);
        $this->getJson($this->prefix . 'fetch', $this->admin)->assertDontSee('private-test-value')->assertDontSee($data['agent_token']);
        $headers = ['Authorization' => 'Bearer ' . $data['agent_token']];
        $this->getJson($this->agent('config'), $headers)->assertOk()->assertJsonPath('data.source', self::SOURCE);
        $this->getJson($this->agent('config', 2), $headers)->assertForbidden();
        $this->getJson($this->agent('config'), $this->member)->assertForbidden();
        $this->getJson($this->agent('config'), ['Authorization' => 'Bearer panel-global-token'])->assertForbidden();
        $this->postJson($this->agent('config'), [], $headers)->assertStatus(405);
    }

    public function test_update_keeps_secret_and_rejects_stale_reports()
    {
        $data = $this->createBinding(); $auth = ['Authorization' => 'Bearer ' . $data['agent_token']];
        $this->postJson($this->agent('report'), ['revision' => 1, 'applied_revision' => 1, 'status' => 'ready', 'protocol' => 'Vless'], $auth)->assertOk();
        $this->postJson($this->prefix . 'save', $this->payload(['id' => 1, 'source' => '', 'selector' => 'new-name']), $this->admin)->assertOk();
        $this->postJson($this->agent('report'), ['revision' => 1, 'applied_revision' => 1, 'status' => 'ready'], $auth)->assertStatus(409);
        $this->getJson($this->agent('config'), $auth)->assertJsonPath('data.source', self::SOURCE)->assertJsonPath('data.revision', 2);
        $this->postJson($this->agent('report'), ['revision' => 2, 'status' => 'error', 'applied_revision' => 1, 'error_code' => 'probe_failed'], $auth)->assertOk();
        $this->assertSame('error', ServerUpstream::first()->status);
        $this->postJson($this->agent('report'), ['revision' => 2, 'status' => 'ready', 'applied_revision' => 1], $auth)->assertStatus(422);
    }

    public function test_disable_rotation_and_delete_revoke_gateway_without_editing_entrance()
    {
        $before = ServerTrojan::first()->toArray();
        $data = $this->createBinding(); $auth = ['Authorization' => 'Bearer ' . $data['agent_token']];
        $this->postJson($this->prefix . 'drop', ['id' => 1], $this->admin)->assertStatus(422);
        $new = $this->postJson($this->prefix . 'rotateToken', ['id' => 1], $this->admin)->assertOk()->json('data.agent_token');
        $this->getJson($this->agent('config'), $auth)->assertForbidden();
        $auth = ['Authorization' => 'Bearer ' . $new];
        $this->postJson($this->prefix . 'save', $this->payload(['id' => 1, 'source' => '', 'enabled' => false]), $this->admin)->assertOk();
        $this->getJson($this->agent('config'), $auth)->assertJsonPath('data.enabled', false)->assertJsonPath('data.source', '');
        $this->postJson($this->prefix . 'drop', ['id' => 1], $this->admin)->assertOk();
        $this->getJson($this->agent('config'), $auth)->assertForbidden();
        $this->assertSame($before, ServerTrojan::first()->toArray());
    }

    public function test_invalid_input_duplicate_and_rebinding_are_rejected()
    {
        foreach (['http://example.com/sub', 'file:///etc/passwd', 'https://user:pass@example.com/sub', "vless://test\nsecret", 'unknown://node'] as $source) {
            $this->postJson($this->prefix . 'save', $this->payload(['source' => $source]), $this->admin)->assertStatus(422);
        }
        $this->postJson($this->prefix . 'save', $this->payload(['refresh_interval' => 1]), $this->admin)->assertStatus(422);
        $this->createBinding();
        $this->postJson($this->prefix . 'save', $this->payload(), $this->admin)->assertStatus(422);
        $this->postJson($this->prefix . 'save', $this->payload(['id' => 1, 'node_id' => 999]), $this->admin)->assertStatus(422);
        $this->assertSame(1, ServerUpstream::count());
    }

    public function test_refresh_increments_version_and_deleted_entrance_is_disabled()
    {
        $data = $this->createBinding(); $auth = ['Authorization' => 'Bearer ' . $data['agent_token']];
        $this->postJson($this->prefix . 'refresh', ['id' => 1], $this->admin)->assertOk();
        $this->getJson($this->agent('config'), $auth)->assertJsonPath('data.revision', 2);
        ServerTrojan::first()->delete();
        $this->getJson($this->agent('config'), $auth)->assertJsonPath('data.enabled', false)->assertJsonPath('data.source', '');
        $this->postJson($this->prefix . 'save', $this->payload(['id' => 1, 'source' => '', 'enabled' => false]), $this->admin)->assertOk();
        $this->postJson($this->prefix . 'drop', ['id' => 1], $this->admin)->assertOk();
        $this->getJson($this->agent('config'), $auth)->assertForbidden();
    }
}
