<?php

namespace TeeFrame\Server\Rcon;

use TeeFrame\Core\Utf8;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Commands\AbstractCommand;
use TeeFrame\Game\Commands\CommandLine;
use TeeFrame\Game\GameConstants;
use TeeFrame\Network\Chunks\AbstractChunk;
use TeeFrame\Network\Chunks\Game\SvChatChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionAddChunk;
use TeeFrame\Network\Chunks\Game\SvVoteOptionRemoveChunk;
use TeeFrame\Network\Chunks\System\RconAuthChunk;
use TeeFrame\Network\Chunks\System\RconAuthStatusChunk;
use TeeFrame\Network\Chunks\System\RconCmdAddChunk;
use TeeFrame\Network\Chunks\System\RconCmdChunk;
use TeeFrame\Network\Chunks\System\RconCmdRemChunk;
use TeeFrame\Network\Chunks\System\RconLineChunk;
use TeeFrame\Network\NetworkBase;
use TeeFrame\Server\Ban\BanList;
use TeeFrame\Server\ConnectionHandler;
use TeeFrame\Server\ConnectionSlot;
use TeeFrame\Server\Console;

class Rcon
{
    protected \Closure $consoleListener;
    protected bool $stopped = false;

    public function __construct(
        protected ConnectionHandler $connections,
        protected BanList $bans,
        #[\SensitiveParameter] protected string $password = '',
        protected int $maxTries = 10,
        protected int $banDuration = 300,
    ) {
        if ($maxTries < 1 || $banDuration < 0) {
            throw new \InvalidArgumentException('RCON requires a positive attempt limit and a non-negative ban duration');
        }
        if (str_contains($password, "\0")) {
            throw new \InvalidArgumentException('RCON password must not contain a zero byte');
        }

        $this->consoleListener = fn (string $line) => $this->broadcastLine($line);
        Console::listen($this->consoleListener);
    }

    public function handle(ConnectionSlot $connection, AbstractChunk $chunk): void
    {
        if ($this->stopped || ! ($chunk->getFlags() & NetworkBase::CHUNK_FLAG_VITAL)
            || $connection->state < ConnectionSlot::STATE_LOADING || $connection->state > ConnectionSlot::STATE_INGAME
        ) {
            return;
        }

        if ($chunk instanceof RconAuthChunk) {
            $this->authenticate($connection, $chunk);
        } elseif ($chunk instanceof RconCmdChunk && $connection->rcon()->authenticated) {
            $this->execute($connection, $chunk->command);
        }
    }

    protected function authenticate(ConnectionSlot $connection, RconAuthChunk $chunk): void
    {
        $session = $connection->rcon();
        if ($session->authenticated) {
            return;
        }
        if ($this->password === '') {
            $this->sendLine($connection, 'Remote console is disabled.');

            return;
        }
        if (hash_equals($this->password, $chunk->password)) {
            $session->authenticated      = true;
            $session->authTries          = 0;
            $session->commandList        = $chunk->sendCommandList;
            $session->advertisedCommands = [];
            $connection->chunks()->add(new RconAuthStatusChunk(true, $chunk->sendCommandList));
            $this->sendLine($connection, 'Authentication successful. Full remote console access granted.');
            $connection->consoleInfo('RCON authentication successful');

            return;
        }

        $session->authTries++;
        $this->sendLine($connection, 'Wrong password '.$session->authTries.'/'.$this->maxTries.'.');
        if ($session->authTries >= $this->maxTries) {
            $reason = 'Too many remote console authentication tries';
            $connection->consoleWarn($reason);
            if ($this->banDuration > 0) {
                $this->bans->ban($connection->destinationAddress, $this->banDuration, $reason);
            }
            $connection->closeConnection($reason);
        }
    }

    protected function execute(ConnectionSlot $connection, string $line): void
    {
        if (strlen($line) > 511 || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $line) || ! mb_check_encoding($line, 'UTF-8')) {
            $this->sendLine($connection, 'Invalid remote console command.');

            return;
        }

