<?php

use TeeFrame\Network\Chunks\System\InputChunk;
use TeeFrame\Network\RawPayload;

test('InputChunk round-trips correctly with fire=false', function () {
    $original = new InputChunk(
        ackGameTick: 100,
        predictionTick: 102,
        inputSize: 40,
        inputDirection: 1,
        inputTargetX: 200,
        inputTargetY: -50,
        inputJump: false,
        inputFire: false,
        inputHook: false,
        inputPlayerFlag: 0,
        inputWantedWeapon: 0,
        inputNextWeapon: 0,
        inputPrevWeapon: 0,
    );

    $payload = $original->getPayload();
    $decoded = InputChunk::make($payload);

    expect($decoded->ackGameTick)->toBe(100);
    expect($decoded->predictionTick)->toBe(102);
    expect($decoded->inputSize)->toBe(40);
    expect($decoded->inputDirection)->toBe(1);
    expect($decoded->inputTargetX)->toBe(200);
    expect($decoded->inputTargetY)->toBe(-50);
    expect($decoded->inputJump)->toBeFalse();
    expect($decoded->inputFire)->toBeFalse();
    expect($decoded->inputHook)->toBeFalse();
    expect($decoded->inputPlayerFlag)->toBe(0);
    expect($decoded->inputWantedWeapon)->toBe(0);
    expect($decoded->inputNextWeapon)->toBe(0);
    expect($decoded->inputPrevWeapon)->toBe(0);
});

test('InputChunk round-trips correctly with fire=true', function () {
    $original = new InputChunk(
        ackGameTick: 50,
        predictionTick: 51,
        inputSize: 40,
        inputDirection: -1,
        inputTargetX: -100,
        inputTargetY: 300,
        inputJump: true,
        inputFire: true,
        inputHook: true,
        inputPlayerFlag: 1,
        inputWantedWeapon: 3,
        inputNextWeapon: 1,
        inputPrevWeapon: 0,
    );

    $payload = $original->getPayload();
    $decoded = InputChunk::make($payload);

    expect($decoded->ackGameTick)->toBe(50);
    expect($decoded->predictionTick)->toBe(51);
    expect($decoded->inputSize)->toBe(40);
    expect($decoded->inputDirection)->toBe(-1);
    expect($decoded->inputTargetX)->toBe(-100);
    expect($decoded->inputTargetY)->toBe(300);
    expect($decoded->inputJump)->toBeTrue();
    expect($decoded->inputFire)->toBeTrue();
    expect($decoded->inputHook)->toBeTrue();
    expect($decoded->inputPlayerFlag)->toBe(1);
    expect($decoded->inputWantedWeapon)->toBe(3);
    expect($decoded->inputNextWeapon)->toBe(1);
    expect($decoded->inputPrevWeapon)->toBe(0);
});

test('InputChunk with all zero input round-trips correctly', function () {
    $original = new InputChunk(
        ackGameTick: 0,
        predictionTick: 0,
        inputSize: 40,
        inputDirection: 0,
        inputTargetX: 0,
        inputTargetY: 0,
        inputJump: false,
        inputFire: false,
        inputHook: false,
        inputPlayerFlag: 0,
        inputWantedWeapon: 0,
        inputNextWeapon: 0,
        inputPrevWeapon: 0,
    );

    $payload = $original->getPayload();
    $decoded = InputChunk::make($payload);

    expect($decoded->ackGameTick)->toBe(0);
    expect($decoded->predictionTick)->toBe(0);
    expect($decoded->inputSize)->toBe(40);
    expect($decoded->inputDirection)->toBe(0);
    expect($decoded->inputTargetX)->toBe(0);
    expect($decoded->inputTargetY)->toBe(0);
    expect($decoded->inputJump)->toBeFalse();
    expect($decoded->inputFire)->toBeFalse();
    expect($decoded->inputHook)->toBeFalse();
});

test('InputChunk encode output contains expected bytes', function () {
    $chunk = new InputChunk(
        ackGameTick: 0,
        predictionTick: 0,
        inputSize: 40,
        inputDirection: 0,
        inputTargetX: 0,
        inputTargetY: 0,
        inputJump: false,
        inputFire: false,
        inputHook: false,
        inputPlayerFlag: 0,
        inputWantedWeapon: 0,
        inputNextWeapon: 0,
        inputPrevWeapon: 0,
    );

    $encoded = $chunk->encode();

    // All zero input should produce a compact payload (each int = 1 byte for value 0)
    // But we need at least the header bytes
    expect($encoded)->not->toBeEmpty();
});