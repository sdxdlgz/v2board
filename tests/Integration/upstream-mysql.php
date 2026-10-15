<?php

// Explicit disposable database only; never boot the application or load its .env.
if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    throw new RuntimeException('Usage: php upstream-mysql.php VENDOR_AUTOLOAD PRIVATE_TEST_CONFIG');
}
$root = dirname(__DIR__, 2);
$config = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
if (($config['host'] ?? '') !== '127.0.0.1' || ($config['port'] ?? 0) < 1024 || $config['port'] == 3306 ||
    !preg_match('/^codex_upstream_trial_[a-f0-9]{12}$/', $config['database'] ?? '')) {
    throw new RuntimeException('Requires an isolated loopback port and disposable database');
}
$loader = require $argv[1];
$map = [];
foreach (['Models/ServerUpstream', 'Models/ServerTrojan', 'Models/ServerVless', 'Services/UpstreamService'] as $file) {
    $map['App\\' . str_replace('/', '\\', $file)] = $root . '/app/' . $file . '.php';
}
$loader->addClassMap($map);
$app = new Illuminate\Foundation\Application($root);
$capsule = new Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection(array_merge($config, ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'prefix' => '', 'strict' => true]));
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->instance('db.schema', $capsule->schema());
$app->instance('encrypter', new Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC'));
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$db = $capsule->getConnection();
if ($db->select('SHOW TABLES')) throw new RuntimeException('Disposable database must be empty');
function verify($condition, $label) { if (!$condition) throw new RuntimeException($label); }

$schema = $capsule->schema();
$schema->create('v2_server_trojan', function ($t) {
    $t->increments('id'); $t->string('name'); $t->unsignedInteger('parent_id')->nullable();
    $t->unsignedInteger('created_at'); $t->unsignedInteger('updated_at');
});
$node = App\Models\ServerTrojan::create(['name' => 'Isolated gateway']);
$before = $node->fresh()->toArray();
require $root . '/database/migrations/2026_10_16_000001_create_server_upstreams_table.php';
$migration = new CreateServerUpstreamsTable();
$migration->up();
$source = 'vless://11111111-2222-4333-8444-555555555555@upstream.example:443#fixture';
$payload = ['node_type' => 'trojan', 'node_id' => $node->id, 'source' => $source, 'selector' => '', 'refresh_interval' => 3600, 'enabled' => true];
$service = new App\Services\UpstreamService();
$saved = $service->save($payload);
$item = $saved['item']->fresh();
verify($item->enabled === true && $item->revision === 1, 'Database casts');
verify(Illuminate\Support\Facades\Crypt::decryptString($item->source_encrypted) === $source, 'Encrypted source round trip');
verify(strpos($item->toJson(), $saved['agent_token']) === false && strpos($item->toJson(), 'source_encrypted') === false, 'Hidden credentials');
$service->save(array_merge($payload, ['id' => $item->id, 'source' => '', 'enabled' => false]));
verify($item->fresh()->revision === 2 && $item->fresh()->enabled === false, 'Transactional update');
App\Models\ServerUpstream::whereKey($item->id)->update(['revision' => Illuminate\Support\Facades\DB::raw('revision + 1')]);
verify($item->fresh()->revision === 3, 'Atomic revision increment');
verify(App\Models\ServerUpstream::whereKey($item->id)->where('revision', 2)->update(['status' => 'ready']) === 0, 'Stale report rejection');
try {
    $duplicate = $item->fresh()->replicate(); $duplicate->save();
    throw new RuntimeException('Unique entrance constraint missing');
} catch (Illuminate\Database\QueryException $e) {
    verify($e->getCode() === '23000', 'Expected duplicate-key failure');
}
verify(App\Models\ServerUpstream::count() === 1, 'Duplicate did not alter binding');
$migration->down();
verify(!$schema->hasTable('v2_server_upstream'), 'Scoped migration rollback');
verify(App\Models\ServerTrojan::find($node->id)->toArray() === $before, 'Existing entrance unchanged');
echo json_encode(['passed' => true, 'encrypted_storage' => true, 'revision_and_unique_constraints' => true, 'scoped_rollback' => true]) . "\n";
