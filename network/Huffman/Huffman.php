<?php

namespace TeeFrame\Network\Huffman;

class Huffman
{
    /** @var int[] */
    public const FREQ_TABLE = [
        1 << 30, 4545, 2657, 431, 1950, 919, 444, 482, 2244, 617, 838, 542, 715, 1814, 304, 240, 754, 212, 647, 186,
        283, 131, 146, 166, 543, 164, 167, 136, 179, 859, 363, 113, 157, 154, 204, 108, 137, 180, 202, 176,
        872, 404, 168, 134, 151, 111, 113, 109, 120, 126, 129, 100, 41, 20, 16, 22, 18, 18, 17, 19,
        16, 37, 13, 21, 362, 166, 99, 78, 95, 88, 81, 70, 83, 284, 91, 187, 77, 68, 52, 68,
        59, 66, 61, 638, 71, 157, 50, 46, 69, 43, 11, 24, 13, 19, 10, 12, 12, 20, 14, 9,
        20, 20, 10, 10, 15, 15, 12, 12, 7, 19, 15, 14, 13, 18, 35, 19, 17, 14, 8, 5,
        15, 17, 9, 15, 14, 18, 8, 10, 2173, 134, 157, 68, 188, 60, 170, 60, 194, 62, 175, 71,
        148, 67, 167, 78, 211, 67, 156, 69, 1674, 90, 174, 53, 147, 89, 181, 51, 174, 63, 163, 80,
        167, 94, 128, 122, 223, 153, 218, 77, 200, 110, 190, 73, 174, 69, 145, 66, 277, 143, 141, 60,
        136, 53, 180, 57, 142, 57, 158, 61, 166, 112, 152, 92, 26, 22, 21, 28, 20, 26, 30, 21,
        32, 27, 20, 17, 23, 21, 30, 22, 22, 21, 27, 25, 17, 27, 23, 18, 39, 26, 15, 21,
        12, 18, 18, 27, 20, 18, 15, 19, 11, 17, 33, 12, 18, 15, 19, 18, 16, 26, 17, 18,
        9, 10, 25, 22, 22, 17, 20, 16, 6, 16, 15, 20, 14, 18, 24, 335, 1517,
    ];

    public const HUFFMAN_EOF_SYMBOL  = 256;
    public const HUFFMAN_MAX_SYMBOLS = self::HUFFMAN_EOF_SYMBOL + 1;
    public const HUFFMAN_MAX_NODES   = self::HUFFMAN_MAX_SYMBOLS * 2 - 1;
    public const HUFFMAN_LUTBITS     = 10;
    public const HUFFMAN_LUTSIZE     = 1 << self::HUFFMAN_LUTBITS;
    public const HUFFMAN_LUTMASK     = self::HUFFMAN_LUTSIZE - 1;
    public const HUFFMAN_NULL_NODE   = 0xFFFF;
    public const HUFFMAN_UNSET_BITS  = 0xFFFFFFFF;

    /**
     * @var array<int, HuffmanNode>
     */
    public array $nodes;

    /**
     * @var array<int, int>
     */
    public array $decodeLut;

    public int $numNodes;

    public int $startNodeIndex;

    /**
     * @param int[] $frequencies
     */
    public function __construct(array $frequencies = self::FREQ_TABLE)
    {
        $this->nodes = [];
        for ($i = 0; $i < self::HUFFMAN_MAX_NODES; $i++) {
            $this->nodes[$i] = new HuffmanNode;
        }

        $this->decodeLut      = array_fill(0, self::HUFFMAN_LUTSIZE, 0);
        $this->numNodes       = 0;
        $this->startNodeIndex = 0;

        $this->constructTree($frequencies);

        for ($i = 0; $i < self::HUFFMAN_LUTSIZE; $i++) {
            $bits  = $i;
            $found = false;
            $index = $this->startNodeIndex;
            for ($x = 0; $x < self::HUFFMAN_LUTBITS; $x++) {
                if ($bits & 1) {
                    $index = $this->nodes[$index]->right;
                } else {
                    $index = $this->nodes[$index]->left;
                }
                $bits >>= 1;
                if ($this->nodes[$index]->numBits) {
                    $this->decodeLut[$i] = $index;
                    $found               = true;
                    break;
                }
            }
            if (! $found) {
                $this->decodeLut[$i] = $index;
            }
        }
    }

