<?php
// Deployment example only. Copy to private/update/trust.php after filling it.
// Keep an existing site's trust.php; never upload a signing PRIVATE key.
return [
    'public_key' => <<<'PEM'
PASTE_THE_COMPLETE_RELEASE_PUBLIC_KEY_PEM_HERE
PEM,
    'source_url' => 'https://YOUR-STATIC-RELEASE-SOURCE.example',
    'probe_base_url' => 'https://YOUR-WEBSITE.example',
    'backup_retention' => 3,
    'allow_loopback_source' => false,
];
