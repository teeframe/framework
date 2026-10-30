<?php

use TeeFrame\Game\Entities\PvpLaserEntity;
use TeeFrame\Game\World\Vector2;
use TeeFrame\Map\Map;

test('line projection clamps to the segment and does not mutate its inputs', function (
    Vector2 $start,
    Vector2 $end,
    Vector2 $point,
    Vector2 $expected,
) {
    $originalStart = clone $start;
    $originalEnd   = clone $end;
    $originalPoint = clone $point;
    $projected     = $point->closestPointOnLine($start, $end);

    expect($projected->x)->toEqualWithDelta($expected->x, 0.000000001);
    expect($projected->y)->toEqualWithDelta($expected->y, 0.000000001);
    expect($projected)->not->toBe($start);
    expect($start)->toEqual($originalStart);
    expect($end)->toEqual($originalEnd);
    expect($point)->toEqual($originalPoint);
})->with([
    'horizontal'   => [new Vector2(0, 0), new Vector2(10, 0), new Vector2(4, 5), new Vector2(4, 0)],
    'vertical'     => [new Vector2(2, -5), new Vector2(2, 5), new Vector2(7, 1), new Vector2(2, 1)],
    'diagonal'     => [new Vector2(0, 0), new Vector2(10, 10), new Vector2(8, 2), new Vector2(5, 5)],
    'before start' => [new Vector2(0, 0), new Vector2(10, 0), new Vector2(-5, 2), new Vector2(0, 0)],
    'past end'     => [new Vector2(0, 0), new Vector2(10, 0), new Vector2(20, 2), new Vector2(10, 0)],
    'reversed'     => [new Vector2(10, 0), new Vector2(0, 0), new Vector2(4, 5), new Vector2(4, 0)],
    'zero length'  => [new Vector2(3, 7), new Vector2(3, 7), new Vector2(9, 9), new Vector2(3, 7)],
]);

test('vector and laser projections retain their distinct short segment cutoffs', function (
    float $length,
    float $vectorX,
    float $laserX,
) {
    $start     = new Vector2(0, 0);
    $end       = new Vector2($length, 0);
    $point     = new Vector2($length, 1);
    $laser     = new PvpLaserEntity(createWorld(new Map), clone $start, new Vector2(1, 0), 0, -1);
    $projected = (new ReflectionMethod($laser, 'closestPointOnLine'))->invoke($laser, $start, $end, $point);
    assert($projected instanceof Vector2);

    expect($point->closestPointOnLine($start, $end)->x)->toEqualWithDelta($vectorX, 0.000000000001);
    expect($projected->x)->toEqualWithDelta($laserX, 0.000000000001);
    expect($projected)->not->toBe($start);
})->with([
    'zero'                => [0.0, 0.0, 0.0],
    'below vector cutoff' => [0.0000999, 0.0, 0.0],
    'at vector cutoff'    => [0.0001, 0.0001, 0.0],
    'between cutoffs'     => [0.001, 0.001, 0.0],
    'below laser cutoff'  => [0.009999, 0.009999, 0.0],
    'at laser cutoff'     => [0.01, 0.01, 0.01],
    'above laser cutoff'  => [0.010001, 0.010001, 0.010001],
]);
