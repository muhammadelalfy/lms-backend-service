<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use ZipArchive;

/** The landing's Android download: APKs on the server, a manifest, and a stable link. */
class AndroidDownloadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->dir = storage_path('framework/testing/downloads-'.bin2hex(random_bytes(4)));
        config(['landing.downloads_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function apk(string $name = 'app.apk', bool $valid = true): string
    {
        $path = storage_path('framework/testing/'.bin2hex(random_bytes(4)).'-'.$name);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString($valid ? 'AndroidManifest.xml' : 'readme.txt', 'manifest');
        $zip->addFromString('classes.dex', str_repeat('dex', 100));
        $zip->close();

        return $path;
    }

    private function publish(string $file, string $version, string $abi = 'arm64'): int
    {
        return Artisan::call('app:publish-android', ['path' => $file, '--version-name' => $version, '--abi' => $abi]);
    }

    public function test_without_any_apk_the_landing_says_soon_and_the_link_is_a_friendly_404(): void
    {
        $this->get('http://localhost/')->assertOk()->assertSee('أندرويد — قريباً')->assertDontSee('/download/android');
        $this->get('http://localhost/download/android')->assertNotFound()->assertSee('غير متاح للتحميل');
        $this->getJson('http://localhost/download/android.json')->assertNotFound();
    }

    public function test_publishing_makes_the_landing_button_a_download_with_version_and_size(): void
    {
        $this->assertSame(0, $this->publish($this->apk(), '1.0.0'));
        $this->assertSame(0, $this->publish($this->apk(), '1.0.0', 'armv7'));

        $this->get('http://localhost/')->assertOk()
            ->assertSee('href="'.url('/download/android').'"', false)
            ->assertSee('الإصدار 1.0.0')
            ->assertSee('لأجهزة أقدم (32-bit)')
            ->assertDontSee('أندرويد — قريباً');
    }

    public function test_the_download_is_an_apk_attachment_with_an_etag_and_the_32_bit_variant_is_reachable(): void
    {
        $this->publish($this->apk(), '1.2.0');
        $this->publish($this->apk(), '1.2.0', 'armv7');

        $response = $this->get('http://localhost/download/android')->assertOk();
        $this->assertSame('application/vnd.android.package-archive', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('mudarisy-1.2.0.apk', (string) $response->headers->get('Content-Disposition'));
        $etag = (string) $response->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->get('http://localhost/download/android', ['If-None-Match' => $etag])->assertStatus(304);

        $this->assertStringContainsString('mudarisy-1.2.0-armv7.apk', (string) $this->get('http://localhost/download/android?abi=armv7')->headers->get('Content-Disposition'));

        $this->getJson('http://localhost/download/android.json')->assertOk()
            ->assertJsonPath('version_name', '1.2.0')
            ->assertJsonStructure(['files' => ['arm64' => ['size', 'sha256', 'url'], 'armv7' => ['size', 'sha256', 'url']], 'url']);
    }

    public function test_a_file_that_is_not_an_apk_is_refused_and_nothing_changes(): void
    {
        $this->assertSame(1, $this->publish($this->apk('bad.apk', valid: false), '1.0.0'));
        $this->assertFileDoesNotExist($this->dir.'/mudarisy.apk');
        $this->assertFileDoesNotExist($this->dir.'/latest.json');

        $text = storage_path('framework/testing/not-a-zip.apk');
        file_put_contents($text, 'hello');
        $this->assertSame(1, $this->publish($text, '1.0.0'));
        @unlink($text);
    }

    public function test_only_the_newest_three_versions_are_kept_and_the_stable_name_is_the_newest(): void
    {
        foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0'] as $i => $version) {
            $this->publish($this->apk(), $version);
            touch($this->dir."/mudarisy-{$version}-arm64.apk", time() - 100 + $i); // older than the next publish
        }
        $this->publish($this->apk(), '1.4.0');

        $kept = array_map('basename', glob($this->dir.'/mudarisy-*-arm64.apk') ?: []);
        sort($kept);
        $this->assertCount(3, $kept);
        $this->assertContains('mudarisy-1.4.0-arm64.apk', $kept);
        $this->assertSame('1.4.0', json_decode((string) file_get_contents($this->dir.'/latest.json'), true)['version_name']);
        $this->assertFileEquals($this->dir.'/mudarisy-1.4.0-arm64.apk', $this->dir.'/mudarisy.apk');
    }

    public function test_an_apk_copied_by_hand_without_a_manifest_still_makes_the_button_work(): void
    {
        mkdir($this->dir, 0775, true);
        copy($this->apk(), $this->dir.'/mudarisy.apk');

        $this->get('http://localhost/')->assertOk()->assertSee('href="'.url('/download/android').'"', false)->assertDontSee('الإصدار');
        $this->get('http://localhost/download/android')->assertOk();
    }
}
