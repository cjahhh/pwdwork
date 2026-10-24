<?php

return [
    // Laravel reads this after configuration has loaded. An empty list trusts no proxy.
    'proxies' => array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', ''))))),
];
