<?php

namespace Game;

use Game\Core\Vector2;

class Player
{
    public Vector2 $viewPosition;

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->viewPosition = new Vector2(0, 0);
    }
}