<?php
    function janelaHann(int $N): array {
        $w = [];        
        for ($n = 0; $n < $N; $n++) $w[] = 0.5 * (1 - cos(2 * M_PI * $n / ($N - 1)));  
        return $w;
    }

    function frameSinal(array $sinal, int $N, array $janela, int &$padding): array {
        $len = intval(count($sinal));
        $resto = ($len % $N);
        $padding = ($resto > 0) ? $N - $resto : 0;
        $half = intval($N / 2);
        $ini = [];
        $fim = [];

        for ($i = 0; $i < $half; $i++) {
            $ini[] = $sinal[$half - 1 - $i];  
            $fim[] = $sinal[$len - 1 - $i];
        }
   
        for ($i = 0; $i < $padding; $i++) $fim[] = $sinal[$len - 1 - $i];   
        $sinal = array_merge($ini, $sinal, $fim);   
        $len = count($sinal);
        $frames = [];

        for ($i = 0; $i + $N <= $len; $i += $half) {
            $frame = [];
            for ($k = 0; $k < $N; $k++) $frame[] = $sinal[$i + $k] * $janela[$k];            
            $frames[] = $frame;
        }

        return $frames;
    }

    function fft(array $x, int $sinal): array {
        $N = count($x);

        if ($N == 1) {
            if (is_array($x[0])) return [$x[0]];
            else return [['re' => $x[0], 'im' => 0.0]];            
        }

        $even = [];
        $odd  = [];

        for ($i = 0; $i < $N; $i += 2) {
            $even[] = is_array($x[$i]) ? $x[$i] : ['re' => $x[$i], 'im' => 0.0];

            if ($i + 1 < $N) $odd[] = is_array($x[$i + 1]) ? $x[$i + 1] : ['re' => $x[$i + 1], 'im' => 0.0];
            else $odd[] = ['re' => 0.0, 'im' => 0.0];                       
        }

        $Feven = fft($even, $sinal);
        $Fodd  = fft($odd, $sinal);
        $X = array_fill(0, $N, ['re' => 0.0, 'im' => 0.0]);
        $half = intdiv($N, 2);

        for ($k = 0; $k < $half; $k++) {
            $angulo = $sinal * 2 * M_PI * $k / $N;
            $cos = cos($angulo);
            $sin = sin($angulo);
            // W * Fodd[k]
            $Wre = $Fodd[$k]['re'] * $cos - $Fodd[$k]['im'] * $sin;
            $Wim = $Fodd[$k]['re'] * $sin + $Fodd[$k]['im'] * $cos;
            // Borboleta
            $X[$k]['re'] = $Feven[$k]['re'] + $Wre;
            $X[$k]['im'] = $Feven[$k]['im'] + $Wim;
            $X[$k + $half]['re'] = $Feven[$k]['re'] - $Wre;
            $X[$k + $half]['im'] = $Feven[$k]['im'] - $Wim;
        }

        return $X;
    }

    function equalizadorWeb($X, $bandas, $rate): array {
        $N = count($X);
        $len = count($bandas);
        if($len == 0) return $X;        

        for ($k = 1; $k < $N / 2; $k++) {
            $frequencia = $k * $rate / $N; 

            for ($i = 0; $i < $len; $i++) {
                $banda = $bandas[$i];

                if($banda['inicio'] == 0 && $k == 1) {
                    $X[$k]['re'] *= $banda['ganho'];
                    $X[$k]['im'] *= $banda['ganho'];
                }

                if ($frequencia >= $banda['inicio'] && $frequencia <= $banda['fim']) {
                    $X[$k]['re'] *= $banda['ganho'];
                    $X[$k]['im'] *= $banda['ganho'];
                    $X[$N - $k]['re'] =  $X[$k]['re'];
                    $X[$N - $k]['im'] = -$X[$k]['im'];
                }
            }
        }   

        return $X;
    }

    function somaBlocos(array $dados, array $frame, int $frameIndex): array {
        $N = count($frame);
        $offset = $frameIndex * ($N / 2);

        for ($i = 0; $i < $N; $i++) {
            $pos = $i + $offset;
            $dados[$pos] = ($dados[$pos] ?? 0) + ($frame[$i]['re'] / $N);
        }
        return $dados;
    }

    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true); 
    $sinal = isset($input['dados']) ? $input['dados'] : []; 
    $bandas = isset($input['bandas']) ? $input['bandas'] : [];
    $rate = isset($input['rate']) ? $input['rate'] : 44100;

    $padding = 0;
    $N = 2048;

    $janela = janelaHann($N);
    $frames = frameSinal($sinal, $N, $janela, $padding);
    $size = count($frames);
    $dados = [];

    for ($k = 0; $k < $size; $k++) {
        $frames[$k] = fft($frames[$k], -1); // FFT
        $frames[$k] = equalizadorWeb($frames[$k], $bandas, $rate);
        $frames[$k] = fft($frames[$k], 1); // IFFT
        $dados = somaBlocos($dados, $frames[$k], $k);
    }

    $numBins = intval($N / 2);
    $dados = array_slice($dados, $numBins, -($numBins + $padding));

    echo json_encode([
        'frames' => $size,
        'padding' => $padding,
        'dados' => $dados,
        'rate' => $rate
    ]);