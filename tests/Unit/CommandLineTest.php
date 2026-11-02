<?php

use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\CommandLine;
use TeeFrame\Game\Tees\AbstractTee;

test('parameter declarations control token boundaries optional arguments and numeric validation', function (string $params, string $line, array $expected) {
    expect(CommandLine::parseArguments($line, $params))->toBe($expected);
})->with([
    'no parameters'                            => ['', '', []],
    'whitespace without parameters'            => ['', " \t", []],
    'word'                                     => ['s[name]', 'arena', ['arena']],
    'quoted word'                              => ['s[name]', '"arena one"', ['arena one']],
    'empty quoted string'                      => ['s[name]', '""', ['']],
    'two words'                                => ['s[first] s[second]', 'one two', ['one', 'two']],
    'remaining text'                           => ['r[text]', 'one  two "three"', ['one  two "three"']],
    'quoted remaining text'                    => ['r[text]', '"one two"', ['one two']],
    'required before rest'                     => ['s[player] r[message]', 'Player secret text', ['Player', 'secret text']],
    'quoted escapes'                           => ['s[value]', '"a\\\\b\\"c"', ['a\\b"c']],
    'omitted optional'                         => ['s[name] ?s[map]', 'ctf', ['ctf']],
    'all optional omitted'                     => ['?i[id] r[reason]', '', []],
    'optional tail'                            => ['s[name] ?s[map] ?r[reason]', 'ctf ctf1 another reason', ['ctf', 'ctf1', 'another reason']],
    'empty optional placeholder'               => ['s[name] ?s[map] ?r[reason]', 'tdm "" another reason', ['tdm', '', 'another reason']],
    'optional applies to remaining parameters' => ['s[name] ?i[id] r[reason]', 'name', ['name']],
    'integer zero'                             => ['i[id]', '0', ['0']],
    'negative integer'                         => ['i[value]', '-42', ['-42']],
    'signed integer'                           => ['i[value]', '+7', ['+7']],
    'float'                                    => ['f[value]', '-1.25', ['-1.25']],
    'scientific notation'                      => ['f[value]', '2e3', ['2e3']],
    'labels can contain spaces'                => ['s[player name] r[message text]', 'Player hello', ['Player', 'hello']],
    'unlabelled types'                         => ['s i f', 'name 1 2.5', ['name', '1', '2.5']],
]);

test('missing excess and malformed typed arguments are rejected', function (string $params, string $line) {
    expect(fn () => CommandLine::parseArguments($line, $params))->toThrow(InvalidArgumentException::class);
})->with([
    'missing string'                    => ['s[name]', ''],
    'missing rest'                      => ['s[name] r[message]', 'name'],
    'extra parameter'                   => ['s[name]', 'one two'],
    'unexpected argument'               => ['', 'extra'],
    'unclosed string'                   => ['s[name]', '"name'],
    'unseparated quoted argument'       => ['s[name]', '"name"suffix'],
    'extra text after quoted rest'      => ['r[text]', '"one" two'],
    'integer with suffix'               => ['i[id]', '12x'],
    'fractional integer'                => ['i[id]', '1.5'],
    'overflowing integer'               => ['i[id]', '99999999999999999999999999'],
    'empty integer'                     => ['i[id]', '""'],
    'invalid supplied optional integer' => ['?i[id]', 'no'],
    'float with suffix'                 => ['f[value]', '1.5x'],
    'not a number'                      => ['f[value]', 'NaN'],
    'infinity'                          => ['f[value]', 'INF'],
    'overflowing float'                 => ['f[value]', '1e9999'],
]);

test('invalid parameter declarations are configuration errors', function (string $params) {
    expect(fn () => CommandLine::parseArguments('', $params))->toThrow(LogicException::class);
})->with(['unknown', 's[name', 's[]', '?', 's[name] extra', 'r[text] s[other]', 'x[name]', 's[name]?i[id]']);

test('command matching uses exact names and aliases', function () {
    $command = new class extends AbstractCommand
    {
        public function getType(): int
        {
            return self::TYPE_SERVER;
        }

        public function getName(): string
        {
            return 'example';
        }

        public function getAliases(): array
        {
            return ['e'];
        }

        public function getParams(): string
        {
            return 's[name] ?i[id]';
        }

        public function execute(AbstractWorld $world, AbstractTee $tee, array $arguments, ?Closure $output = null): void {}
    };

    expect(CommandLine::match($command, 'EXAMPLE "a name" 3'))->toBe(['a name', '3']);
    expect(CommandLine::match($command, 'E name'))->toBe(['name']);
    expect(CommandLine::match($command, 'example_extra name'))->toBeNull();
    expect(CommandLine::match($command, 'unrelated'))->toBeNull();
    expect(fn () => CommandLine::match($command, 'example'))->toThrow(InvalidArgumentException::class);
    expect(fn () => CommandLine::match($command, 'example name not-an-integer'))->toThrow(InvalidArgumentException::class);
});
