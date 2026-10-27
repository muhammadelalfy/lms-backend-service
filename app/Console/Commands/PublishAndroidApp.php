<?php

namespace App\Console\Commands;

use App\Support\AndroidRelease;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('app:publish-android {path : the APK file} {--version-name= : e.g. 1.0.0} {--version-code=} {--abi=arm64 : arm64 or armv7} {--notes=}')]
#[Description('Publish an Android APK as the newest download of the landing page')]
class PublishAndroidApp extends Command
{
    public function handle(): int
    {
        $version = (string) $this->option('version-name');
        if ($version === '') {
            $this->error('Pass --version-name (e.g. --version-name=1.0.0).');

            return self::FAILURE;
        }

        try {
            $entry = AndroidRelease::fromConfig()->publish(
                (string) $this->argument('path'),
                (string) $this->option('abi'),
                $version,
                $this->option('version-code') !== null ? (int) $this->option('version-code') : null,
                $this->option('notes') !== null ? (string) $this->option('notes') : null,
            );
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $abi = (string) $this->option('abi');
        $this->info("Published {$entry['file']} ({$entry['size']} bytes, sha256 {$entry['sha256']}).");
        $this->line('Download: '.url('/download/android'.($abi === 'armv7' ? '?abi=armv7' : '')));

        return self::SUCCESS;
    }
}
