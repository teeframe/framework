<?php

namespace Base;

class Console
{
    public function __construct()
    {

    }

    public function info(string $message): void
    {
        $this->newLine($message, 32);
    }

    public function warn(string $message): void
    {
        $this->newLine($message, 33);
    }

    public function error(string $message): void
    {
        $this->newLine($message, 31);
    }

    protected function newLine(string $message, int $textColor = 0): void
    {
        echo "\033[0;{$textColor}m".$message."\033[0m".PHP_EOL;
    }
}