        try {
            foreach (CommandLine::split($line) as $commandLine) {
                if ($this->stopped || ! $connection->rcon()->authenticated || $connection->state === ConnectionSlot::STATE_CLOSED) {
                    break;
                }

                $this->executeCommand($connection, $commandLine);
            }
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->sendLine($connection, $exception->getMessage());
        }
    }

    protected function executeCommand(ConnectionSlot $connection, string $line): void
    {
        $name = strtolower(CommandLine::name($line));
        if ($name === 'logout' || $name === 'cmdlist') {
            CommandLine::arguments(substr($line, strlen($name)), '');
        }
        if ($name === 'logout') {
            $connection->consoleInfo('RCON command=logout');
            $this->logout($connection);

            return;
        }

        if ($name === 'cmdlist') {
            $connection->consoleInfo('RCON command=cmdlist');
            foreach (AbstractCommand::formatList(array_keys($this->getCommandInfo($connection->world()))) as $line) {
                $this->sendLine($connection, $line);
            }

            return;
        }

        if (in_array($name, ['add_vote', 'remove_vote', 'force_vote'], true)) {
            $connection->consoleInfo('RCON command='.$name);
            $this->executeVoteCommand($connection, $name, substr($line, strlen($name)));

            return;
        }

        $world   = $connection->world();
        $command = $world->findCommand($name, AbstractCommand::TYPE_SERVER);
        if ($command !== null) {
            $arguments = CommandLine::match($command, $line);
            assert($arguments !== null);

            // Arguments can contain credentials supplied to custom commands.
            $connection->consoleInfo('RCON command='.strtok($command->getName(), " \t"));
            $command->execute($world, $connection->playerTee(), $arguments, fn (string $message) => $this->sendLine($connection, $message));

            return;
        }

        $this->sendLine($connection, 'Unknown command: '.strtok($line, " \t"));
    }

    protected function executeVoteCommand(ConnectionSlot $connection, string $name, string $line): void
    {
        $arguments = CommandLine::arguments($line, $this->getCommandInfo($connection->world())[$name]['params']);

        $world = $connection->world();
        $votes = $world->getVoteController();
        if ($name === 'add_vote') {
            [$description, $command] = $arguments;
            if ($votes->getVoteOption($description) !== null) {
                throw new \InvalidArgumentException('Vote option already exists: '.$description);
            }
            if ($votes->resolveCommands($world, $command) === null) {
                throw new \InvalidArgumentException('Vote options must use registered server commands');
            }
            $votes->addVoteOption($description, $command);
            $this->sendVoteOptionUpdate($world, new SvVoteOptionAddChunk($description));
            $this->sendLine($connection, 'Added vote option: '.$description);

            return;
        }
        if ($name === 'remove_vote') {
            $option = $votes->getVoteOption($arguments[0]) ?? throw new \InvalidArgumentException('Unknown vote option: '.$arguments[0]);
            $votes->removeVoteOption($option->description);
            $this->sendVoteOptionUpdate($world, new SvVoteOptionRemoveChunk($option->description));
            $this->sendLine($connection, 'Removed vote option: '.$option->description);

            return;
        }

        $this->forceVote($connection, strtolower($arguments[0]), $arguments[1], $arguments[2] ?? '');
    }

    protected function sendVoteOptionUpdate(AbstractWorld $world, AbstractChunk $chunk): void
    {
        // READY clients may already have received their initial vote menu.
        $world->getServer()->sendToWorld($world, $chunk, includeReady: true);
    }

    protected function forceVote(ConnectionSlot $connection, string $type, string $value, string $reason): void
    {
        $world  = $connection->world();
        $reason = $reason === '' ? 'No reason given' : $reason;
        if ($type === 'option') {
            $votes    = $world->getVoteController();
            $option   = $votes->getVoteOption($value) ?? throw new \InvalidArgumentException('Unknown vote option: '.$value);
            $commands = $votes->resolveCommands($world, $option->command) ?? throw new \InvalidArgumentException('Vote options must use registered server commands');
            $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: Utf8::truncateBytes("Admin forced server option '{$option->description}' ({$reason})", 511)));
            foreach ($commands as $resolved) {
                if ($this->stopped || ! $connection->rcon()->authenticated || $connection->world() !== $world || $connection->state === ConnectionSlot::STATE_CLOSED) {
                    break;
                }
                $resolved['command']->execute($world, $connection->playerTee(), $resolved['arguments'], fn (string $message) => $this->sendLine($connection, $message));
            }

            return;
        }
        if ($type !== 'kick' && $type !== 'spectate') {
            throw new \InvalidArgumentException('Vote type must be option, kick or spectate');
        }

        $index  = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $target = $index === false ? null : $this->connections->findByTee($world, $index);
        if ($target === null) {
            throw new \InvalidArgumentException('Invalid client id to '.$type);
        }
        if ($type === 'kick') {
            if ($target === $connection || $target->rcon()->authenticated) {
                throw new \InvalidArgumentException('Cannot kick yourself or another authenticated administrator');
            }
            $world->getServer()->kick($world, $index, Utf8::truncateBytes($reason, 127));

            return;
        }

        $tee = $target->playerTee();
        if ($tee->team === GameConstants::TEAM_SPECTATORS) {
            throw new \InvalidArgumentException('The player is already a spectator');
        }
        $tee->setTeam(GameConstants::TEAM_SPECTATORS);
        $world->broadcast(new SvChatChunk(team: 0, clientId: -1, text: Utf8::truncateBytes("'{$tee->name}' was moved to spectators ({$reason})", 511)));
    }

    /** @return array<string, array{help: string, params: string}> */
    public function getCommandInfo(AbstractWorld $world): array
    {
        $info = [
            'cmdlist'     => ['help' => 'List available RCON commands', 'params' => ''],
            'logout'      => ['help' => 'Log out of the remote console', 'params' => ''],
            'add_vote'    => ['help' => 'Add a voting option', 'params' => 's[name] r[command]'],
            'remove_vote' => ['help' => 'Remove a voting option', 'params' => 'r[name]'],
            'force_vote'  => ['help' => 'Force a voting option, kick or spectate', 'params' => 's[type] s[value] ?r[reason]'],
        ] + array_map(
            fn (AbstractCommand $command): array => ['help' => $command->getHelpText(), 'params' => $command->getParams()],
            $world->getCommandMap(AbstractCommand::TYPE_SERVER),
        );

        return $info;
    }

    public function update(): void
    {
        if ($this->stopped) {
            return;
        }
        foreach ($this->connections->getConnections() as $connection) {
            if (! $connection->rcon()->authenticated || $connection->state === ConnectionSlot::STATE_CLOSED) {
                continue;
            }

            $this->updateCommands($connection);
            $connection->chunks()->send();
        }
    }

    protected function updateCommands(ConnectionSlot $connection): void
    {
        $session  = $connection->rcon();
        $commands = $session->commandList ? $this->getCommandInfo($connection->world()) : [];

        foreach ($session->advertisedCommands as $name => $metadata) {
            if (($commands[$name] ?? null) === $metadata) {
                continue;
            }
            $connection->chunks()->add(new RconCmdRemChunk($name));
            unset($session->advertisedCommands[$name]);
        }

        // Spread autocomplete over ticks instead of filling the reliable buffer.
        $added = 0;
        foreach ($commands as $name => $metadata) {
            if (isset($session->advertisedCommands[$name])) {
                continue;
            }
            $connection->chunks()->add(new RconCmdAddChunk($name, $metadata['help'], $metadata['params']));
            $session->advertisedCommands[$name] = $metadata;
            if (++$added === 4) {
                break;
            }
        }
    }

    public function logout(ConnectionSlot $connection): void
    {
        $session                     = $connection->rcon();
        $session->authenticated      = false;
        $session->commandList        = false;
        $session->advertisedCommands = [];
        $connection->chunks()->add(new RconAuthStatusChunk(false, false));
        $this->sendLine($connection, 'Logged out.');
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function sendLine(ConnectionSlot $connection, string $line): void
    {
        if ($connection->state === ConnectionSlot::STATE_CLOSED) {
            return;
        }
        $connection->chunks()->add(new RconLineChunk($line))->send();
    }

    protected function broadcastLine(string $line): void
    {
        if ($this->stopped) {
            return;
        }
        foreach ($this->connections->getConnections() as $connection) {
            if ($connection->rcon()->authenticated && $connection->state !== ConnectionSlot::STATE_CLOSED) {
                $connection->chunks()->add(new RconLineChunk($line));
            }
        }
    }
}
