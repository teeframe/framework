<?php

namespace TeeFrame\Server;

use TeeFrame\Core\Utf8;
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
        $info     = $this->buildInfo($worlds, $connections, $name, $passwordProtected);
        $packets  = [];
        $packetNo = 0;

        // Server-info numbers are decimal strings, not packed integers.
        $payload = NetworkMessages::SERVERBROWSE_INFO_EXTENDED.$this->encodeFields([
            $token,
            $info['version'],
            $info['name'],
            $info['map']['name'],
            $info['map']['crc'],
            $info['map']['size'],
            $info['game_type'],
            (int) $info['passworded'],
            $info['num_players'],
            $info['max_players'],
            count($info['clients']),
            $info['max_clients'],
            '', // Reserved server info.
        ]);

        foreach ($info['clients'] as $client) {
            $clientPayload = $this->encodeFields([
                $client['name'],
                $client['clan'],
                $client['country'],
                $client['score'],
                (int) $client['is_player'],
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

    /**
     * @param  non-empty-array<AbstractWorld>  $worlds
     * @param  ConnectionSlot[]  $connections
     * @return array{
     *     max_clients: int, max_players: int, num_players: int, passworded: bool,
     *     game_type: string, name: string, map: array{name: string, crc: int, size: int},
     *     version: string, client_score_kind: string, requires_login: bool,
     *     clients: list<array{name: string, clan: string, country: int, score: int, is_player: bool}>
     * }
     */
    public function buildInfo(array $worlds, array $connections, string $name, bool $passwordProtected): array
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

            $tee    = $slot->playerTee();
            $inGame = $slot->state === ConnectionSlot::STATE_INGAME;

            $clients[] = [
                'name'      => $inGame ? Utf8::truncateBytes($tee->name, NetworkParams::MAX_NAME_LENGTH) : '(connecting)',
                'clan'      => $inGame ? Utf8::truncateBytes($tee->clan, NetworkParams::MAX_CLAN_LENGTH) : '',
                'country'   => $tee->country,
                'score'     => $tee->score,
                'is_player' => $tee->team !== GameConstants::TEAM_SPECTATORS,
            ];

            if ($tee->team !== GameConstants::TEAM_SPECTATORS) {
                $numPlayers++;
            }
        }

        $world      = reset($worlds);
        $multiWorld = count($worlds) > 1;

        return [
            'max_clients' => $maxClients,
            'max_players' => max($numPlayers, $maxPlayers),
            'num_players' => $numPlayers,
            'passworded'  => $passwordProtected,
            'game_type'   => $multiWorld ? 'MW' : Utf8::truncateBytes($world->getGameController()->getGameType(), 16),
            'name'        => Utf8::truncateBytes($name, 256),
            'map'         => [
                'name' => $multiWorld ? 'MultiWorld' : Utf8::truncateBytes($world->getMap()->getName(), 32),
                'crc'  => $multiWorld ? 0 : $world->getMap()->getCrc(),
                'size' => $multiWorld ? 0 : $world->getMap()->getSize(),
            ],
            'version'           => '0.6 TeeFrame',
            'client_score_kind' => 'points',
            'requires_login'    => false,
            'clients'           => $clients,
        ];
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
