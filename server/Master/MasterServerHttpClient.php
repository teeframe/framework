<?php

namespace TeeFrame\Server\Master;

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\System;

class MasterServerHttpClient
{
    /**
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}
     */
    public function post(string $url, int $family, array $headers, string $body, float $timeout = 5.0, string $localAddress = ''): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'], $parts['scheme'])
            || ! in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('The master server URL must be an HTTP or HTTPS URL without credentials or a fragment');
        }

        $host = trim($parts['host'], '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $address = $host;

            if (str_contains($address, ':') !== ($family === AF_INET6)) {
                throw new \RuntimeException('The master server address does not match the game socket address family');
            }
        } else {
            $addresses = System::getaddrinfo($host, $family, SOCK_STREAM, STREAM_IPPROTO_TCP, '', $timeout);

            if (! is_array($addresses) || $addresses === []) {
                throw new \RuntimeException('Could not resolve the master server address');
            }

            $address = $addresses[0];
        }

        $tls  = $parts['scheme'] === 'https';
        $port = $parts['port'] ?? ($tls ? 443 : 80);
        $path = $parts['path'] ?? '/';

        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        $client = new Client($address, $port, $tls);

        try {
            $settings = [
                'timeout'               => $timeout,
                'connect_timeout'       => $timeout,
                'ssl_verify_peer'       => true,
                'ssl_allow_self_signed' => false,
                'ssl_host_name'         => $host,
                'http_compression'      => false,
                'package_max_length'    => 64 * 1024,
            ];

            if ($localAddress !== '' && $localAddress !== '0.0.0.0' && $localAddress !== '::') {
                $settings['bind_address'] = $localAddress;
            }

            $client->set($settings);
            $client->setHeaders([
                'Host'         => $parts['host'].(isset($parts['port']) ? ':'.$port : ''),
                'Content-Type' => 'application/json',
                'Connection'   => 'close',
                ...$headers,
            ]);

            if (! $client->post($path, $body)) {
                throw new \RuntimeException('Master server request failed: '.$client->errMsg);
            }

            return ['status' => $client->statusCode, 'body' => $client->body];
        } finally {
            $client->close();
        }
    }
}
