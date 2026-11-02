<?php

use TeeFrame\Game\World\TuneController;

test('every protocol tuning value can be addressed by its console name and reset individually', function () {
    $tuning   = new TuneController;
    $defaults = $tuning->getValues();
    expect($defaults)->toHaveCount(33);
    expect($defaults['ground_control_speed'])->toBe(10.0);
    expect($defaults['shotgun_speeddiff'])->toBe(0.8);
    foreach ($defaults as $name => $value) {
        $tuning->set($name, $value + 1);
        expect($tuning->get($name))->toBe($value + 1);
        $tuning->reset($name);
        expect($tuning->get($name))->toBe($value);
    }
    expect($tuning)->toEqual(new TuneController);
});

test('tuning setters reject non-finite and overflowing values without changing state', function (float $value) {
    $tuning = new TuneController;
    expect(fn () => $tuning->set('gravity', $value))->toThrow(InvalidArgumentException::class);
    expect($tuning->gravity)->toBe(50);
})->with([INF, -INF, NAN, 21474836.48, -21474836.49]);

test('decimal tuning values are rounded to protocol hundredths without floating-point truncation', function (float $value, int $scaled) {
    $tuning = new TuneController;
    $tuning->set('gravity', $value);
    expect($tuning->gravity)->toBe($scaled);
})->with([[0.29, 29], [1.13, 113], [-0.29, -29], [0.289, 29]]);

test('velocity ramp tuning rejects non-positive encoded values without changing state', function (string $name, float $value) {
    $tuning = new TuneController;

    expect(fn () => $tuning->set($name, $value))->toThrow(InvalidArgumentException::class, $name.' must be at least 0.01');
    expect($tuning)->toEqual(new TuneController);
})->with(['velramp_range', 'velramp_curvature'])->with([0.0, -1.0, 0.004, -0.004]);

test('a zero tuning value can be read without being treated as missing', function () {
    $tuning = new TuneController;
    $tuning->set('gravity', 0.0);
    expect($tuning->get('gravity'))->toBe(0.0);
});
