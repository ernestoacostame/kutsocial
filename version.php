<?php
define('KUTSOCIAL_VERSION', '2.0.15');

// Polyfill para servidores sin la extensión mbstring
if (!function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null) {
        return strlen($string);
    }
}
