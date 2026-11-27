<?php

namespace App\Support;

/**
 * Pure-PHP, zero-dependency, offline ISO/IEC 18004 QR Code SVG generator (Byte mode, EC Level L, Versions 1–10).
 * Produces crisp monochrome black-on-white SVG suitable for 80mm thermal printers.
 */
final class QrCodeSvg
{
    /** @var array<int, array{size: int, dataCodewords: int, ecPerBlock: int, blocks: list<array{count: int, data: int}>, align: list<int>}> */
    private const VERSIONS = [
        1 => ['size' => 21, 'dataCodewords' => 19, 'ecPerBlock' => 7, 'blocks' => [['count' => 1, 'data' => 19]], 'align' => []],
        2 => ['size' => 25, 'dataCodewords' => 34, 'ecPerBlock' => 10, 'blocks' => [['count' => 1, 'data' => 34]], 'align' => [6, 18]],
        3 => ['size' => 29, 'dataCodewords' => 55, 'ecPerBlock' => 15, 'blocks' => [['count' => 1, 'data' => 55]], 'align' => [6, 22]],
        4 => ['size' => 33, 'dataCodewords' => 80, 'ecPerBlock' => 20, 'blocks' => [['count' => 1, 'data' => 80]], 'align' => [6, 26]],
        5 => ['size' => 37, 'dataCodewords' => 108, 'ecPerBlock' => 26, 'blocks' => [['count' => 1, 'data' => 108]], 'align' => [6, 30]],
        6 => ['size' => 41, 'dataCodewords' => 136, 'ecPerBlock' => 18, 'blocks' => [['count' => 2, 'data' => 68]], 'align' => [6, 34]],
        7 => ['size' => 45, 'dataCodewords' => 156, 'ecPerBlock' => 20, 'blocks' => [['count' => 2, 'data' => 78]], 'align' => [6, 22, 38]],
        8 => ['size' => 49, 'dataCodewords' => 194, 'ecPerBlock' => 24, 'blocks' => [['count' => 2, 'data' => 97]], 'align' => [6, 24, 42]],
        9 => ['size' => 53, 'dataCodewords' => 232, 'ecPerBlock' => 30, 'blocks' => [['count' => 2, 'data' => 116]], 'align' => [6, 26, 46]],
        10 => ['size' => 57, 'dataCodewords' => 274, 'ecPerBlock' => 18, 'blocks' => [['count' => 2, 'data' => 68], ['count' => 2, 'data' => 69]], 'align' => [6, 28, 50]],
    ];

    /** Format info bitstrings for EC Level L (01) and masks 0..7. */
    private const FORMAT_BITS_L = [
        0 => 0x77C4,
        1 => 0x72F3,
        2 => 0x7DAA,
        3 => 0x789D,
        4 => 0x662F,
        5 => 0x6318,
        6 => 0x6C41,
        7 => 0x6976,
    ];

    /** Version info bitstrings for versions 7..10. */
    private const VERSION_BITS = [
        7 => 0x07C94,
        8 => 0x085BC,
        9 => 0x09A99,
        10 => 0x0A4D3,
    ];

    public static function svg(string $data, int $px = 136, int $quiet = 2): string
    {
        $matrix = self::encode($data);
        $n = count($matrix);
        $total = $n + ($quiet * 2);

        $path = '';
        for ($y = 0; $y < $n; $y++) {
            $row = $matrix[$y];
            for ($x = 0; $x < $n; $x++) {
                if ($row[$x]) {
                    $dx = $x + $quiet;
                    $dy = $y + $quiet;
                    $path .= "M{$dx},{$dy}h1v1h-1z";
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%2$d" height="%2$d" shape-rendering="crispEdges" role="img" aria-label="کد QR پیگیری آنلاین تعمیر"><rect width="%1$d" height="%1$d" fill="#ffffff"/><path d="%3$s" fill="#000000"/></svg>',
            $total,
            $px,
            $path,
        );
    }

    /**
     * @return list<list<bool>>
     */
    public static function encode(string $data): array
    {
        $bytes = array_values(unpack('C*', $data) ?: []);
        $len = count($bytes);

        $version = null;
        foreach (self::VERSIONS as $v => $spec) {
            $countBits = $v <= 9 ? 8 : 16;
            $capacityBits = $spec['dataCodewords'] * 8;
            if ((4 + $countBits + ($len * 8)) <= $capacityBits) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new \InvalidArgumentException('QR payload exceeds supported Version 10 capacity.');
        }

        $spec = self::VERSIONS[$version];
        $bits = [];
        // Byte mode indicator: 0100
        self::pushBits($bits, 0b0100, 4);
        self::pushBits($bits, $len, $version <= 9 ? 8 : 16);
        foreach ($bytes as $b) {
            self::pushBits($bits, $b, 8);
        }

        $capBits = $spec['dataCodewords'] * 8;
        $term = min(4, $capBits - count($bits));
        self::pushBits($bits, 0, $term);
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $dataCodewords = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $val = 0;
            for ($j = 0; $j < 8; $j++) {
                $val = ($val << 1) | $bits[$i + $j];
            }
            $dataCodewords[] = $val;
        }
        $padBytes = [0xEC, 0x11];
        $padIdx = 0;
        while (count($dataCodewords) < $spec['dataCodewords']) {
            $dataCodewords[] = $padBytes[$padIdx % 2];
            $padIdx++;
        }

        // Split into RS blocks and interleave data + EC codewords
        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        $gen = self::rsGenerator($spec['ecPerBlock']);
        foreach ($spec['blocks'] as $grp) {
            for ($b = 0; $b < $grp['count']; $b++) {
                $chunk = array_slice($dataCodewords, $offset, $grp['data']);
                $offset += $grp['data'];
                $dataBlocks[] = $chunk;
                $ecBlocks[] = self::rsRemainder($chunk, $gen);
            }
        }

        $finalBytes = [];
        $maxDataLen = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $finalBytes[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $spec['ecPerBlock']; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $finalBytes[] = $block[$i];
                }
            }
        }

