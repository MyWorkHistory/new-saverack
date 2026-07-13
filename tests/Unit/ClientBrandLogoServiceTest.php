<?php

namespace Tests\Unit;

use App\Models\ClientAccount;
use App\Services\ClientBrandLogoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ClientBrandLogoServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function mockAccount(int $id = 42): ClientAccount
    {
        $account = Mockery::mock(ClientAccount::class)->makePartial();
        $account->id = $id;
        $account->brand_logo_path = null;
        $account->shouldReceive('save')->andReturnTrue();

        return $account;
    }

    public function test_resizees_tall_logo_to_square_canvas(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD extension is not available.');
        }

        Storage::fake('public');

        $source = imagecreatetruecolor(100, 400);
        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);

        $file = UploadedFile::fake()->createWithContent('logo.png', $png);
        $service = new ClientBrandLogoService();
        $path = $service->replaceForAccount($this->mockAccount(), $file);

        $stored = Storage::disk('public')->get($path);
        $this->assertNotEmpty($stored);

        $result = imagecreatefromstring($stored);
        $this->assertNotFalse($result);
        $this->assertSame(256, imagesx($result));
        $this->assertSame(256, imagesy($result));
        imagedestroy($result);
    }

    public function test_normalizes_small_logo_to_square_canvas(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD extension is not available.');
        }

        Storage::fake('public');

        $source = imagecreatetruecolor(80, 120);
        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);

        $file = UploadedFile::fake()->createWithContent('logo.png', $png);
        $service = new ClientBrandLogoService();
        $path = $service->replaceForAccount($this->mockAccount(), $file);

        $stored = Storage::disk('public')->get($path);
        $result = imagecreatefromstring($stored);
        $this->assertNotFalse($result);
        $this->assertSame(256, imagesx($result));
        $this->assertSame(256, imagesy($result));
        imagedestroy($result);
    }

    public function test_normalizes_wide_logo_to_square_canvas(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD extension is not available.');
        }

        Storage::fake('public');

        $source = imagecreatetruecolor(400, 80);
        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);

        $file = UploadedFile::fake()->createWithContent('logo.png', $png);
        $service = new ClientBrandLogoService();
        $path = $service->replaceForAccount($this->mockAccount(), $file);

        $stored = Storage::disk('public')->get($path);
        $result = imagecreatefromstring($stored);
        $this->assertNotFalse($result);
        $this->assertSame(256, imagesx($result));
        $this->assertSame(256, imagesy($result));
        imagedestroy($result);
    }
}
