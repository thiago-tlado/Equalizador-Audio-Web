<?php
    function janelaHann(int $N): array {
        $w = [];        
        for ($n = 0; $n < $N; $n++) $w[] = 0.5 * (1 - cos(2 * M_PI * $n / ($N - 1)));  
        return $w;
    }

    function frameSinal(array $sinal, int $N, array $janela, int &$zeros): array {
        $shift = intval($N / 2);
        $len = intval(count($sinal));
        $resto = ($len % $N);
        $zeros = ($resto > 0) ? $N - $resto : 0;

        $ini = [];
        $fim = [];

        // Espelho das bordas
        for ($i = 0; $i < $shift; $i++) {
            $ini[] = $sinal[$shift - 1 - $i];  
            $fim[] = $sinal[$len - 1 - $i];
        }
   
        $sinal = array_merge($ini, $sinal, $fim);   
        $len = count($sinal);
        for ($i = 0; $i < $zeros; $i++) $sinal[] = $sinal[$len - 1 - $i];    
        $len = count($sinal); 
        $frames = [];

        for ($i = 0; $i + $N <= $len; $i += $shift) {
            $frame = [];
            for ($k = 0; $k < $N; $k++) $frame[] = $sinal[$i + $k] * $janela[$k];            
            $frames[] = $frame;
        }

        return $frames;
    }

    function fft(array $x): array {
        $N = count($x);

        // Caso base
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

        $Feven = fft($even);
        $Fodd  = fft($odd);

        $X = array_fill(0, $N, ['re' => 0.0, 'im' => 0.0]);
        $half = intdiv($N, 2);

        for ($k = 0; $k < $half; $k++) {
            $angle = -2 * M_PI * $k / $N;
            $cos = cos($angle);
            $sin = sin($angle);

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

    function magnitudeSpectros(string $local, int $N, int $amostragem): array {
        if (!is_dir($local)) return ['frequencias' => [0], 'magnitudes' => [0]];
        $files = glob(rtrim($local, '/\\') . '/*.json');
        sort($files, SORT_NATURAL);

        $numBins     = $N / 2;
        $magnitudes  = array_fill(0, $numBins, 0);
        $frequencias = [];
        $numArquivos = count($files);
        $cntFrames = 0;

        for ($i = 0; $i < $numArquivos; $i++) {
            $frames = json_decode(file_get_contents($files[$i]), true);
            $numFrames = count($frames);
            $cntFrames += $numFrames;

            for ($j = 0; $j < $numFrames; $j++) {
                $X = $frames[$j];

                for ($k = 0; $k < $numBins; $k++) {
                    $espectro = ($X[$k]['re']**2 + $X[$k]['im']**2) / 0.375;
                    $magnitudes[$k] += $espectro;
                }
            }
        }

        if($cntFrames > 0) {
            for ($k = 0; $k < $numBins; $k++) {
                $magnitudes[$k] = sqrt($magnitudes[$k] / $cntFrames) * (2 / $N);
                $frequencias[$k] = intval(round($k * $amostragem / $N));
            }
        }
        
        return ['frequencias' => $frequencias, 'magnitudes' => $magnitudes];
    }       

    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);  
    $info = [];
    $qtdBlocos = 50;

    if(isset($input['nome'])) {  
        $dir = __DIR__ . '/arquivos/'. pathinfo($input['nome'], PATHINFO_FILENAME) . '/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $orig = $dir . 'originais/';

        if(isset($input['dados'])) {
            $dados = $input['dados'];
            $blocoSize = (isset($input['blockSize'])) ? intval($input['blockSize']) : 2048;

            $janelas = janelaHann($blocoSize);
            $zeros = 0;
            $frames = frameSinal($dados, $blocoSize, $janelas, $zeros);
            $info = ["cnt" => 0, "total" => count($frames), "amostragem" => intval($input['amostragem']),
                    "blocos" => 0, "processados" => 0, "blocoSize" => $blocoSize,
                    "amostras" => count($dados) + $zeros + $blocoSize, "zeros" => $zeros];
            
            file_put_contents($dir . 'infos.json', json_encode($info, JSON_PRETTY_PRINT));
            file_put_contents($dir . 'janelas.json', json_encode($janelas));
            file_put_contents($dir . 'frames.json', json_encode($frames));
            
            if (!is_dir($orig)) mkdir($orig, 0777, true);
            echo json_encode(["processo" => true]);
        } else {
            $info = json_decode(file_get_contents($dir . 'infos.json'), true);
            $frames = json_decode(file_get_contents($dir . 'frames.json'), true);
            $cnt = (isset($info['cnt']) ? intval($info['cnt']) : 0);
            $total = (isset($info['total']) ? intval($info['total']) : 0);

            $blocoSize = isset($info['blocoSize']) ? intval($info['blocoSize']) : 2048;
            $X = [];

            if($cnt < $total) {
                $limit = min($cnt + $qtdBlocos, $total);

                for ($i = $cnt; $i < $limit; $i++) {
                    $info['cnt'] = $i + 1;
                    $espectro = fft($frames[$i]);
                    $X[] = $espectro;
                }
            }

            $info['blocos']++;
            file_put_contents($dir . 'infos.json', json_encode($info));
            file_put_contents($orig . $cnt . '.json', json_encode($X));

            if ($info['cnt'] >= $total) {
                $amostragem = $info['amostragem'];
                $originais = magnitudeSpectros($orig, $blocoSize, $amostragem);
                $equalizados = $originais;
  
                echo json_encode(["processo" =>  false, 
                                "grupos" => $info['blocos'],
                                "tamanhoGrupo" => $qtdBlocos,
                                "tamanhoBloco" => $blocoSize,
                                "blocos" => $info['total'],
                                "amostras" => $info['amostras'],
                                "frequencias" => $originais['frequencias'], 
                                "magnitudes" => $originais['magnitudes']]);  
            } else {
                echo json_encode(["processo" => true, 
                                "blocos" => $info['blocos'],
                                "cnt" => $info['cnt'], 
                                "total" => $info['total']]);  
            }  
        }
    } else {
        echo json_encode(["erro" => "Nome do arquivo não fornecido"]);
        exit;   
    }