<?php

namespace Network\Huffman;

// TODO: Refactor this entirely

class Huffman {
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
        9, 10, 25, 22, 22, 17, 20, 16, 6, 16, 15, 20, 14, 18, 24, 335, 1517
    ];

    public const HUFFMAN_EOF_SYMBOL = 256;
    public const HUFFMAN_MAX_SYMBOLS = self::HUFFMAN_EOF_SYMBOL + 1;
    public const HUFFMAN_MAX_NODES = self::HUFFMAN_MAX_SYMBOLS * 2 - 1;
    public const HUFFMAN_LUTBITS = 10;
    public const HUFFMAN_LUTSIZE = 1 << self::HUFFMAN_LUTBITS;
    public const HUFFMAN_LUTMASK = self::HUFFMAN_LUTSIZE - 1;

    /**
     * @var array<int, HuffmanNode>
     */
    public array $nodes;

    /**
     * @var array<int, int>
     */
    public array $decode_lut;


    public int $num_nodes;

    public int $start_node_index;

    function __construct(array $frequencies = self::FREQ_TABLE) {
        $this->nodes = [];
        for ($i = 0; $i < self::HUFFMAN_MAX_NODES; $i++) {
            $this->nodes[$i] = new HuffmanNode();
        }

        $this->decode_lut = array_fill(0, self::HUFFMAN_LUTSIZE, 0);
        $this->num_nodes = 0;
        $this->start_node_index = 0;

        $this->construct_tree($frequencies);

        for ($i = 0; $i < self::HUFFMAN_LUTSIZE; $i++) {
            $bits = $i;
            $broke = false;
            $index = $this->start_node_index;
            for ($x = 0; $x < self::HUFFMAN_LUTBITS; $x++) { 
                if ($bits & 1)
                    $index = $this->nodes[$index]->right;
                else
                    $index = $this->nodes[$index]->left;
                $bits >>= 1;
                if ($this->nodes[$index]->numbits) {
                    $this->decode_lut[$i] = $index;
                    $broke = true;
                    break;
                }
            }
            if (!$broke) {
                $this->decode_lut[$i] = $index;
            }
        }
    }

    function set_bits_r(int $node_index, int $bits, int $depth): void {
        if ($this->nodes[$node_index]->right != 0xffff) 
            $this->set_bits_r($this->nodes[$node_index]->right, $bits | (1 << $depth), $depth + 1);
        if ($this->nodes[$node_index]->left != 0xffff)
            $this->set_bits_r($this->nodes[$node_index]->left, $bits, $depth + 1);
        if ($this->nodes[$node_index]->numbits) {
            $this->nodes[$node_index]->bits = $bits;
            $this->nodes[$node_index]->numbits = $depth;
        }
    }

    /**
     * @param array<int, int> $index_list
     * @param array<int, HuffmanConstructNode> $node_list
     * 
     * @return array<int, int>
     */
    function bubble_sort(array $index_list, array $node_list, int $size): array {
        $changed = true;
        while ($changed) {
            $changed = false;
            for ($i = 0; $i < $size-1; $i++) {
                if ($node_list[$index_list[$i]]->frequency < $node_list[$index_list[$i + 1]]->frequency) {
                    $temp = $index_list[$i];
                    $index_list[$i] = $index_list[$i + 1];
                    $index_list[$i + 1] = $temp;
                    $changed = true;
                }
            }
            $size--;
        }
        return $index_list;
    }

    function construct_tree(array $frequencies = self::FREQ_TABLE): void {
        $nodes_left_storage = [];

        for ($i = 0; $i < self::HUFFMAN_MAX_SYMBOLS; $i++) {
            $nodes_left_storage[$i] = new HuffmanConstructNode();
        }

        $nodes_left = array_fill(0, self::HUFFMAN_MAX_SYMBOLS, 0);
        $num_nodes_left = self::HUFFMAN_MAX_SYMBOLS;

        for ($i = 0; $i < self::HUFFMAN_MAX_SYMBOLS; $i++) {
            $this->nodes[$i]->numbits = 0xFFFFFFFF;
            $this->nodes[$i]->symbol = $i;
            $this->nodes[$i]->left = 0xFFFF;
            $this->nodes[$i]->right = 0xFFFF;

            if ($i == self::HUFFMAN_EOF_SYMBOL) {
                $nodes_left_storage[$i]->frequency = 1;
            } else {
                $nodes_left_storage[$i]->frequency = $frequencies[$i];
            }
                
            $nodes_left_storage[$i]->node_id = $i;
            $nodes_left[$i] = $i;
        }

        $this->num_nodes = self::HUFFMAN_MAX_SYMBOLS;

        while ($num_nodes_left > 1) {
            $nodes_left = $this->bubble_sort($nodes_left, $nodes_left_storage, $num_nodes_left);

            $this->nodes[$this->num_nodes]->numbits = 0;
            $this->nodes[$this->num_nodes]->left = $nodes_left_storage[$nodes_left[$num_nodes_left - 1]]->node_id;
            $this->nodes[$this->num_nodes]->right = $nodes_left_storage[$nodes_left[$num_nodes_left - 2]]->node_id;

            $nodes_left_storage[$nodes_left[$num_nodes_left - 2]]->node_id = $this->num_nodes;
            $nodes_left_storage[$nodes_left[$num_nodes_left - 2]]->frequency = $nodes_left_storage[$nodes_left[$num_nodes_left - 1]]->frequency
                                            + $nodes_left_storage[$nodes_left[$num_nodes_left - 2]]->frequency;
            $this->num_nodes++;
            $num_nodes_left--;
        }

        $this->start_node_index = $this->num_nodes-1;
        $this->set_bits_r($this->start_node_index, 0, 0);
    }

    function compress(array $inp_buffer, int $start_index = 0, int $size = 0): array {
        $out_buffer = [];
        $bits = 0;
        $numbits = 0;
        $value = 0;

        if ($size === 0) {
            $size = count($inp_buffer);
        }

        for ($i = $start_index; $i < $size; $i++) {
            $value = $inp_buffer[$i];
            $bits |= $this->nodes[$value]->bits << $numbits;
            $numbits += $this->nodes[$value]->numbits;

            while ($numbits >= 8) {
                $out_buffer[] = $bits & 0xff;
                $bits >>= 8;
                $numbits -= 8;
            }
        }

        $value = self::HUFFMAN_EOF_SYMBOL;

        $bits |= $this->nodes[$value]->bits << $numbits;
        $numbits += $this->nodes[$value]->numbits;

        while ($numbits >= 8) {
            $out_buffer[] = $bits & 0xff;
            $bits >>= 8;
            $numbits -= 8;
        }

        if ($numbits) {
            $out_buffer[] = $bits;
        }

        return $out_buffer;
    }

    function decompress(array $inp_buffer, int $size = 0): array {
        $bits = 0;
        $bitcount = 0;
        $eof = $this->nodes[self::HUFFMAN_EOF_SYMBOL];
        $output = [];
        
        if ($size == 0)
            $size = count($inp_buffer);
        $inp_buffer = array_slice($inp_buffer, 0, $size);
        $src_index = 0;

        while (true) {
            $node_i = -1;

            if ($bitcount >= self::HUFFMAN_LUTBITS)
                $node_i = $this->decode_lut[$bits & self::HUFFMAN_LUTMASK];

            while ($bitcount < 24 && $src_index != $size) {
                $bits |= $inp_buffer[$src_index] << $bitcount;
                $bitcount += 8;
                $src_index++;
            }

            if ($node_i == -1)
                $node_i = $this->decode_lut[$bits & self::HUFFMAN_LUTMASK];
            if ($this->nodes[$node_i]->numbits) {
                $bits >>= $this->nodes[$node_i]->numbits;
                $bitcount -= $this->nodes[$node_i]->numbits;
            } else {
                $bits >>= self::HUFFMAN_LUTBITS;
                $bitcount -= self::HUFFMAN_LUTBITS;
            
                while (true) {
                    if ($bits & 1) {
                        $node_i = $this->nodes[$node_i]->right;
                    } else
                        $node_i = $this->nodes[$node_i]->left;
                    $bitcount -= 1;
                    $bits >>= 1;
                
                    if ($this->nodes[$node_i]->numbits)
                        break;
                    if ($bitcount == 0)
                        // throw new \Exception("No more bits, decoding error");
                        break; // No more bits, decoding error
                }
            }

            if ($this->nodes[$node_i] == $eof)
                break;

            $output[] = $this->nodes[$node_i]->symbol;
        }

        return $output;
    }
}