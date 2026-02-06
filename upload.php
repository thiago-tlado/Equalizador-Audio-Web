<?php
    include_once 'folders.php';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    if (!is_dir($arq)) mkdir($arq, 0777, true);

    header('Content-Type: application/json');
    date_default_timezone_set('America/Sao_Paulo');

    if (isset($_FILES['audioFile']) && $_FILES['audioFile']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['audioFile']['tmp_name'];
        $formato = isset($_POST['formato']) ? preg_replace('/[^a-z0-9]/i', '', $_POST['formato']) : 'webm';
        $fileName = $_FILES['audioFile']['name'];
       
        if($fileName == ('.'.$formato))  $fileName = date('dmy_His') . '.' . $formato;        
        $dest = $dir . $fileName; 
        
        if (move_uploaded_file($tmpName, $dest)) {
            echo json_encode([
                "sucesso" => true,
                "arquivo" => $fileName
            ]);
        } else {
            echo json_encode([ "sucesso" => false ]);
        }
    } else 
        echo json_encode([ "sucesso" => false ]);
?>