<?php

namespace TeeFrame\Server\Concerns;

use TeeFrame\Server\Console;

trait HasConnectionConsole
{
    public function consoleError(string $message): void
    {
        Console::error($this->generateConsoleMessage($message));
    }

    public function consoleWarn(string $message): void
    {
        Console::warn($this->generateConsoleMessage($message));
    }

    public function consoleInfo(string $message): void
    {
        Console::info($this->generateConsoleMessage($message));
    }

    protected function generateConsoleMessage(string $message): string
    {
        return 'ClientID='.$this->slotIndex.', addr='.$this->destinationAddress.':'.$this->destinationPort.'. '.$message;
    }
}
