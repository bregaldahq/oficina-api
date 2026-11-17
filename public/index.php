<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Infrastructure\Config\EnvLoader;

EnvLoader::loadFile(__DIR__ . '/../.env');
EnvLoader::require(['JWT_SECRET', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);

$startedAt = microtime(true);
$appEnv    = (string)($_ENV['APP_ENV'] ?? 'local');
$appDebug  = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Request-Id');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$uuid = new \App\Infrastructure\UuidGenerator();

// Observability wiring: correlation id first, so every log line of this request carries it.
$logger        = new \App\Infrastructure\Logging\JsonLogger('oficina-api', $appEnv);
$context       = new \App\Infrastructure\Context\RequestContext();
$correlationId = (new \App\Presentation\Middleware\CorrelationIdMiddleware($uuid))->apply($_SERVER);
$context->setCorrelationId($correlationId);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$jwtProvider    = \App\Infrastructure\Security\JwtProvider::fromEnv();
$authMiddleware = new \App\Presentation\Middleware\AuthMiddleware($jwtProvider, $context);

$customerRepo = new \App\Infrastructure\Repository\PdoCustomerRepository();
$vehicleRepo  = new \App\Infrastructure\Repository\PdoVehicleRepository();

// A saga, os repositórios da OS e o despachante de eventos (histórico, New Relic, e-mail) vêm
// da mesma montagem que o worker usa (src/Infrastructure/ServiceFactory.php).
$services  = new \App\Infrastructure\ServiceFactory($_ENV + getenv(), $logger, $context);
$orderRepo = $services->orders();

$authController = new \App\Presentation\Controller\AuthController(
    new \App\Application\UseCase\Auth\AuthenticateUseCase($jwtProvider)
);

$healthController = new \App\Presentation\Controller\HealthController();

$customerController = new \App\Presentation\Controller\CustomerController($customerRepo, $uuid);

$vehicleController = new \App\Presentation\Controller\VehicleController($vehicleRepo, $customerRepo, $uuid);

$orderController = new \App\Presentation\Controller\ServiceOrderController(
    $services->createServiceOrder($logger),
    new \App\Application\UseCase\ServiceOrder\GetServiceOrderUseCase($orderRepo, $services->sagas()),
    new \App\Application\UseCase\ServiceOrder\ListServiceOrdersByCustomerUseCase($orderRepo),
    new \App\Application\UseCase\ServiceOrder\ListServiceOrdersUseCase($orderRepo),
    $services->cancelServiceOrder($logger),
    $services->deliverServiceOrder(),
);

$internalController = new \App\Presentation\Controller\InternalCustomerController(
    $customerRepo,
    (string)($_ENV['INTERNAL_TOKEN'] ?? ''),
);

$router = new \App\Presentation\Router\Router();
$router->setAuthMiddleware($authMiddleware);

// Authorization matrix — docs/fase-3/CONTRATOS.md §5, com os adendos de docs/fase-4/CONTRATOS.md §4.

// públicas
$router->get('/api/health', fn() => $healthController->live(),  requireAuth: false);
$router->get('/api/ready',  fn() => $healthController->ready(), requireAuth: false);
$router->post('/api/auth/login', fn() => $authController->login(), requireAuth: false);
// interna: fora do API Gateway, só por dentro da VPC (a Lambda auth-cpf), com X-Internal-Token
$router->get(
    '/internal/customers/by-document/{document}',
    fn ($p) => $internalController->byDocument($p, (string)($_SERVER['HTTP_X_INTERNAL_TOKEN'] ?? '')),
    requireAuth: false,
);

// ordens de serviço — a rota /me vem antes de /{id} para não ser capturada por ela
$router->get('/api/service-orders/me', fn($p, $c) => $orderController->mine($p, $c))
    ->requireRole('customer', 'admin');
$router->get('/api/service-orders/{id}', fn($p, $c) => $orderController->show($p, $c))
    ->requireRole('customer', 'admin');
$router->get('/api/service-orders', fn() => $orderController->index())->requireRole('admin');
$router->post('/api/service-orders', fn() => $orderController->store((string)$context->getCorrelationId()))->requireRole('admin');
// Fase 4: o status é movido pela saga. Daqui só saem a entrega e o cancelamento.
$router->post('/api/service-orders/{id}/delivery', fn($p) => $orderController->deliver($p))->requireRole('admin');
$router->post('/api/service-orders/{id}/cancellation', fn($p) => $orderController->cancel($p))->requireRole('admin');

// clientes
$router->get('/api/customers',         fn() => $customerController->index())->requireRole('admin');
$router->get('/api/customers/{id}',    fn($p) => $customerController->show($p))->requireRole('admin');
$router->post('/api/customers',        fn() => $customerController->store())->requireRole('admin');
$router->put('/api/customers/{id}',    fn($p) => $customerController->update($p))->requireRole('admin');
$router->delete('/api/customers/{id}', fn($p) => $customerController->destroy($p))->requireRole('admin');

// veículos
$router->get('/api/vehicles',          fn() => $vehicleController->index())->requireRole('admin');
$router->get('/api/vehicles/{id}',     fn($p) => $vehicleController->show($p))->requireRole('admin');
$router->post('/api/vehicles',         fn() => $vehicleController->store())->requireRole('admin');
$router->put('/api/vehicles/{id}',     fn($p) => $vehicleController->update($p))->requireRole('admin');
$router->delete('/api/vehicles/{id}',  fn($p) => $vehicleController->destroy($p))->requireRole('admin');

// catálogo e estoque saíram para o serviço de Faturamento (/api/billing), Fase 4

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = (string)($_SERVER['REQUEST_URI'] ?? '/');

/**
 * One structured line per request (CONTRATOS.md §7), emitted even when the request dies on
 * an exit() inside a middleware — hence the shutdown function.
 */
// O CPF da consulta interna vai na URL: não entra no log.
$logPath = static fn (string $uri): string => (string)preg_replace(
    '#^(/internal/customers/by-document/)[^/]+#',
    '$1***',
    (string)(parse_url($uri, PHP_URL_PATH) ?? $uri),
);

$requestLogged = false;
$logRequest    = static function (string $level = 'info') use (
    &$requestLogged, $logger, $context, $method, $uri, $startedAt, $logPath
): void {
    if ($requestLogged) {
        return;
    }
    $requestLogged = true;

    $logger->log($level, 'request.completed', [
        'correlation_id' => $context->getCorrelationId(),
        'method'         => $method,
        'path'           => $logPath($uri),
        'status'         => http_response_code() ?: 200,
        'duration_ms'    => round((microtime(true) - $startedAt) * 1000, 1),
        'customer_id'    => $context->getActor(),
        'role'           => $context->getRole(),
    ]);
};

register_shutdown_function(static fn () => $logRequest());

try {
    $router->dispatch($method, $uri);
} catch (\App\Presentation\Request\RequestValidationException $e) {
    http_response_code($e->status);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\App\Domain\Exception\DomainException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    // Unhandled failure: the detail goes to the log, never to the client unless APP_DEBUG=true.
    $logger->error('request.failed', [
        'correlation_id'    => $context->getCorrelationId(),
        'method'            => $method,
        'path'              => (string)(parse_url($uri, PHP_URL_PATH) ?? $uri),
        'exception_class'   => $e::class,
        'exception_message' => $e->getMessage(),
        'file'              => $e->getFile(),
        'line'              => $e->getLine(),
    ]);

    http_response_code(500);
    echo json_encode([
        'error'   => 'Erro interno do servidor.',
        'message' => $appDebug ? $e->getMessage() : null,
    ]);

    $logRequest('error');
}

$logRequest();
