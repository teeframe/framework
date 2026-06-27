<?php

namespace TeeFrame\Network\Chunks\Game;

use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Network\NetworkMessages;
use TeeFrame\Network\RawPayload;

/**
 * Sends a batch of up to 15 vote option descriptions to the client.
 *
 * Mirrors CNetMsg_Sv_VoteOptionListAdd: m_NumOptions followed by exactly
 * 15 description strings (unused slots are empty).
 */
class SvVoteOptionListAddChunk extends AbstractChunk
{
    public const MAX_OPTIONS = 15;

    /**
     * @param string[] $descriptions 1 to 15 vote option descriptions.
     */
    public function __construct(public array $descriptions)
    {
        parent::__construct(flags: NetworkBase::CHUNK_FLAG_VITAL, message: NetworkMessages::SV_VOTEOPTIONLISTADD);
    }

    public static function make(RawPayload $payload): static
    {
        $numOptions = $payload->extractInt();

        $descriptions = [];
        for ($i = 0; $i < self::MAX_OPTIONS; $i++) {
            $descriptions[] = $payload->extractString();
        }

        return new static(array_slice($descriptions, 0, max(1, $numOptions)));
    }

    public function getPayload(): RawPayload
    {
        $descriptions = array_values($this->descriptions);
        $numOptions = count($descriptions);

        $payload = (new RawPayload)->addInt($numOptions);

        for ($i = 0; $i < self::MAX_OPTIONS; $i++) {
            $payload->addString($descriptions[$i] ?? '');
        }

        return $payload;
    }
}
