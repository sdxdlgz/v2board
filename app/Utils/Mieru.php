<?php

namespace App\Utils;

class Mieru
{
    public static function username($uuid)
    {
        return 'u' . str_replace('-', '', $uuid);
    }

    public static function uri($uuid, array $server)
    {
        $params = http_build_query([
            'profile' => $server['name'],
            'port' => (int) $server['port'],
            'protocol' => 'TCP',
        ], '', '&', PHP_QUERY_RFC3986);
        $auth = rawurlencode(self::username($uuid)) . ':' . rawurlencode($uuid);
        return 'mierus://' . $auth . '@' . Helper::formatHost($server['host']) . '?' . $params . "\r\n";
    }

    public static function mihomo($uuid, array $server)
    {
        return [
            'name' => $server['name'],
            'type' => 'mieru',
            'server' => $server['host'],
            'port' => (int) $server['port'],
            'username' => self::username($uuid),
            'password' => $uuid,
            'transport' => 'TCP',
            'udp' => true,
        ];
    }
}
