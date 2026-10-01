<?php
$out = shell_exec('php vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline phpstan-baseline.neon 2>&1');
echo $out;
