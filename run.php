<?php
declare(strict_types=1);

use CodeUpdate\Run;

include __DIR__ . '/vendor/autoload.php';

try {
    $code = new Run();
    $code->run();
} catch (\Exception $ex) {
    echo json_encode(['msg' => $ex->getMessage(), 'status' => $ex->getCode(),]);
    exit($ex->getCode());
}

echo json_encode(['msg' => 'ok', 'status' => 0,]);
