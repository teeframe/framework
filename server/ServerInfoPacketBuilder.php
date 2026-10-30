<?php

namespace TeeFrame\Server;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\GameConstants;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\NetworkParams;
use TeeFrame\Network\Packets\ConnectionlessPacket;

class ServerInfoPacketBuilder
{
    /**
     * @param  non-empty-array<AbstractWorld>  $worlds
     * @param  ConnectionSlot[]  $connections
     * @return list<ConnectionlessPacket>
     */
    public function build(array $worlds, array $connections, string $name, bool $passwordProtected, int $token): array
    {
        $maxClients = 0;
        $maxPlayers = 0;

        foreach ($worlds as $world) {
            $slotsLimit = $world->getSlotsLimit();

            $maxClients += $slotsLimit;
            $maxPlayers += $slotsLimit - $world->getGameController()->getSpectatorSlots();
        }

        $clients    = [];
        $numPlayers = 0;
        foreach ($connections as $slot) {
            if (! in_array($slot->world(), $worlds, true)
                || $slot->state < ConnectionSlot::STATE_CONNECTING
                || $slot->state > ConnectionSlot::STATE_INGAME
            ) {
                continue;
            }

            $clients[] = $slot;
            if ($slot->playerTee()->team !== GameConstants::TEAM_SPECTATORS) {
                $numPlayers++;
            }
        }

        $world      = reset($worlds);
        $multiWorld = count($worlds) > 1;
        $packets    = [];
        $packetNo   = 0;

        // Server-info numbers are decimal strings, not packed integers.
        $payload = NetworkMessages::SERVERBROWSE_INFO_EXTENDED.$this->encodeFields([
            $token,
            '0.6 TeeFrame',
            substr($name, 0, 256),
            $multiWorld ? 'MultiWorld' : substr($world->getMap()->getName(), 0, 32),
            $multiWorld ? 0 : $world->getMap()->getCrc(),
            $multiWorld ? 0 : $world->getMap()->getSize(),
            $multiWorld ? 'MW' : substr($world->getGameController()->getGameType(), 0, 16),
            (int) $passwordProtected,
            $numPlayers,
            max($numPlayers, $maxPlayers),
            count($clients),
            $maxClients,
            '', // Reserved server info.
        ]);

        foreach ($clients as $slot) {
            $tee    = $slot->playerTee();
            $inGame = $slot->state === ConnectionSlot::STATE_INGAME;

            $clientPayload = $this->encodeFields([
                $inGame ? substr($tee->name, 0, NetworkParams::MAX_NAME_LENGTH) : '(connecting)',
                $inGame ? substr($tee->clan, 0, NetworkParams::MAX_CLAN_LENGTH) : '',
                $tee->country,
                $tee->score,
                (int) ($tee->team !== GameConstants::TEAM_SPECTATORS),
                '', // Reserved client info.
            ]);

            if (strlen($payload) + strlen($clientPayload) + NetworkParams::PACKET_HEADER_SIZE_CONNECTION_LESS > NetworkParams::MAXIMUM_PACKET_SIZE) {
                $packets[] = new ConnectionlessPacket($payload);
                $payload   = NetworkMessages::SERVERBROWSE_INFO_EXTENDED_MORE
                    .$this->encodeFields([$token, ++$packetNo, '']);
            }

            $payload .= $clientPayload;
        }

        $packets[] = new ConnectionlessPacket($payload);

        return $packets;
    }

    /** @param array<string|int> $fields */
    protected function encodeFields(array $fields): string
    {
        return implode("\0", array_map(
            fn (string|int $field): string => explode("\0", (string) $field, 2)[0],
            $fields,
        ))."\0";
    }
}
