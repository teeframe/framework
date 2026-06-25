<?php

namespace TeeFrame\Game\Commands;

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Tees\AbstractTee;

abstract class AbstractCommand
{
    abstract public function getName(): string;

    abstract public function getPattern(): string;

    /**
     * @param  string[]  $matches
     */
    abstract public function execute(AbstractWorld $world, AbstractTee $tee, array $matches): void;
}