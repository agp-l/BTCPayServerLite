<?php

declare(strict_types=1);

// Compatibility entry point: the router redirects to the authenticated admin page.
// A 308 redirect preserves POST data from forms opened before the upgrade.
require __DIR__ . '/index.php';
