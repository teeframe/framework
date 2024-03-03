<?php

namespace TeeFrame\Game\Tees;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Core\SnapableObject;
use TeeFrame\Game\Core\Vector2;
use TeeFrame\Network\SnapItems\ObjClientInfoItem;
use TeeFrame\Network\SnapItems\ObjPlayerInfoItem;

abstract class AbstractTee implements SnapableObject
{
    protected ?AbstractWorld $world = null;

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

    protected int $playerIndex;

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
        $this->viewPosition = new Vector2(0, 0);
        $this->playerIndex  = -1;
    }

    public function setWorld(AbstractWorld $world): void
    {
        $this->world = $world;
    }

    public function doSnap(AbstractTee $requestingTee): array
    {
        return [
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
                clientId: $this->playerIndex,
                team: 0,
                score: 0,
                latency: 0, // TODO: Implement latency in the right way
            ),
        ];
    }
}
