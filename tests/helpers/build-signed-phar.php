<?php

declare(strict_types=1);

if ($argc !== 2 || !is_string($argv[1]) || $argv[1] === '') {
    fwrite(STDERR, "Usage: build-signed-phar.php <destination>\n");
    exit(64);
}
$destination = $argv[1];
if (is_file($destination)) {
    fwrite(STDERR, "Refusing to overwrite an existing PHAR.\n");
    exit(65);
}

try {
    $phar = new Phar($destination);
    $phar->startBuffering();
    $phar->addFromString('payload.php', <<<'PHP'
<?php

declare(strict_types=1);

echo "Bedriox signed PHAR passed.\n";
PHP);
    $phar->setStub("#!/usr/bin/env php\n<?php Phar::mapPhar('qualification.phar'); require 'phar://qualification.phar/payload.php'; __HALT_COMPILER();");
    $phar->setSignatureAlgorithm(Phar::SHA256);
    $phar->stopBuffering();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Could not create signed PHAR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