    public function setBitsR(int $nodeIndex, int $bits, int $depth): void
    {
        if ($this->nodes[$nodeIndex]->right !== self::HUFFMAN_NULL_NODE) {
            $this->setBitsR($this->nodes[$nodeIndex]->right, $bits | (1 << $depth), $depth + 1);
        }
        if ($this->nodes[$nodeIndex]->left !== self::HUFFMAN_NULL_NODE) {
            $this->setBitsR($this->nodes[$nodeIndex]->left, $bits, $depth + 1);
        }
        if ($this->nodes[$nodeIndex]->numBits) {
            $this->nodes[$nodeIndex]->bits    = $bits;
            $this->nodes[$nodeIndex]->numBits = $depth;
        }
    }

    /**
     * @param  array<int, int>  $indexList
     * @param  array<int, HuffmanConstructNode>  $nodeList
     * @return array<int, int>
     */
    public function bubbleSort(array $indexList, array $nodeList, int $size): array
    {
        $changed = true;
        while ($changed) {
            $changed = false;
            for ($i = 0; $i < $size - 1; $i++) {
                if ($nodeList[$indexList[$i]]->frequency < $nodeList[$indexList[$i + 1]]->frequency) {
                    $temp               = $indexList[$i];
                    $indexList[$i]     = $indexList[$i + 1];
                    $indexList[$i + 1] = $temp;
                    $changed           = true;
                }
            }
            $size--;
        }

        return $indexList;
    }

    /**
     * @param int[] $frequencies
     */
    public function constructTree(array $frequencies): void
    {
        /** @var array<int, HuffmanConstructNode> $nodesLeftStorage */
        $nodesLeftStorage = [];

        for ($i = 0; $i < self::HUFFMAN_MAX_SYMBOLS; $i++) {
            $nodesLeftStorage[$i] = new HuffmanConstructNode;
        }

        /** @var array<int, int> $nodesLeft */
        $nodesLeft     = array_fill(0, self::HUFFMAN_MAX_SYMBOLS, 0);
        $numNodesLeft = self::HUFFMAN_MAX_SYMBOLS;

        for ($i = 0; $i < self::HUFFMAN_MAX_SYMBOLS; $i++) {
            $this->nodes[$i]->numBits = self::HUFFMAN_UNSET_BITS;
            $this->nodes[$i]->symbol  = $i;
            $this->nodes[$i]->left    = self::HUFFMAN_NULL_NODE;
            $this->nodes[$i]->right   = self::HUFFMAN_NULL_NODE;

            if ($i === self::HUFFMAN_EOF_SYMBOL) {
                $nodesLeftStorage[$i]->frequency = 1;
            } else {
                $nodesLeftStorage[$i]->frequency = $frequencies[$i];
            }

            $nodesLeftStorage[$i]->nodeId = $i;
            $nodesLeft[$i]                = $i;
        }

        $this->numNodes = self::HUFFMAN_MAX_SYMBOLS;

        while ($numNodesLeft > 1) {
            $nodesLeft = $this->bubbleSort($nodesLeft, $nodesLeftStorage, $numNodesLeft);

            $this->nodes[$this->numNodes]->numBits = 0;
            $this->nodes[$this->numNodes]->left    = $nodesLeftStorage[$nodesLeft[$numNodesLeft - 1]]->nodeId;
            $this->nodes[$this->numNodes]->right   = $nodesLeftStorage[$nodesLeft[$numNodesLeft - 2]]->nodeId;

            $nodesLeftStorage[$nodesLeft[$numNodesLeft - 2]]->nodeId    = $this->numNodes;
            $nodesLeftStorage[$nodesLeft[$numNodesLeft - 2]]->frequency = $nodesLeftStorage[$nodesLeft[$numNodesLeft - 1]]->frequency
                                            + $nodesLeftStorage[$nodesLeft[$numNodesLeft - 2]]->frequency;
            $this->numNodes++;
            $numNodesLeft--;
        }

        $this->startNodeIndex = $this->numNodes - 1;
        $this->setBitsR($this->startNodeIndex, 0, 0);
    }

