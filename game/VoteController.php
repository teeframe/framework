<?php

namespace TeeFrame\Game;

use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\SvVoteClearOptionsChunk;

class VoteController
{
    /**
     * @return AbstractChunk[]
     */
    public function getInitialVoteChunks(AbstractTee $requestingTee): array
    {
        return [
            new SvVoteClearOptionsChunk,
            ...$this->getVoteChunks($requestingTee)
        ];
    }

    /**
     * @return AbstractChunk[]
     */
    public function getVoteChunks(AbstractTee $requestingTee): array
    {
        return []; // TODO: CNetMsg_Sv_VoteOptionListAdd OptionMsg
    }

    public function getRunningVote(AbstractTee $requestingTee): ?AbstractChunk // TODO: Type this with the correct chunk
    {
        return null; // TODO: CGameContext::SendVoteSet(int ClientID)
    }

    public function getRunningVoteStatus(AbstractTee $requestingTee): ?AbstractChunk // TODO: Type this with the correct chunk
    {
        return null; // TODO: CGameContext::SendVoteStatus(int ClientID, int Total, int Yes, int No)
    }
}
