<?php
// detect_lang: 'en'|'ru' по Accept-Language (по умолчанию en)
function detect_lang(): string {
    static $l = null;
    if ($l !== null) return $l;
    $al = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'en'));
    $l = str_starts_with($al, 'ru') ? 'ru' : 'en';
    return $l;
}
