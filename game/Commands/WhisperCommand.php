<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\Game\SvChatChunk;

class WhisperCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'whisper';
    }

    public function getPattern(): string
    {
        return '/^\/(?:w|whisper)\s+(?:"([^"]+)"|(\S+))\s+(.+)$/s';
    }

    /**
     * @param  string[]  $matches
     */
    public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void
    {
        $targetName = $matches[1] !== '' ? $matches[1] : $matches[2];
        $whisperMsg = $matches[3];

        $targetTee = null;
        foreach ($world->getTees() as $t) {
            if (strcasecmp($t->name, $targetName) === 0) {
                $targetTee = $t;
                break;
            }
        }

        $server = $world->getServer();

        if ($targetTee === null) {
            $server->sendToTee($world, $tee->teeIndex, new SvChatChunk(
                team: 0,
                clientId: -1,
                text: "Player '{$targetName}' not found",
            ));

            return;
        }

        $server->sendToTee($world, $targetTee->teeIndex, new SvChatChunk(
            team: 2,
            clientId: $tee->teeIndex,
            text: $whisperMsg,
        ));

        $server->sendToTee($world, $tee->teeIndex, new SvChatChunk(
            team: 3,
            clientId: $tee->teeIndex,
            text: $whisperMsg,
        ));
    }
}