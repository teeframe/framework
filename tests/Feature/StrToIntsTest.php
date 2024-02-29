<?php

test('stringToIntegers', function () {
    expect(stringToIntegers("wL7SHc4Ipa1prqHE", 4))->toBe([
        -137578541,
        -924601143,
        -253644304,
        -219035648,
    ]);
});

/**
 * @param string $string Input string
 * @param int $num Number of output integers count
 *
 * @return int[]
 */
function stringToIntegers(string $string, int $num): array
{
    $integers = array_fill(0, $num, 0);
    $bytes = unpack('c*', $string);
    $bytesCount = count($bytes);
    $index = 0;

    for ($i = 0; $i < $num; $i++) {
        $buffer = [0, 0, 0, 0];

        for (
            $c = 0;
            $c < 4 && $index < $bytesCount;
            $c++, $index++
        ) {
            $buffer[$c] = $bytes[$index + 1];
        }

        $integers[$i] = toInt32(
            (($buffer[0] + 128) << 24) |
            (($buffer[1] + 128) << 16) |
            (($buffer[2] + 128) << 8) |
            (($buffer[3] + 128) << 0)
        );
    }

    $integers[$num - 1] = toInt32($integers[$num - 1] & 0xffff_ff00);
    return $integers;
}
