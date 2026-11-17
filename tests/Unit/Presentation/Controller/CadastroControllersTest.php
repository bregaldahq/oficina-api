<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Controller;

use App\Application\UseCase\Auth\AuthenticateUseCase;
use App\Infrastructure\Repository\PdoCustomerRepository;
use App\Infrastructure\Repository\PdoVehicleRepository;
use App\Infrastructure\Security\JwtProvider;
use App\Presentation\Controller\AuthController;
use App\Presentation\Controller\CustomerController;
use App\Presentation\Controller\VehicleController;
use App\Presentation\Request\RequestValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\OsDatabase;
use Tests\Support\SequenceUuid;

/** Clientes, veículos e login do admin: o cadastro que fica no serviço de OS. */
class CadastroControllersTest extends TestCase
{
    private string $body = '{}';

    protected function setUp(): void
    {
        OsDatabase::create();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ADMIN_USERNAME'], $_ENV['ADMIN_PASSWORD']);
    }

    private function reader(): \Closure
    {
        return fn (): string => $this->body;
    }

    private function customers(): CustomerController
    {
        return new CustomerController(new PdoCustomerRepository(), new SequenceUuid(), $this->reader());
    }

    private function vehicles(): VehicleController
    {
        return new VehicleController(new PdoVehicleRepository(), new PdoCustomerRepository(), new SequenceUuid(), $this->reader());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function call(object $controller, string $action, array $body = [], mixed ...$args): array
    {
        $this->body = (string)json_encode($body);
        http_response_code(200);
        ob_start();
        $controller->{$action}(...$args);
        $out = json_decode((string)ob_get_clean(), true);

        return [(int)http_response_code(), is_array($out) ? $out : []];
    }

    public function testCicloDeVidaDoCliente(): void
    {
        [$status, $created] = $this->call($this->customers(), 'store', ['name' => 'Maria Silva', 'document' => '529.982.247-25', 'email' => 'm@x.com']);
        $this->assertSame(201, $status);
        $id = ['id' => $created['id']];

        $this->assertSame('Maria Silva', $this->call($this->customers(), 'show', [], $id)[1]['name']);
        [$status, $updated] = $this->call($this->customers(), 'update', ['name' => 'Maria S. Souza', 'status' => 'BLOCKED'], $id);
        $this->assertSame([200, 'Maria S. Souza', 'BLOCKED'], [$status, $updated['name'], $updated['status']]);
        $this->assertSame('m@x.com', $updated['email'], 'o que não veio no corpo continua');
        $this->assertCount(1, $this->call($this->customers(), 'index')[1]);
        $this->assertSame(204, $this->call($this->customers(), 'destroy', [], $id)[0]);
        $this->assertSame(404, $this->call($this->customers(), 'show', [], $id)[0]);
        $this->assertSame(404, $this->call($this->customers(), 'update', ['name' => 'x'], $id)[0]);
        $this->assertSame(404, $this->call($this->customers(), 'destroy', [], $id)[0]);
    }

    public function testClienteComDocumentoInvalidoOuRepetido(): void
    {
        $this->assertSame(422, $this->call($this->customers(), 'store', ['name' => 'Ana', 'document' => '111.111.111-11'])[0]);

        $this->call($this->customers(), 'store', ['name' => 'Ana', 'document' => '52998224725']);
        $this->assertSame(422, $this->call($this->customers(), 'store', ['name' => 'Outra', 'document' => '529.982.247-25'])[0]);
    }

    public function testValidacaoDoCorpoViraExcecaoQueOIndexTransformaEm400(): void
    {
        try {
            $this->call($this->customers(), 'store', ['document' => '52998224725']);
            $this->fail('aceitou cliente sem nome');
        } catch (RequestValidationException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('name', $e->getMessage());
        }
        ob_end_clean();
    }

    public function testCicloDeVidaDoVeiculo(): void
    {
        $customer = $this->call($this->customers(), 'store', ['name' => 'Maria', 'document' => '52998224725'])[1]['id'];

        [$status, $created] = $this->call($this->vehicles(), 'store', ['customer_id' => $customer, 'license_plate' => 'ABC1D23', 'brand' => 'Fiat', 'model' => 'Uno', 'year' => 2015]);
        $this->assertSame(201, $status);
        $id = ['id' => $created['id']];

        $this->assertSame('Uno', $this->call($this->vehicles(), 'show', [], $id)[1]['model']);
        $this->assertSame(200, $this->call($this->vehicles(), 'update', ['brand' => 'Fiat', 'model' => 'Mobi', 'year' => 2020], $id)[0]);
        $this->assertSame('Mobi', $this->call($this->vehicles(), 'show', [], $id)[1]['model']);
        $this->assertCount(1, $this->call($this->vehicles(), 'index')[1]);
        $this->assertSame(204, $this->call($this->vehicles(), 'destroy', [], $id)[0]);
        $this->assertSame(404, $this->call($this->vehicles(), 'show', [], $id)[0]);
        $this->assertSame(404, $this->call($this->vehicles(), 'destroy', [], $id)[0]);
        $this->assertSame(404, $this->call($this->vehicles(), 'update', ['brand' => 'x', 'model' => 'y', 'year' => 2000], $id)[0]);
    }

    public function testVeiculoDeClienteInexistenteOuPlacaRepetida(): void
    {
        $this->assertSame(404, $this->call($this->vehicles(), 'store', ['customer_id' => 'nao-existe', 'license_plate' => 'ABC1D23', 'brand' => 'F', 'model' => 'U', 'year' => 2015])[0]);

        $customer = $this->call($this->customers(), 'store', ['name' => 'Maria', 'document' => '52998224725'])[1]['id'];
        $vehicle  = ['customer_id' => $customer, 'license_plate' => 'ABC1D23', 'brand' => 'F', 'model' => 'U', 'year' => 2015];
        $this->call($this->vehicles(), 'store', $vehicle);
        $this->assertSame(422, $this->call($this->vehicles(), 'store', $vehicle)[0]);
        $this->assertSame(422, $this->call($this->vehicles(), 'store', ['license_plate' => 'XX'] + $vehicle)[0], 'placa inválida');
    }

    public function testLoginDoAdmin(): void
    {
        $_ENV['ADMIN_USERNAME'] = 'admin';
        $_ENV['ADMIN_PASSWORD'] = 'senha-forte';
        $auth                   = new AuthController(new AuthenticateUseCase(new JwtProvider('segredo', 3600)), $this->reader());

        [$status, $body] = $this->call($auth, 'login', ['username' => 'admin', 'password' => 'senha-forte']);
        $this->assertSame(200, $status);
        $this->assertSame('admin', (new JwtProvider('segredo', 3600))->validate($body['token'])['role']);

        $this->assertSame(401, $this->call($auth, 'login', ['username' => 'admin', 'password' => 'errada'])[0]);
        $this->assertSame(400, $this->call($auth, 'login', ['username' => 'admin'])[0]);
    }
}
