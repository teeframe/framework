<?php

namespace TeeFrame\Game\Tees;

use TeeFrame\Core\SnapableObject;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\GameConstants;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Network\SnapItems\ObjClientInfoItem;
use TeeFrame\Network\SnapItems\ObjPlayerInfoItem;
use TeeFrame\Network\SnapItems\ObjSpectatorInfoItem;

abstract class AbstractTee implements SnapableObject
{
    protected ?AbstractWorld $world = null;

    public ?AbstractCharacterEntity $character = null;

    // Client Info
    public string $name;

    public string $clan;

    public int $country;

    public string $skinName;

    public bool $useCustomColor;

    public int $colorBody;

    public int $colorFeet;

    // Tee On World
    public Vector2 $viewPosition;

    public int $teeIndex;

    public int $latency = 0;

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        // Client Info
        $this->name           = '';
        $this->clan           = '';
        $this->country        = 0;
        $this->skinName       = '';
        $this->useCustomColor = false;
        $this->colorBody      = 0;
        $this->colorFeet      = 0;

        // Tee On World
        $this->viewPosition  = new Vector2(0, 0);
        $this->teeIndex      = -1;
        $this->latency       = 0;
    }

    public function setInfo(string $name, string $clan, int $country, string $skinName, bool $useCustomColor, int $colorBody, int $colorFeet): void
    {
        $this->name           = $name;
        $this->clan           = $clan;
        $this->country        = $country;
        $this->skinName       = $skinName;
        $this->useCustomColor = $useCustomColor;
        $this->colorBody      = $colorBody;
        $this->colorFeet      = $colorFeet;
    }

    public function setWorld(AbstractWorld $world, int $index): void
    {
        $this->world    = $world;
        $this->teeIndex = $index;
    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        $snaps = [
            new ObjClientInfoItem(
                name: $this->name,
                clan: $this->clan,
                country: $this->country,
                skinName: $this->skinName,
                useCustomColor: $this->useCustomColor,
                colorBody: $this->colorBody,
                colorFoot: $this->colorFeet,
            ),
            new ObjPlayerInfoItem(
                local: $this === $requestingTee,
                clientId: $this->teeIndex,
                team: $this->getSnapTeam(),
                score: $this->getSnapScore(),
                latency: $this->latency,
            ),
        ];

        // Spectator info is only sent to the local spectator
        if ($this === $requestingTee && $this->getSnapTeam() === GameConstants::TEAM_SPECTATORS) {
            $snaps[] = new ObjSpectatorInfoItem(
                spectatorId: $this->getSnapSpectatorId(),
                x: (int) round($this->viewPosition->x),
                y: (int) round($this->viewPosition->y),
            );
        }

        return $snaps;
    }

    protected function getSnapScore(): int
    {
        return 0;
    }

    protected function getSnapTeam(): int
    {
        return GameConstants::TEAM_RED;
    }

    protected function getSnapSpectatorId(): int
    {
        return GameConstants::SPEC_FREEVIEW;
    }
}
