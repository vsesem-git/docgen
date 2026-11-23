<?php
declare(strict_types=1);

// Only this directory is exposed by the PHP test server. Application code,
// record data, saved templates, queue files and SMTP secrets stay outside it.
require dirname(__DIR__) . '/index.php';
