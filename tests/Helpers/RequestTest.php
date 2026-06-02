<?php
declare(strict_types=1);

namespace Tests\Helpers;

use App\Helpers\Request;
use Tests\TestCase;

class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_GET['test_key']);
        unset($_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testBodyParsesJson(): void
    {
        Request::setTestBody(json_encode(['name' => 'John', 'age' => 30]));
        $this->assertSame(['name' => 'John', 'age' => 30], Request::body());
    }

    public function testBodyReturnsEmptyArrayForEmptyInput(): void
    {
        Request::setTestBody('');
        $this->assertSame([], Request::body());
    }

    public function testBodyReturnsEmptyArrayForInvalidJson(): void
    {
        Request::setTestBody('not json');
        $this->assertSame([], Request::body());
    }

    public function testGetReturnsValueOrDefault(): void
    {
        $_GET['foo'] = 'bar';
        $this->assertSame('bar', Request::get('foo'));
        $this->assertSame('default', Request::get('missing', 'default'));
        $this->assertNull(Request::get('missing'));
    }

    public function testBearerTokenExtractsFromHeader(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer abc123';
        $this->assertSame('abc123', Request::bearerToken());
    }

    public function testBearerTokenReturnsNullForMissingHeader(): void
    {
        $this->assertNull(Request::bearerToken());
    }

    public function testBearerTokenReturnsNullForNonBearer(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc123';
        $this->assertNull(Request::bearerToken());
    }

    public function testValidateRequired(): void
    {
        $errors = Request::validate(['name' => ''], ['name' => 'required']);
        $this->assertArrayHasKey('name', $errors);

        $errors = Request::validate(['name' => 'John'], ['name' => 'required']);
        $this->assertEmpty($errors);
    }

    public function testValidateEmail(): void
    {
        $errors = Request::validate(['email' => 'invalid'], ['email' => 'email']);
        $this->assertArrayHasKey('email', $errors);

        $errors = Request::validate(['email' => 'test@example.com'], ['email' => 'email']);
        $this->assertEmpty($errors);
    }

    public function testValidateMinMax(): void
    {
        $errors = Request::validate(['pass' => '123'], ['pass' => 'min:5']);
        $this->assertArrayHasKey('pass', $errors);

        $errors = Request::validate(['pass' => '12345678901'], ['pass' => 'max:10']);
        $this->assertArrayHasKey('pass', $errors);

        $errors = Request::validate(['pass' => '12345'], ['pass' => 'min:5|max:10']);
        $this->assertEmpty($errors);
    }

    public function testValidateNumeric(): void
    {
        $errors = Request::validate(['age' => 'abc'], ['age' => 'numeric']);
        $this->assertArrayHasKey('age', $errors);

        $errors = Request::validate(['age' => '25'], ['age' => 'numeric']);
        $this->assertEmpty($errors);
    }

}
