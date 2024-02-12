<?php

namespace Base;

class Console
{
    public function __construct()
    {

    }

    public function info(string $message): void
    {
        echo $this->newLine($message, 32);
    }

    public function error(string $message): void
    {
        echo $this->newLine($message, 31);
    }

    protected function newLine(string $message, int $textColor = 0): void
    {
        echo "\033[0;{$textColor}m".$message."\033[0m".PHP_EOL;
    }
}
