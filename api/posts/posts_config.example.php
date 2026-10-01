<?php
/**
 * Config opcional do módulo de posts. Copie para posts_config.php (que é
 * gitignorado) e preencha se precisar.
 *
 * `ffmpeg_bin`: caminho do ffmpeg usado para converter imagens que o
 * navegador não exibe (HEIC/TIFF/BMP/AVIF) para JPG no upload do post. O
 * PHP nem sempre enxerga o PATH, por isso o caminho explícito. Sem este
 * arquivo (ou sem a chave), cai no `ffmpeg` do PATH; sem ffmpeg nenhum, o
 * post segue com a imagem original (só não converte os formatos exóticos).
 */

return [
    // Ex. no Windows/XAMPP: "C:/ffmpeg/bin/ffmpeg.exe"
    "ffmpeg_bin" => "",
];
