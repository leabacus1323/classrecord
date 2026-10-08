<?php
/**
 * MyGradebook local asset loader.
 * Serves CSS/JS inline from local files so the app does not depend on
 * browser requests to /assets for critical styling and scripts.
 */
function mygradebook_asset(string $filename): string {
    $base = realpath(__DIR__ . '/../assets');
    if ($base === false) return '';

    $file = realpath($base . DIRECTORY_SEPARATOR . $filename);
    if ($file === false || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
        return '';
    }

    $contents = file_get_contents($file);
    return $contents === false ? '' : $contents;
}
