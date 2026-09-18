<?php

return [
    'tenant' => env('AZURE_TENANT_ID'),
    'client_id' => env('AZURE_CLIENT_ID'),
    'client_secret' => env('AZURE_CLIENT_SECRET'),
    'redirect' => env('AZURE_REDIRECT_URI'),
    'auto_provision' => env('AZURE_AUTO_PROVISION', false),
    'link_existing_users' => env('AZURE_LINK_EXISTING_USERS', false),
    'default_role' => env('AZURE_DEFAULT_ROLE'),
];