    /**
     * @param  int[]  $inputBuffer
     * @return int[]
     */
    public function compress(array $inputBuffer, int $startIndex = 0, int $size = 0): array
    {
        /** @var int[] $outputBuffer */
        $outputBuffer = [];
        $bits         = 0;
        $numBits      = 0;
        $value        = 0;

        if ($size === 0) {
            $size = count($inputBuffer);
        }

        for ($i = $startIndex; $i < $size; $i++) {
            $value = $inputBuffer[$i];
            $bits |= $this->nodes[$value]->bits << $numBits;
            $numBits += $this->nodes[$value]->numBits;

            while ($numBits >= 8) {
                $outputBuffer[] = $bits & 0xFF;
                $bits >>= 8;
                $numBits -= 8;
            }
        }

        $value = self::HUFFMAN_EOF_SYMBOL;

        $bits |= $this->nodes[$value]->bits << $numBits;
        $numBits += $this->nodes[$value]->numBits;

        while ($numBits >= 8) {
            $outputBuffer[] = $bits & 0xFF;
            $bits >>= 8;
            $numBits -= 8;
        }

        if ($numBits) {
            $outputBuffer[] = $bits;
        }

        return $outputBuffer;
    }

    /**
     * @param  int[]  $inputBuffer
     * @return int[]
     */
    public function decompress(array $inputBuffer, int $size = 0): array
    {
        $bits      = 0;
        $bitCount  = 0;
        $eof       = $this->nodes[self::HUFFMAN_EOF_SYMBOL];
        /** @var int[] $output */
        $output    = [];

        if ($size === 0) {
            $size = count($inputBuffer);
        }
        $inputBuffer = array_slice($inputBuffer, 0, $size);
        $srcIndex    = 0;

        while (true) {
            $nodeIndex = -1;

            if ($bitCount >= self::HUFFMAN_LUTBITS) {
                $nodeIndex = $this->decodeLut[$bits & self::HUFFMAN_LUTMASK];
            }

            while ($bitCount < 24 && $srcIndex !== $size) {
                $bits |= $inputBuffer[$srcIndex] << $bitCount;
                $bitCount += 8;
                $srcIndex++;
            }

            if ($nodeIndex === -1) {
                $nodeIndex = $this->decodeLut[$bits & self::HUFFMAN_LUTMASK];
            }
            if ($this->nodes[$nodeIndex]->numBits) {
                $bits >>= $this->nodes[$nodeIndex]->numBits;
                $bitCount -= $this->nodes[$nodeIndex]->numBits;
            } else {
                $bits >>= self::HUFFMAN_LUTBITS;
                $bitCount -= self::HUFFMAN_LUTBITS;

                while (true) {
                    if ($bits & 1) {
                        $nodeIndex = $this->nodes[$nodeIndex]->right;
                    } else {
                        $nodeIndex = $this->nodes[$nodeIndex]->left;
                    }
                    $bitCount -= 1;
                    $bits >>= 1;

                    if ($this->nodes[$nodeIndex]->numBits) {
                        break;
                    }
                    if ($bitCount === 0) {
                        // throw new \Exception("No more bits, decoding error");
                        break;
                    } // No more bits, decoding error
                }
            }

            if ($this->nodes[$nodeIndex] === $eof) {
                break;
            }

            $output[] = $this->nodes[$nodeIndex]->symbol;
        }

        return $output;
    }
}
