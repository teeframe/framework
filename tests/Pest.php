<?php

use TeeFrame\Core\TickHandler;
use TeeFrame\Game\AbstractGameController;
use TeeFrame\Game\AbstractWorld;
use TeeFrame\Game\Entities\Character\AbstractCharacterEntity;
use TeeFrame\Game\Entities\Character\PvpCharacterEntity;
use TeeFrame\Game\PlayerInput;
use TeeFrame\Game\PvpGameController;
use TeeFrame\Game\Tees\AbstractTee;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;
use TeeFrame\Network\Chunks\Game\SvSoundGlobalChunk;
use TeeFrame\Server\AbstractServerInstance;
use Tests\Support\RecordingGameServer;

require_once __DIR__.'/Support/RecordingGameServer.php';
require_once __DIR__.'/Support/RecordingConnection.php';

class TestGameController extends PvpGameController
{
    public function onCharacterDeath(AbstractCharacterEntity $victim, int $killerTeeIndex): int
    {
        return 0;
    }
}

class TestWorld extends AbstractWorld
{
    public function __construct(
        TickHandler $tickHandler,
        Map $map,
        ?AbstractServerInstance $server = null,
    ) {
        parent::__construct('test', $tickHandler, $map, $server ?? new RecordingGameServer($tickHandler), slotsLimit: 64);
    }

    public function getMotd(AbstractTee $requestingTee): string
    {
        return '';
    }

    protected function bootGameController(): void
    {
        $this->gameController = new TestGameController($this->tickHandler);
    }

    protected function getNewCharacterEntity(Vector2 $position): AbstractCharacterEntity
    {
        return new PvpCharacterEntity($this, $position);
    }
}

class TestWorldWithController extends AbstractWorld
{
    public function __construct(
        TickHandler $tickHandler,
        Map $map,
        AbstractGameController $controller,
        ?AbstractServerInstance $server = null,
        int $slotsLimit = 64,
    ) {
        $this->gameController = $controller;
        parent::__construct('test', $tickHandler, $map, $server ?? new RecordingGameServer($tickHandler), $slotsLimit);
    }

    public function getMotd(AbstractTee $requestingTee): string
    {
        return '';
    }

    protected function bootGameController(): void
    {
        // Controller already set in __construct.
    }

    protected function getNewCharacterEntity(Vector2 $position): AbstractCharacterEntity
    {
        return new PvpCharacterEntity($this, $position);
    }
}

class TestPvpWorldWithController extends TestWorldWithController
{
    public function getGameController(): PvpGameController
    {
        $controller = parent::getGameController();
        assert($controller instanceof PvpGameController);

        return $controller;
    }
}

function recordingServer(AbstractWorld $world): RecordingGameServer
{
    $server = $world->getServer();
    if (! $server instanceof RecordingGameServer) {
        throw new LogicException('Test world does not use a recording server');
    }

    return $server;
}

/** @return list<int> */
function soundsSentToTee(AbstractWorld $world, int $teeIndex): array
{
    $sounds = [];
    foreach (recordingServer($world)->sentPairs as $pair) {
        if ($pair['world'] === $world && $pair['teeIndex'] === $teeIndex && $pair['chunk'] instanceof SvSoundGlobalChunk) {
            $sounds[] = $pair['chunk']->soundId;
        }
    }

    return $sounds;
}

function createWorld(Map $map): AbstractWorld
{
    return new class(new TickHandler, $map) extends TestWorld
    {
        public function doTick(): void {}
    };
}

/** @param array<string, mixed> $overrides */
function input(array $overrides = []): PlayerInput
{
    return new PlayerInput(
        direction: (int) ($overrides['direction'] ?? 0),
        targetX: (int) ($overrides['targetX'] ?? 0),
        targetY: (int) ($overrides['targetY'] ?? -1),
        jump: (bool) ($overrides['jump'] ?? false),
        fire: (int) ($overrides['fire'] ?? 0),
        hook: (bool) ($overrides['hook'] ?? false),
        playerFlags: (int) ($overrides['playerFlags'] ?? 0),
        wantedWeapon: (int) ($overrides['wantedWeapon'] ?? 0),
        nextWeapon: (int) ($overrides['nextWeapon'] ?? 0),
        prevWeapon: (int) ($overrides['prevWeapon'] ?? 0),
    );
}

function feedInput(AbstractCharacterEntity $character, PlayerInput $input): void
{
    $character->onPredictedInput($input);
    $character->applyInput();
    $character->doTick();
}
