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

    public function test_resizees_tall_logo_to_max_height(): void
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
        $this->assertLessThanOrEqual(200, imagesy($result));
        $this->assertGreaterThan(0, imagesx($result));
        imagedestroy($result);
    }

    public function test_does_not_upscale_small_logo(): void
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
        $this->assertSame(120, imagesy($result));
        $this->assertSame(80, imagesx($result));
        imagedestroy($result);
    }
}
