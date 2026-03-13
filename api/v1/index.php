<?php
declare(strict_types=1);

$cfg = require __DIR__ . '/../../app/bootstrap.php';

require_once __DIR__ . '/../../app/Support/ApiResponse.php';
require_once __DIR__ . '/../../app/Support/Request.php';
require_once __DIR__ . '/../../app/Support/Route.php';
require_once __DIR__ . '/../../app/Support/AuthMiddleware.php';

require_once __DIR__ . '/../../app/Modules/Auth/Controllers/AuthController.php';
require_once __DIR__ . '/../../app/Modules/Stations/Controllers/StationsController.php';
require_once __DIR__ . '/../../app/Modules/Carriers/Controllers/CarriersController.php';
require_once __DIR__ . '/../../app/Modules/Picking/Controllers/PickingBatchesController.php';
require_once __DIR__ . '/../../app/Modules/Picking/Controllers/PickingOrdersController.php';
require_once __DIR__ . '/../../app/Modules/Packing/Controllers/PackingController.php';
require_once __DIR__ . '/../../app/Modules/Shipping/Controllers/ShippingController.php';

$method = Request::method();
$path   = Request::path();

$currentSession = AuthMiddleware::handle($method, $path, $cfg);

$routes = [
    ['GET', '/api/v1/health', function (array $params): void {
        ApiResponse::ok([
            'system' => 'pakowanie-api',
            'version' => 'v1',
            'status' => 'bootstrap',
        ]);
    }],

    ['POST', '/api/v1/auth/login', function (array $params) use ($cfg): void {
        (new AuthController($cfg))->login();
    }],
    ['POST', '/api/v1/auth/logout', function (array $params) use ($cfg): void {
        (new AuthController($cfg))->logout();
    }],
    ['GET', '/api/v1/auth/me', function (array $params) use ($cfg): void {
        (new AuthController($cfg))->me();
    }],

    ['GET', '/api/v1/stations', function (array $params) use ($cfg): void {
        (new StationsController($cfg))->index();
    }],

    ['GET', '/api/v1/carriers', function (array $params) use ($cfg): void {
        (new CarriersController($cfg))->index();
    }],
    ['POST', '/api/v1/stations/select', function (array $params) use ($cfg): void {
        (new StationsController($cfg))->select();
    }],

    ['POST', '/api/v1/picking/batches/open', function (array $params): void {
        (new PickingBatchesController())->open($params);
    }],
    ['GET', '/api/v1/picking/batches/current', function (array $params): void {
        (new PickingBatchesController())->current($params);
    }],
    ['GET', '/api/v1/picking/batches/{batchId}', function (array $params): void {
        (new PickingBatchesController())->show($params);
    }],
    ['POST', '/api/v1/picking/batches/{batchId}/refill', function (array $params): void {
        (new PickingBatchesController())->refill($params);
    }],
    ['POST', '/api/v1/picking/batches/{batchId}/close', function (array $params): void {
        (new PickingBatchesController())->close($params);
    }],
    ['GET', '/api/v1/picking/batches/{batchId}/orders', function (array $params): void {
        (new PickingOrdersController())->orders($params);
    }],
    ['GET', '/api/v1/picking/batches/{batchId}/products', function (array $params): void {
        (new PickingOrdersController())->products($params);
    }],
    ['POST', '/api/v1/picking/orders/{orderId}/items/{itemId}/picked', function (array $params): void {
        (new PickingOrdersController())->markPicked($params);
    }],
    ['POST', '/api/v1/picking/orders/{orderId}/items/{itemId}/missing', function (array $params): void {
        (new PickingOrdersController())->markMissing($params);
    }],
    ['POST', '/api/v1/picking/orders/{orderId}/drop', function (array $params): void {
        (new PickingOrdersController())->drop($params);
    }],
    ['POST', '/api/v1/picking/batches/{batchId}/abandon', function (array $params): void {
        (new PickingBatchesController())->abandon($params);
    }],

    ['POST', '/api/v1/packing/orders/{orderId}/open', function (array $params): void {
        (new PackingController())->open($params);
    }],
    ['GET', '/api/v1/packing/orders/{orderId}', function (array $params): void {
        (new PackingController())->show($params);
    }],
    ['POST', '/api/v1/packing/orders/{orderId}/finish', function (array $params): void {
        (new PackingController())->finish($params);
    }],
    ['POST', '/api/v1/packing/orders/{orderId}/cancel', function (array $params): void {
        (new PackingController())->cancel($params);
    }],
    ['POST', '/api/v1/packing/orders/{orderId}/heartbeat', function (array $params): void {
        (new PackingController())->heartbeat($params);
    }],
    ['POST', '/api/v1/heartbeat', function (array $params): void {
        require_once BASE_PATH . '/app/Modules/Heartbeat/Controllers/HeartbeatController.php';
        (new HeartbeatController())->heartbeat($params);
    }],

    ['GET', '/api/v1/shipping/rules', function (array $params): void {
        (new ShippingController())->rules();
    }],
    ['POST', '/api/v1/shipping/resolve-method', function (array $params): void {
        (new ShippingController())->resolveMethod();
    }],
    ['GET', '/api/v1/shipping/orders/{orderId}/options', function (array $params): void {
        (new ShippingController())->options($params);
    }],
    ['POST', '/api/v1/shipping/orders/{orderId}/generate-label', function (array $params): void {
        (new ShippingController())->generateLabel($params);
    }],
    ['GET', '/api/v1/shipping/orders/{orderId}/label', function (array $params): void {
        (new ShippingController())->label($params);
    }],
    ['POST', '/api/v1/shipping/orders/{orderId}/reprint', function (array $params): void {
        (new ShippingController())->reprint($params);
    }],
];

foreach ($routes as $route) {
    if ($method !== $route[0]) {
        continue;
    }

    $params = Route::match($route[1], $path);
    if ($params === null) {
        continue;
    }

    $handler = $route[2];
    $handler($params);
    exit;
}

ApiResponse::error('Route not found', 404, [
    'method' => $method,
    'path' => $path,
]);
