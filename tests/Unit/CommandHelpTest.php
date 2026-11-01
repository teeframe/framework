<?php

use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\Chat\ConverseCommand;
use TeeFrame\Game\Commands\Server\PauseCommand;
use TeeFrame\Game\Commands\Server\ReloadCommand;

test('command help includes alias parameters without changing the description', function () {
    $command = new ConverseCommand;
    expect($command->getHelpText())->toBe('Whisper to the last player you whispered to - Aliases: c r[message]');
    expect($command->getDescription())->toBe('Whisper to the last player you whispered to');
    expect((new PauseCommand)->getHelpText())->toBe('Pause or unpause this world - Aliases: pause_game');
    expect((new ReloadCommand)->getHelpText())->toBe('Reload the current map and restart this world');
});

test('command lists contain sorted unique names without descriptions or parameters', function () {
    expect(AbstractCommand::formatList(['whisper', 'c', 'converse', 'c', 'cmdlist']))->toBe(['c, cmdlist, converse, whisper']);
    expect(AbstractCommand::formatList([]))->toBeEmpty();
});

test('long command lists split only between complete names and preserve every entry', function () {
    $names = array_map(fn (int $index): string => sprintf('command_%03d_', $index).str_repeat('é', 16), range(1, 40));
    $lines = AbstractCommand::formatList(array_reverse($names));
    expect(count($lines))->toBeGreaterThan(1);
    expect(explode(', ', implode(', ', $lines)))->toBe($names);
    foreach ($lines as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(511);
        expect(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
});
