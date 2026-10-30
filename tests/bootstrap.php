<?php

require __DIR__ . '/../vendor/autoload.php';

// Framework helpers are supplied by Laravel applications, not illuminate/support.
if (!function_exists('app')) {
    function app($abstract = null, array $parameters = [])
    {
        $app = \Illuminate\Container\Container::getInstance();
        return $abstract === null ? $app : $app->make($abstract, $parameters);
    }
}
if (!function_exists('resolve')) {
    function resolve($abstract, array $parameters = []) { return app($abstract, $parameters); }
}
if (!function_exists('config')) {
    function config($key = null, $default = null)
    {
        if (is_array($key)) { return app('config')->set($key); }
        return $key === null ? app('config') : app('config')->get($key, $default);
    }
}
if (!function_exists('config_path')) {
    function config_path($path = '') { return '/tmp/opencode/scout-solr-config/' . $path; }
}
