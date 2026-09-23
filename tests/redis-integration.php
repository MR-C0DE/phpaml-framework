<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/ScopeCleanupInterface.php';
require dirname(__DIR__) . '/src/Session/SessionStoreInterface.php';
require dirname(__DIR__) . '/src/Session/IdentifiedSessionStoreInterface.php';
require dirname(__DIR__) . '/src/Session/RedisSessionStore.php';

use PHPAML\Session\RedisSessionStore;

if (!class_exists(Redis::class)) {
    fwrite(STDERR, "SKIP: l'extension redis n'est pas installée.\n");
    exit(0);
}

$redis = new Redis();
$host = getenv('PHPAML_REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PHPAML_REDIS_PORT') ?: 6379);
if (!$redis->connect($host, $port, 2.0)) {
    fwrite(STDERR, "Impossible de joindre Redis sur {$host}:{$port}.\n");
    exit(1);
}

$prefix = 'phpaml:integration:' . bin2hex(random_bytes(8)) . ':';
$sessionId = RedisSessionStore::generateId();
$first = new RedisSessionStore($redis, $sessionId, 30, $prefix);
$second = new RedisSessionStore($redis, $sessionId, 30, $prefix);
$oldRedisKey = $prefix . hash('sha256', $sessionId);

try {
    $first->set('user', ['id' => 42]);
    $second->set('locale', 'fr');

    if ($first->get('locale') !== 'fr' || $second->get('user') !== ['id' => 42]) {
        throw new RuntimeException('Deux écrivains ont perdu un champ de session Redis.');
    }
    if ($redis->ttl($oldRedisKey) < 1) {
        throw new RuntimeException('La durée de vie Redis n’a pas été appliquée.');
    }

    $first->regenerate();
    $newRedisKey = $prefix . hash('sha256', $first->id());
    if ($first->id() === $sessionId || $redis->exists($oldRedisKey) !== 0 || $redis->exists($newRedisKey) !== 1) {
        throw new RuntimeException('La rotation de l’identifiant Redis est incomplète.');
    }
    if ($first->get('user') !== ['id' => 42] || $first->get('locale') !== 'fr') {
        throw new RuntimeException('La rotation a perdu des données de session.');
    }

    $first->remove('locale');
    if ($first->get('locale', 'missing') !== 'missing') {
        throw new RuntimeException('La suppression de champ Redis a échoué.');
    }

    echo "redis session integration: OK\n";
} finally {
    foreach ($redis->keys($prefix . '*') as $key) {
        $redis->del($key);
    }
    $redis->close();
}
