<?php

namespace TeeFrame\Server\Master;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use TeeFrame\Core\TickHandler;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Server\Console;

class MasterServerRegistration
{
    public const URL = 'https://master1.ddnet.org/ddnet/15/register';

    protected string $secret;
    protected string $challengeSecret;
    protected ?string $challengeToken = null;

    protected string $info              = '';
    protected int $infoSerial           = -1;
    protected int $successfulInfoSerial = -1;

    protected int $nextRegisterTime    = 0;
    protected ?int $lastRegisterTime   = null;
    protected ?Channel $pendingRequest = null;

    protected string $status   = '';
    protected bool $registered = false;
    protected bool $stopped    = false;

    public function __construct(
        protected TickHandler $tickHandler,
        protected int $port,
        protected int $family,
        protected MasterServerHttpClient $httpClient = new MasterServerHttpClient,
        protected string $localAddress = '',
        ?string $secret = null,
    ) {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('The registration port must be between 1 and 65535');
        }

        if (! in_array($family, [AF_INET, AF_INET6], true)) {
            throw new \InvalidArgumentException('Registration requires an IPv4 or IPv6 socket');
        }

        $this->secret          = $secret ?? self::createSecret();
        $this->challengeSecret = self::createSecret();
    }

    public function update(?string $info = null): void
    {
        if ($this->stopped) {
            return;
        }

        $now = $this->tickHandler->getTime();

        if ($info !== null && $info !== $this->info) {
            $this->info = $info;
            $this->infoSerial++;

            if ($this->lastRegisterTime !== null) {
                $this->nextRegisterTime = min($this->nextRegisterTime, $this->lastRegisterTime + 1_000_000_000);
            }
        }

        if ($this->infoSerial >= 0 && $this->pendingRequest === null && $now >= $this->nextRegisterTime) {
            $this->sendRegister();
        }
    }

    public function onPacket(ConnectionlessPacket $packet): bool
    {
        $prefix = NetworkMessages::SERVERBROWSE_CHALLENGE.$this->challengeSecret.':';
        $data   = $packet->getData();

        if ($this->stopped || ! str_starts_with($data, $prefix)) {
            return false;
        }

        $fields = explode("\0", substr($data, strlen($prefix)));

        if (count($fields) !== 3 || $fields[0] !== $this->getProtocol() || $fields[2] !== ''
            || preg_match('/\A[\x21-\x7e]{1,127}\z/', $fields[1]) !== 1
        ) {
            return true;
        }

        $this->challengeToken = $fields[1];

        if ($this->status === 'need_challenge') {
            $this->nextRegisterTime = $this->tickHandler->getTime();
        }

        return true;
    }

    public function shutdown(): void
    {
        $this->stopped = true;

        if ($this->pendingRequest !== null) {
            // Its coroutine removes any accepted listing before signalling completion.
            $this->pendingRequest->pop(1.0);

            return;
        }

        $this->deleteIfRegistered();
    }

    protected function sendRegister(): void
    {
        $serial  = $this->infoSerial;
        $body    = $serial > $this->successfulInfoSerial ? $this->info : '';
        $token   = $this->challengeToken;
        $headers = [
            ...$this->getHeaders(),
            'Challenge-Secret' => $this->challengeSecret.':'.$this->getProtocol(),
            'Info-Serial'      => (string) $serial,
        ];

        if ($token !== null) {
            $headers['Challenge-Token'] = $token;
        }

        $this->lastRegisterTime = $this->tickHandler->getTime();
        $this->nextRegisterTime = $this->lastRegisterTime + 15_000_000_000;
        $this->pendingRequest   = $finished = new Channel(1);

        $coroutine = Coroutine::create(function () use ($headers, $body, $serial, $token, $finished): void {
            try {
                $response = $this->httpClient->post(self::URL, $this->family, $headers, $body, localAddress: $this->localAddress);
                $this->handleResponse($response, $serial, $token, $body !== '');
            } catch (\RuntimeException|\JsonException|\InvalidArgumentException $exception) {
                $this->status = 'error';
                Console::error('register', 'Master server '.$this->getProtocol().': '.$exception->getMessage());
            } finally {
                if ($this->stopped) {
                    $this->deleteIfRegistered();
                }

                $this->pendingRequest = null;
                $finished->push(true);
            }
        });

        if ($coroutine === false) {
            $this->pendingRequest = null;
            Console::error('register', 'Could not start master server registration');
        }
    }

    /** @param array{status: int, body: string} $response */
    protected function handleResponse(array $response, int $serial, ?string $sentToken, bool $sentInfo): void
    {
        $result = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($result) || ! isset($result['status'])
            || ! in_array($result['status'], ['success', 'need_challenge', 'need_info', 'error'], true)
        ) {
            throw new \UnexpectedValueException('Invalid registration response');
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || $result['status'] === 'error') {
            $message = isset($result['message']) && is_string($result['message']) ? $result['message'] : 'Registration request rejected';

            throw new \UnexpectedValueException('HTTP '.$response['status'].': '.$message);
        }

        if ($result['status'] === 'success') {
            if ($this->status !== 'success') {
                Console::info('register', 'Master server '.$this->getProtocol().': registered');
            }

            $this->registered           = true;
            $this->successfulInfoSerial = max($this->successfulInfoSerial, $serial);
        } elseif ($result['status'] === 'need_info') {
            if ($serial === $this->successfulInfoSerial) {
                $this->successfulInfoSerial--;
            }

            if (! $sentInfo) {
                $this->nextRegisterTime = $this->tickHandler->getTime();
            }
        } elseif ($result['status'] === 'need_challenge') {
            // The UDP challenge can arrive before the HTTP response.
            if ($this->challengeToken !== null && $this->challengeToken !== $sentToken) {
                $this->nextRegisterTime = $this->tickHandler->getTime();
            } elseif ($this->status === 'need_challenge') {
                Console::warn('register', 'Master server '.$this->getProtocol().': UDP port '.$this->port.' is not reachable; check the firewall and port forwarding');
            }
        }

        $this->status = $result['status'];
    }

    protected function deleteIfRegistered(): void
    {
        if (! $this->registered) {
            return;
        }

        $this->registered = false;

        try {
            $this->httpClient->post(
                self::URL,
                $this->family,
                ['Action' => 'delete', ...$this->getHeaders()],
                '',
                timeout: 1.0,
                localAddress: $this->localAddress,
            );
        } catch (\RuntimeException $exception) {
            Console::warn('register', 'Could not remove master server listing: '.$exception->getMessage());
        }
    }

    /** @return array<string, string> */
    protected function getHeaders(): array
    {
        return [
            'Address' => 'tw-0.6+udp://connecting-address.invalid:'.$this->port,
            'Secret'  => $this->secret,
        ];
    }

    protected function getProtocol(): string
    {
        return $this->family === AF_INET6 ? 'tw0.6/ipv6' : 'tw0.6/ipv4';
    }

    public static function createSecret(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex      = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
