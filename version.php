<?php
define('KUTSOCIAL_VERSION', '2.1.37');

// Polyfill para servidores sin la extensión mbstring
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        return strlen($string);
    }
}
