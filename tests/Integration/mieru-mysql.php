<?php

// Explicit, isolated MySQL/MariaDB test. Never loads the application's .env.
if (PHP_SAPI !== 'cli' || count($argv) < 3) {
    throw new RuntimeException('Usage: php mieru-mysql.php VENDOR_AUTOLOAD PRIVATE_TEST_CONFIG [worker]');
}
$root = dirname(__DIR__, 2);
$config = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
if (($config['host'] ?? '') !== '127.0.0.1' || ($config['port'] ?? 0) < 1024 || $config['port'] == 3306 ||
    !preg_match('/^codex_mieru_trial_[a-f0-9]{12}$/', $config['database'] ?? '')) {
    throw new RuntimeException('Requires an explicit loopback port and disposable trial database');
}
$loader = require $argv[1];
$loader->setClassMapAuthoritative(false);
$loader->addPsr4('App\\', $root . '/app', true);
// Production may use an optimized classmap; candidate classes must take precedence.
$classes = [
    'App\\Console\\Commands\\TrafficUpdate', 'App\\Console\\Commands\\MieruSetup', 'App\\Console\\Commands\\MieruCreate',
    'App\\Jobs\\StatUserJob', 'App\\Jobs\\StatServerJob', 'App\\Services\\MieruTrafficService',
    'App\\Models\\User', 'App\\Models\\ServerV2node', 'App\\Models\\StatUser', 'App\\Models\\StatServer',
];
$map = [];
foreach ($classes as $class) $map[$class] = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
$loader->addClassMap($map);

$app = new Illuminate\Foundation\Application($root);
$capsule = new Illuminate\Database\Capsule\Manager($app);
$capsule->addConnection([
    'driver' => 'mysql', 'host' => $config['host'], 'port' => $config['port'],
    'database' => $config['database'], 'username' => $config['username'], 'password' => $config['password'],
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_general_ci', 'prefix' => '', 'strict' => true,
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$app->instance('db', $capsule->getDatabaseManager());
$app->bind('db.schema', function () use ($capsule) { return $capsule->getConnection()->getSchemaBuilder(); });
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$db = $capsule->getConnection();
$agentId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$iterations = 32;
$mode = $argv[3] ?? 'main';

function checkValue($actual, $expected, $label)
{
    if ((int) $actual !== $expected) throw new RuntimeException($label . ': expected ' . $expected . ', got ' . $actual);
}

if ($mode === 'mieru' || $mode === 'legacy') {
    $app->instance('redis', new class {
        public function exists($key) { return false; }
        public function hgetall($key) { return [1 => $key === 'v2board_upload_traffic' ? '10' : '20']; }
        public function del($key) { return 1; }
    });
    $deadline = microtime(true) + 15;
    while (!is_file($config['barrier'])) {
        if (microtime(true) > $deadline) throw new RuntimeException('Worker barrier timed out');
        usleep(1000);
    }
    for ($i = 1; $i <= $iterations; $i++) {
        if ($mode === 'mieru') {
            $report = [['id' => 1, 'u' => $i * 100, 'd' => $i * 200]];
            $service = new App\Services\MieruTrafficService();
            $service->record(1, $agentId, $report);
            $service->record(1, $agentId, $report); // Lost-response retry must add no usage.
        } else {
            (new App\Console\Commands\TrafficUpdate())->handle();
            (new App\Jobs\StatUserJob([1 => [10, 20]], ['id' => 2, 'rate' => '1.00'], 'v2node'))->handle();
            (new App\Jobs\StatServerJob([1 => [10, 20]], ['id' => 2, 'rate' => '1.00'], 'v2node'))->handle();
        }
        usleep(500);
    }
    echo $mode . " worker passed\n";
    exit(0);
}
if ($mode !== 'main') throw new RuntimeException('Unknown worker mode');
if ($db->select('SHOW TABLES')) throw new RuntimeException('Trial database must be empty');
$ddl = json_decode(file_get_contents($config['schema_file']), true, 512, JSON_THROW_ON_ERROR);
foreach (['v2_user', 'v2_server_v2node', 'v2_stat_user', 'v2_stat_server'] as $table) {
    if (!isset($ddl[$table]) || strpos($ddl[$table], 'CREATE TABLE `' . $table . '`') !== 0) {
        throw new RuntimeException('Invalid isolated schema input');
    }
    $db->statement($ddl[$table]);
}
$setup = new App\Console\Commands\MieruSetup();
$setup->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\BufferedOutput()));
$setup->handle();
$db->table('v2_user')->insert([
    'id' => 1, 'email' => 'trial@example.invalid', 'password' => 'test-only',
    'uuid' => '11111111-2222-4333-8444-555555555555', 'token' => str_repeat('a', 32),
    'group_id' => 1, 'transfer_enable' => 9000000000000, 'created_at' => time(), 'updated_at' => time(),
]);
$translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
$app->instance('validator', new Illuminate\Validation\Factory($translator, $app));
$create = new App\Console\Commands\MieruCreate();
$create->setLaravel($app);
$create->run(new Symfony\Component\Console\Input\ArrayInput([
    'name' => 'Isolated trial', 'host' => '127.0.0.1', 'port' => '16201', '--group' => ['1'],
]), new Symfony\Component\Console\Output\BufferedOutput());
$node = App\Models\ServerV2node::firstOrFail();
checkValue($node->id, 1, 'New isolated node ID');
checkValue($node->show, 0, 'New node hidden by default');
checkValue($node->up_mbps, 0, 'Required upload bandwidth field');
checkValue($node->down_mbps, 0, 'Required download bandwidth field');
foreach ($classes as $class) {
    if (realpath((new ReflectionClass($class))->getFileName()) !== realpath($map[$class])) {
        throw new RuntimeException('Loaded production class instead of candidate class');
    }
}

