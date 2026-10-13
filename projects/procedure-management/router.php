<?php
// Только для встроенного PHP-сервера: php -S 127.0.0.1:8080 router.php
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/');
if(preg_match('~^/(assets|public)/[a-zA-Z0-9_./-]+\.(css|js|png|pdf)$~',$path) && !str_contains($path,'..') && is_file(__DIR__.$path))return false;
if($path==='/' || $path==='/index.php'){require __DIR__.'/index.php';return true;}
http_response_code(404);echo 'Not found';return true;