        $n = $spec['size'];
        $modules = array_fill(0, $n, array_fill(0, $n, false));
        $isFunc = array_fill(0, $n, array_fill(0, $n, false));

        // Finder patterns + separators
        self::drawFinder($modules, $isFunc, 0, 0);
        self::drawFinder($modules, $isFunc, $n - 7, 0);
        self::drawFinder($modules, $isFunc, 0, $n - 7);

        // Timing patterns
        for ($i = 8; $i < $n - 8; $i++) {
            $dark = ($i % 2) === 0;
            $modules[6][$i] = $dark;
            $isFunc[6][$i] = true;
            $modules[$i][6] = $dark;
            $isFunc[$i][6] = true;
        }

        // Alignment patterns
        $align = $spec['align'];
        $alignCount = count($align);
        for ($i = 0; $i < $alignCount; $i++) {
            for ($j = 0; $j < $alignCount; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $alignCount - 1) || ($i === $alignCount - 1 && $j === 0)) {
                    continue;
                }
                self::drawAlignment($modules, $isFunc, $align[$i], $align[$j]);
            }
        }

        // Dark module
        $modules[$n - 8][8] = true;
        $isFunc[$n - 8][8] = true;

        // Reserve format info areas
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $isFunc[8][$i] = true;
                $isFunc[$i][8] = true;
            }
        }
        for ($i = $n - 8; $i < $n; $i++) {
            $isFunc[8][$i] = true;
            $isFunc[$i][8] = true;
        }

        // Version info (versions 7..10)
        if ($version >= 7) {
            $vBits = self::VERSION_BITS[$version];
            for ($i = 0; $i < 18; $i++) {
                $bit = (($vBits >> $i) & 1) === 1;
                $a = intdiv($i, 3);
                $b = ($n - 11) + ($i % 3);
                $modules[$a][$b] = $bit;
                $isFunc[$a][$b] = true;
                $modules[$b][$a] = $bit;
                $isFunc[$b][$a] = true;
            }
        }

        // Place data bits in zigzag
        $bitIdx = 0;
        $totalBits = count($finalBytes) * 8;
        $upward = true;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $n; $vert++) {
                $y = $upward ? ($n - 1 - $vert) : $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (! $isFunc[$y][$x]) {
                        $dark = false;
                        if ($bitIdx < $totalBits) {
                            $dark = (($finalBytes[$bitIdx >> 3] >> (7 - ($bitIdx & 7))) & 1) === 1;
                            $bitIdx++;
                        }
                        $modules[$y][$x] = $dark;
                    }
                }
            }
            $upward = ! $upward;
        }

        // Choose best mask (0..7) using penalty score
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        $bestMatrix = $modules;
        for ($mask = 0; $mask < 8; $mask++) {
            $cand = $modules;
            self::applyMask($cand, $isFunc, $mask);
            self::drawFormat($cand, self::FORMAT_BITS_L[$mask]);
            $score = self::penalty($cand);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
                $bestMatrix = $cand;
            }
        }

        return $bestMatrix;
    }

    /** @param list<int> $bits */
    private static function pushBits(array &$bits, int $val, int $len): void
    {
        for ($i = $len - 1; $i >= 0; $i--) {
            $bits[] = ($val >> $i) & 1;
        }
    }

    /** @return list<int> */
    private static function rsGenerator(int $degree): array
    {
        $poly = [1];
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $next[$j] ^= $poly[$j];
                $next[$j + 1] ^= self::gfMul($poly[$j], $root);
            }
            $poly = $next;
            $root = self::gfMul($root, 2);
        }

        return $poly;
    }

    /**
     * @param  list<int>  $data
     * @param  list<int>  $gen
     * @return list<int>
     */
    private static function rsRemainder(array $data, array $gen): array
    {
        $deg = count($gen) - 1;
        $rem = array_fill(0, $deg, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($rem);
            $rem[] = 0;
            for ($i = 0; $i < $deg; $i++) {
                $rem[$i] ^= self::gfMul($gen[$i + 1], $factor);
            }
        }

        return $rem;
    }

    private static function gfMul(int $a, int $b): int
    {
        $res = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($b & 1) !== 0) {
                $res ^= $a;
            }
            $hi = ($a & 0x80) !== 0;
            $a = ($a << 1) & 0xFF;
            if ($hi) {
                $a ^= 0x1D;
            }
            $b >>= 1;
        }

        return $res;
    }

    /**
     * @param  list<list<bool>>  $m
     * @param  list<list<bool>>  $f
     */
    private static function drawFinder(array &$m, array &$f, int $ox, int $oy): void
    {
        $n = count($m);
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $x = $ox + $dx;
                $y = $oy + $dy;
                if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) {
                    continue;
                }
                $inOuter = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                $onBorder = $dx === 0 || $dx === 6 || $dy === 0 || $dy === 6;
                $inInner = $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4;
                $m[$y][$x] = $inOuter && ($onBorder || $inInner);
                $f[$y][$x] = true;
            }
        }
    }

    /**
     * @param  list<list<bool>>  $m
     * @param  list<list<bool>>  $f
     */
    private static function drawAlignment(array &$m, array &$f, int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $m[$cy + $dy][$cx + $dx] = ($dist !== 1);
                $f[$cy + $dy][$cx + $dx] = true;
            }
        }
    }

    /**
     * @param  list<list<bool>>  $m
     * @param  list<list<bool>>  $f
     */
    private static function applyMask(array &$m, array $f, int $mask): void
    {
        $n = count($m);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($f[$y][$x]) {
                    continue;
                }
                $invert = match ($mask) {
                    0 => (($x + $y) % 2) === 0,
                    1 => ($y % 2) === 0,
                    2 => ($x % 3) === 0,
                    3 => (($x + $y) % 3) === 0,
                    4 => ((intdiv($y, 2) + intdiv($x, 3)) % 2) === 0,
                    5 => (($x * $y) % 2) + (($x * $y) % 3) === 0,
                    6 => (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0,
                    7 => (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0,
                    default => false,
                };
                if ($invert) {
                    $m[$y][$x] = ! $m[$y][$x];
                }
            }
        }
    }

    /** @param list<list<bool>> $m */
    private static function drawFormat(array &$m, int $bits): void
    {
        $n = count($m);
        for ($i = 0; $i <= 5; $i++) {
            $m[8][$i] = (($bits >> (14 - $i)) & 1) === 1;
        }
        $m[8][7] = (($bits >> 8) & 1) === 1;
        $m[8][8] = (($bits >> 7) & 1) === 1;
        $m[7][8] = (($bits >> 6) & 1) === 1;
        for ($i = 9; $i < 15; $i++) {
            $m[14 - $i][8] = (($bits >> (14 - $i)) & 1) === 1;
        }
        for ($i = 0; $i < 8; $i++) {
            $m[$n - 1 - $i][8] = (($bits >> (14 - $i)) & 1) === 1;
        }
        for ($i = 8; $i < 15; $i++) {
            $m[8][$n - 15 + $i] = (($bits >> (14 - $i)) & 1) === 1;
        }
    }

    /** @param list<list<bool>> $m */
    private static function penalty(array $m): int
    {
        $n = count($m);
        $score = 0;
        for ($y = 0; $y < $n; $y++) {
            $runH = 1;
            $runV = 1;
            for ($x = 1; $x < $n; $x++) {
                if ($m[$y][$x] === $m[$y][$x - 1]) {
                    $runH++;
                } else {
                    if ($runH >= 5) {
                        $score += 3 + ($runH - 5);
                    }
                    $runH = 1;
                }
                if ($m[$x][$y] === $m[$x - 1][$y]) {
                    $runV++;
                } else {
                    if ($runV >= 5) {
                        $score += 3 + ($runV - 5);
                    }
                    $runV = 1;
                }
            }
            if ($runH >= 5) {
                $score += 3 + ($runH - 5);
            }
            if ($runV >= 5) {
                $score += 3 + ($runV - 5);
            }
        }
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        return $score;
    }
}
