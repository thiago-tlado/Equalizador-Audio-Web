<?php
    function ifft(array $X): array {
        $N = count($X);
        if ($N == 1) return [$X[0]]; // Corrige retorno para array

        $even = [];
        $odd = [];

        for ($i = 0; $i < $N; $i += 2) {
            $even[] = $X[$i];
            $odd[]  = ($i + 1 < $N) ? $X[$i + 1] : ['re' => 0.0, 'im' => 0.0];
        }

        $Feven = ifft($even);
        $Fodd = ifft($odd);
        $x = array_fill(0, $N, ['re' => 0.0, 'im' => 0.0]);
        $espelho = $N / 2;

        for ($k = 0; $k < $espelho; $k++) {
            $angulo = 2 * M_PI * $k / $N;
            $cos = cos($angulo);
            $sin = sin($angulo);

            $Wre = $Fodd[$k]['re'] * $cos - $Fodd[$k]['im'] * $sin;
            $Wim = $Fodd[$k]['re'] * $sin + $Fodd[$k]['im'] * $cos;

            $x[$k]['re'] = $Feven[$k]['re'] + $Wre;
            $x[$k]['im'] = $Feven[$k]['im'] + $Wim;
            $x[$k + $espelho]['re'] = $Feven[$k]['re'] - $Wre;
            $x[$k + $espelho]['im'] = $Feven[$k]['im'] - $Wim;
        }

        return $x;
    }

    function somaBlocos(array $dados, array $ifft): array {
        $size = count($ifft);
        $len = count($dados);
        $offset = ($len >= $size) ? intval($len - ($size / 2)) : intval(0);

        for ($i = 0; $i < $size; $i++) {
            $pos = intval($i + $offset);            
            if (isset($dados[$pos])) $dados[$pos] += $ifft[$i];
            else $dados[$pos] = $ifft[$i];           
        }

        return $dados;
    }

    function equalizador(array $X, array $bandas, $amostragem): array {
        if(count($bandas) == 0) return $X;        
        $N = count($X);

        for ($k = 1; $k < $N / 2; $k++) {
            $frequencia = $k * $amostragem / $N; 
            foreach ($bandas as $banda) {
                if ($frequencia >= $banda['inicio'] && $frequencia <= $banda['fim']) {
                    switch($banda['tipo']) {
                        case 'distorcao':
                            $X[$k]['re'] = tanh($X[$k]['re'] * $banda['valor']);
                            $X[$k]['im'] = tanh($X[$k]['im'] * $banda['valor']);
                        break;
                        default:
                            $X[$k]['re'] *= $banda['valor'];
                            $X[$k]['im'] *= $banda['valor'];
                        break;
                    }

                    $X[$N - $k]['re'] =  $X[$k]['re'];
                    $X[$N - $k]['im'] = -$X[$k]['im'];
                }

                if($banda['inicio'] == 0 && $k == 1) {
                    $X[0]['re'] *= $banda['valor'];
                    $X[0]['im'] *= $banda['valor'];
                }
            }
        }

        return $X;
    }

    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['nome'])) {
        echo json_encode(['erro' => 'Nome do arquivo não fornecido']);
        exit;
    }

    $dir = __DIR__ . '/arquivos/' . pathinfo($input['nome'], PATHINFO_FILENAME) . '/';
    $equa = $dir . 'originais/';
    $janelasFile = $dir . 'janelas.json';
    $infosFile = $dir . 'infos.json';
    $recFile = $dir . 'reconstruido.json';
    $specFile = $dir . 'spectro.json';

    if (!is_dir($equa) || !file_exists($janelasFile) || !file_exists($infosFile)) {
        echo json_encode(['erro' => 'Arquivos necessários não encontrados']);
        exit;
    }

    $janelas = json_decode(file_get_contents($janelasFile), true);
    $infos = json_decode(file_get_contents($infosFile), true);
    $files = glob($equa . '*.json');
    sort($files, SORT_NATURAL);

    $cnt = 0;
    $dados = [];
    $spectros = [];

    if(isset($input['processados'])) {
        $cnt = $input['processados'];
        $spectros = array_fill(0, intval(count($janelas) / 2), 0);
    } else {
        $cnt = $infos['processados'];
        $dados = file_exists($recFile) ? json_decode(file_get_contents($recFile), true) : [];
        $spectros = file_exists($specFile) ? json_decode(file_get_contents($specFile), true) : array_fill(0, intval(count($janelas) / 2), 0);
    }

    if ($cnt < $infos['blocos']) {
        $bandas = (isset($input['bandas'])) ? $input['bandas'] : [];
        $X = json_decode(file_get_contents($files[$cnt]), true);   

        $cnt++;
        $infos['processados'] = $cnt;  
        $limit = count($X);     

        for ($i = 0; $i < $limit; $i++) {
            $equalizado = equalizador($X[$i], $bandas, $infos['amostragem']);
            $lenEq = count($equalizado);

            for ($n = 0; $n < $lenEq / 2; $n++) {
                $spectros[$n] += (($equalizado[$n]['re']**2 + $equalizado[$n]['im']**2) / 0.375);
            }

            $ifft = ifft($equalizado);
            $lenIfft = count($ifft);
            for($n = 0; $n < $lenIfft; $n++) $ifft[$n] = $ifft[$n]['re'] / $lenIfft;           
            $dados = somaBlocos($dados, $ifft);            
        }

        file_put_contents($infosFile, json_encode($infos, JSON_PRETTY_PRINT));
        file_put_contents($recFile, json_encode($dados));
        file_put_contents($specFile, json_encode($spectros));
    }

    if ($cnt < $infos['blocos']) {
        echo json_encode(['processo' => true,
                    'blocos' => $infos['blocos'],
                    'processados' => $cnt]);  
    } else {
        $N = count($janelas);
        $numBins = intval($N / 2);
        $frequencias = [];
        $spectrosBI = array_fill(0, $N, 0);
        $frequenciasBI = array_fill(0, $N, 0);
        $len = count($dados);

        for ($k = 0; $k < $numBins; $k++) {
            $spectros[$k] = sqrt($spectros[$k] / $infos['total']) * (2 / $N);
            $frequencias[$k] = intval(round($k * $infos['amostragem'] / $N));
            $spectrosBI[$k + $numBins] = $spectros[$k];
            $spectrosBI[$numBins - $k] = $spectros[$k];
            $frequenciasBI[$k + $numBins] = $frequencias[$k];
            $frequenciasBI[$numBins - $k] = -$frequencias[$k];
        }

        $dados = array_slice($dados, $numBins, -($numBins + $infos['zeros'])); 
        echo json_encode(['processo' => false,
                         'amostragem' => $infos['amostragem'],
                         'reconstruido' => $dados,
                         'magnitudes' => $spectros,
                         'frequencias' => $frequencias,
                         'magnitudesBI' => $spectrosBI,
                         'frequenciasBI' => $frequenciasBI
        ]);      
    }