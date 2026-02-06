<?php
    $dir = __DIR__ . '/audios/';
    $files = glob($dir . '*');

    if (count($files) > 5) {
        usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
        @unlink($files[0]);
    }

    $arq = __DIR__ . '/arquivos/';
    $folders = glob($arq . '*', GLOB_ONLYDIR);

    if (count($folders) > 5) {  
        usort($folders, function($a, $b) { return filemtime($a) - filemtime($b); });

        $it = new RecursiveDirectoryIterator($folders[0], RecursiveDirectoryIterator::SKIP_DOTS);
        $filesIt = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        
        foreach($filesIt as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($folders[0]);
    }