<?php

namespace Base\Connection\Concerns;

use Base\Console;

trait HasConsoleFeatures
{
    protected function consoleError(string $message): void
    {
        Console::error($this->generateConsoleMessage($message));
    }

    protected function consoleWarn(string $message): void
    {
        Console::warn($this->generateConsoleMessage($message));
    }

    protected function consoleInfo(string $message): void
    {
        Console::info($this->generateConsoleMessage($message));
    }

    protected function generateConsoleMessage(string $message): string
    {
        return 'ClientID='.$this->slotIndex.', addr='.$this->clientAddress.':'.$this->clientPort.'. '.$message;
    }
}
