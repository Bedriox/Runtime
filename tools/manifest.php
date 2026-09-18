<?php

declare(strict_types=1);

require_once __DIR__ . '/ManifestModel.php';

try {
    $manifest = ManifestModel::load(__DIR__ . '/../manifests/dependencies.json');
    $arguments = array_slice($argv, 1);
    if ($arguments === ['targets']) {
        echo json_encode($manifest->targets(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    if (count($arguments) === 2 && $arguments[0] === 'closure') {
        echo json_encode($manifest->targetClosure($arguments[1]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    if (count($arguments) !== 3 || $arguments[0] !== 'source') {
        throw new InvalidArgumentException('Usage: php tools/manifest.php targets | closure <target> | source <id> <field>');
    }
    $value = $manifest->sourceField($arguments[1], $arguments[2]);
    if (is_array($value)) {
        echo json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } elseif (is_string($value) || is_int($value)) {
        echo $value, PHP_EOL;
    } else {
        throw new RuntimeException('Requested field is not a supported scalar or list.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Manifest error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