$processes = [];
foreach (['mieru', 'legacy'] as $worker) {
    $command = [PHP_BINARY, __FILE__, $argv[1], $argv[2], $worker];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated worker');
    fclose($pipes[0]);
    $processes[] = [$process, $pipes, $worker];
}
file_put_contents($config['barrier'], 'start');
foreach ($processes as [$process, $pipes, $worker]) {
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($worker . ' failed: ' . $output . $error);
}
checkValue($db->table('v2_user')->value('u'), $iterations * 110, 'Concurrent upload billing');
checkValue($db->table('v2_user')->value('d'), $iterations * 220, 'Concurrent download billing');
checkValue($db->table('v2_stat_user')->sum('u'), $iterations * 110, 'Shared user statistics upload');
checkValue($db->table('v2_stat_user')->sum('d'), $iterations * 220, 'Shared user statistics download');
checkValue($db->table('v2_stat_server')->sum('u'), $iterations * 110, 'Server statistics upload');
checkValue($db->table('v2_mieru_counter')->value('u'), $iterations * 100, 'Cumulative checkpoint');

$service = new App\Services\MieruTrafficService();
$next = [['id' => 1, 'u' => ($iterations + 1) * 100, 'd' => ($iterations + 1) * 200]];
$db->unprepared("CREATE TRIGGER codex_abort_stat BEFORE UPDATE ON v2_stat_server FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected trial failure'");
try {
    $service->record(1, $agentId, $next);
    throw new RuntimeException('Expected injected database failure');
} catch (Illuminate\Database\QueryException $expected) {
    checkValue($db->table('v2_user')->value('u'), $iterations * 110, 'Rollback usage');
    checkValue($db->table('v2_stat_user')->sum('u'), $iterations * 110, 'Rollback statistics');
    checkValue($db->table('v2_mieru_counter')->value('u'), $iterations * 100, 'Rollback checkpoint');
} finally {
    $db->unprepared('DROP TRIGGER codex_abort_stat');
}
$service->record(1, $agentId, $next);
$service->record(1, $agentId, $next);
checkValue($db->table('v2_user')->value('u'), $iterations * 110 + 100, 'Retry after rollback');
try {
    $service->record(1, 'bbbbbbbb-bbbb-4ccc-8ddd-eeeeeeeeeeee', $next);
    throw new RuntimeException('Agent mismatch was accepted');
} catch (Symfony\Component\HttpKernel\Exception\HttpException $expected) {
    checkValue($expected->getStatusCode(), 409, 'Agent mismatch status');
}
$node->rate = '1.25'; $node->save();
$service->record(1, $agentId, [['id' => 1, 'u' => $next[0]['u'] + 1, 'd' => $next[0]['d'] + 2]]);
$service->record(1, $agentId, [['id' => 1, 'u' => $next[0]['u'] + 4, 'd' => $next[0]['d'] + 8]]);
checkValue($db->table('v2_user')->value('u'), $iterations * 110 + 105, 'Fractional rate upload');
checkValue($db->table('v2_user')->value('d'), $iterations * 220 + 210, 'Fractional rate download');
echo json_encode(['passed' => true, 'iterations_per_writer' => $iterations, 'competing_writers' => 2,
    'duplicate_reports' => true, 'rollback' => true, 'agent_binding' => true, 'fractional_rates' => true,
    'candidate_classes_verified' => true, 'create_command_verified' => true,
    'database_version' => $db->selectOne('SELECT VERSION() AS version')->version]) . "\n";
