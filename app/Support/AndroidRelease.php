<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * The Android app the landing page offers: APK files in the downloads folder (on the persistent
 * `storage` volume, so a redeploy keeps them) and a small manifest, `latest.json`, describing them.
 *
 * Stable names always point at the newest version, so a link never breaks:
 *   mudarisy.apk        arm64 (almost every phone)
 *   mudarisy-armv7.apk  32-bit (older phones)
 * Versioned copies (`mudarisy-<version>-<abi>.apk`) keep the last few releases.
 */
final class AndroidRelease
{
    public const ABIS = ['arm64' => 'mudarisy.apk', 'armv7' => 'mudarisy-armv7.apk'];

    private const KEEP = 3;

    private const MAX_BYTES = 300 * 1024 * 1024;

    public function __construct(private readonly string $dir) {}

    public static function fromConfig(): self
    {
        return new self((string) config('landing.downloads_dir'));
    }

    /** The stable file for [$abi], or null when it is not there. */
    public function file(string $abi): ?string
    {
        $name = self::ABIS[$abi] ?? null;
        $path = $name === null ? null : $this->dir.'/'.$name;

        return $path !== null && is_file($path) ? $path : null;
    }

    /** @return array<string, mixed>|null */
    public function manifest(): ?array
    {
        $path = $this->dir.'/latest.json';
        if (! is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * What the landing and the JSON endpoint show; works even before anyone ran the publish command
     * (only the size is known then). Null when there is no APK at all.
     *
     * @return array{version_name: ?string, version_code: ?int, published_at: ?string, notes: ?string, files: array<string, array{size: int, sha256: ?string}>}|null
     */
    public function summary(): ?array
    {
        $manifest = $this->manifest() ?? [];
        $files = [];
        foreach (array_keys(self::ABIS) as $abi) {
            $path = $this->file($abi);
            if ($path !== null) {
                $files[$abi] = ['size' => (int) filesize($path), 'sha256' => $manifest['files'][$abi]['sha256'] ?? null];
            }
        }
        if ($files === []) {
            return null;
        }

        return [
            'version_name' => $manifest['version_name'] ?? null,
            'version_code' => isset($manifest['version_code']) ? (int) $manifest['version_code'] : null,
            'published_at' => $manifest['published_at'] ?? null,
            'notes' => $manifest['notes'] ?? null,
            'files' => $files,
        ];
    }

    /**
     * Copies a checked APK in as the newest version for [$abi], refreshes the stable name, rewrites the
     * manifest atomically and removes all but the newest versioned copies.
     *
     * @return array{file: string, size: int, sha256: string}
     */
    public function publish(string $source, string $abi, string $versionName, ?int $versionCode = null, ?string $notes = null): array
    {
        if (! isset(self::ABIS[$abi])) {
            throw new RuntimeException('Unknown ABI: '.$abi.' (use '.implode(' or ', array_keys(self::ABIS)).').');
        }
        if (! preg_match('/^[0-9A-Za-z][0-9A-Za-z.+-]{0,30}$/', $versionName)) {
            throw new RuntimeException('The version name may hold letters, digits, dots, + and - (e.g. 1.0.0).');
        }
        $this->assertApk($source);

        if (! is_dir($this->dir) && ! mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new RuntimeException('Cannot create '.$this->dir);
        }
        $versioned = "mudarisy-{$versionName}-{$abi}.apk";
        $this->copyAtomically($source, $this->dir.'/'.$versioned);
        $this->copyAtomically($this->dir.'/'.$versioned, $this->dir.'/'.self::ABIS[$abi]);
        $entry = ['file' => $versioned, 'size' => (int) filesize($this->dir.'/'.$versioned), 'sha256' => hash_file('sha256', $this->dir.'/'.$versioned)];

        $manifest = $this->manifest() ?? [];
        // A new version replaces what the other ABI said about an older one.
        if (($manifest['version_name'] ?? null) !== $versionName) {
            $manifest['files'] = [];
        }
        $manifest['version_name'] = $versionName;
        $manifest['version_code'] = $versionCode ?? ($manifest['version_code'] ?? null);
        $manifest['notes'] = $notes ?? ($manifest['notes'] ?? null);
        $manifest['published_at'] = now()->toIso8601String();
        $manifest['files'][$abi] = $entry;
        $this->writeAtomically($this->dir.'/latest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->rotate($abi);

        return $entry;
    }

    /** A real APK is a zip holding a manifest and compiled code; anything else is refused. */
    private function assertApk(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException('No such file: '.$path);
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('The file is larger than 300 MB.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This is not an APK (not a zip archive).');
        }
        $ok = $zip->locateName('AndroidManifest.xml') !== false && $zip->locateName('classes.dex') !== false;
        $zip->close();
        if (! $ok) {
            throw new RuntimeException('This is not an APK (no AndroidManifest.xml or classes.dex).');
        }
    }

    private function rotate(string $abi): void
    {
        $versions = glob($this->dir."/mudarisy-*-{$abi}.apk") ?: [];
        usort($versions, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
        foreach (array_slice($versions, self::KEEP) as $old) {
            @unlink($old);
        }
    }

    private function copyAtomically(string $from, string $to): void
    {
        $tmp = $to.'.tmp-'.bin2hex(random_bytes(4));
        if (! copy($from, $tmp)) {
            throw new RuntimeException('Cannot write '.$to);
        }
        chmod($tmp, 0644);
        rename($tmp, $to);
    }

    private function writeAtomically(string $to, string $contents): void
    {
        $tmp = $to.'.tmp-'.bin2hex(random_bytes(4));
        file_put_contents($tmp, $contents);
        chmod($tmp, 0644);
        rename($tmp, $to);
    }
}
