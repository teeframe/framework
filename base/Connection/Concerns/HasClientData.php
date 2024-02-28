<?php

namespace Base\Connection\Concerns;

trait HasClientData
{
    public string $name;

    public string $clan;

    public int $country;

    public string $skinName;

    public bool $useCustomColor;

    public int $colorBody;

    public int $colorFeet;

    protected function resetClientData(): void
    {
        $this->name           = '';
        $this->clan           = '';
        $this->country        = 0;
        $this->skinName       = '';
        $this->useCustomColor = false;
        $this->colorBody      = 0;
        $this->colorFeet      = 0;
    }
}
