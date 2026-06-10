<?php
    function asset($path) {
        $base = rtrim(BASE_URL, '/');
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($path, '/');
        if (file_exists($fullPath)) {
            $version = filemtime($fullPath);
        } else {
            $version = time();
        }
        return $base . '/' . ltrim($path, '/') . '?v=' . $version;
    }