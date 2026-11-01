<?php

namespace TeeFrame\Server\Master;

use Swoole\Coroutine;
use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Network\Packets\ConnectionlessPacket;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\ServerInfoPacketBuilder;
use TeeFrame\Server\Sockets\AbstractSocket;

class MasterServer
{
    /** @var array<int, MasterServerRegistration> */
    protected array $registrations = [];
    protected int $nextInfoUpdate  = 0;
    protected bool $stopped        = false;

    public function __construct(
        protected TickHandler $tickHandler,

        protected MasterServerHttpClient $httpClient = new MasterServerHttpClient,
    ) {}

    /** @param AbstractSocket[] $sockets */
    public function start(array $sockets): void
    {
        // One identity lets the master group this server's IPv4/IPv6 addresses.
        $secret = MasterServerRegistration::createSecret();
        foreach ($sockets as $socket) {
            $this->registrations[spl_object_id($socket)] = new MasterServerRegistration(
                tickHandler: $this->tickHandler,
                port: $socket->port,
                family: str_contains($socket->host, ':') ? AF_INET6 : AF_INET,

                httpClient: $this->httpClient,
                localAddress: $socket->host,
                secret: $secret,
            );
        }
    }

    /**
     * @param  AbstractWorld[]  $worlds
     * @param  ConnectionSlot[]  $connections
     */
    public function update(array $worlds, array $connections, string $name, bool $passwordProtected): void
    {
        if ($this->stopped || $this->registrations === [] || $worlds === []) {
            return;
        }

        $info = null;
        if ($this->tickHandler->getTime() >= $this->nextInfoUpdate) {
            $info = json_encode((new ServerInfoPacketBuilder)->buildInfo(
                $worlds,
                $connections,
                $name,
                $passwordProtected,
            ), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $this->nextInfoUpdate = $this->tickHandler->getTime() + 1_000_000_000;
        }

        foreach ($this->registrations as $registration) {
            $registration->update($info);
        }
    }

    public function onPacket(ConnectionlessPacket $packet, AbstractSocket $socket): bool
    {
        return ($this->registrations[spl_object_id($socket)] ?? null)?->onPacket($packet) ?? false;
    }

    public function onWorkerStop(AbstractSocket $socket): void
    {
        if (! $this->stopped) {
            Coroutine\run(function () use ($socket): void {
                $this->registrations[spl_object_id($socket)]->shutdown();
            });
        }
    }

    public function shutdown(): void
    {
        $this->stopped = true;
        foreach ($this->registrations as $registration) {
            $registration->shutdown();
        }
    }
}
