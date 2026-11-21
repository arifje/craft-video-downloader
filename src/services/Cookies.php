<?php

namespace arifje\craftvideodownloader\services;

use Craft;

/** Private credential storage and disposable yt-dlp cookie jars. */
final class Cookies
{
    /** Maximum accepted export size in bytes. */
    public const MAX_BYTES = 1048576;

    /** Storage must be persistent, outside the document root and excluded from Git. */
    public function __construct(private readonly string $directory, private readonly string $webRoot)
    {
    }

    /** Use Craft's persistent storage, not its clearable runtime directory. */
    public static function forCraft(): self
    {
        return new self(Craft::$app->getPath()->getStoragePath() . '/video-downloader-credentials', Craft::getAlias('@webroot'));
    }

    /** Whether a managed credential file exists (including an unreadable file). */
    public function configured(): bool
    {
        clearstatcache(true, $this->directory . '/cookies.txt');
        return file_exists($this->directory . '/cookies.txt') || is_link($this->directory . '/cookies.txt');
    }

    /** When the managed credential file was last saved, or null. */
    public function savedAt(): ?int
    {
        clearstatcache(true, $this->directory . '/cookies.txt');
        $time = @filemtime($this->directory . '/cookies.txt');
        return $time === false ? null : $time;
    }

    /**
     * What is saved, without any cookie names or values: per domain the number
     * of cookies and the earliest expiry (null when they are session cookies).
     *
     * @return array<int, array{domain: string, count: int, expires: ?int}>
     */
    public function summary(): array
    {
        $path = $this->directory . '/cookies.txt';
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return [];
        }
        $content = (string) @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        $domains = [];
        foreach (explode("\n", $content) as $line) {
            if ($line === '' || (str_starts_with($line, '#') && !str_starts_with($line, '#HttpOnly_'))) {
                continue;
            }
            $fields = explode("\t", str_starts_with($line, '#HttpOnly_') ? substr($line, 10) : $line);
            if (count($fields) !== 7) {
                continue;
            }
            $domain = ltrim($fields[0], '.');
            $expires = (int) $fields[4] > 0 ? (int) $fields[4] : null;
            $row = $domains[$domain] ?? ['domain' => $domain, 'count' => 0, 'expires' => null];
            $row['count']++;
            if ($expires !== null && ($row['expires'] === null || $expires < $row['expires'])) {
                $row['expires'] = $expires;
            }
            $domains[$domain] = $row;
        }
        ksort($domains);
        return array_values($domains);
    }

    /**
     * Turn what people actually paste into a Netscape cookies.txt: JSON exports
     * (Cookie-Editor, EditThisCookie, Playwright), a missing header, a BOM or
     * leading blank lines, spaces where tabs were and decimal expiry times.
     * Records that already have seven tab-separated fields are left untouched.
     */
    public static function normalize(#[\SensitiveParameter] string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $content = ltrim($content, " \t\n");
        if ($content !== '' && ($content[0] === '[' || $content[0] === '{')) {
            return self::fromJson($content);
        }

        $lines = explode("\n", $content);
        $out = [];
        foreach ($lines as $line) {
            if ($line === '' || (str_starts_with($line, '#') && !str_starts_with($line, '#HttpOnly_'))) {
                $out[] = $line;
                continue;
            }
            $prefix = str_starts_with($line, '#HttpOnly_') ? '#HttpOnly_' : '';
            $body = substr($line, strlen($prefix));
            $fields = explode("\t", $body);
            if (count($fields) !== 7) {
                // Copying from a viewer often turns tabs into spaces. The value is
                // the last field, so it keeps any spaces of its own.
                $split = preg_split('/[ \t]+/', trim($body, " \t"), 7);
                if ($split !== false && count($split) === 7) {
                    $fields = $split;
                }
            }
            if (count($fields) === 7 && preg_match('/^\d+\.\d+$/D', $fields[4])) {
                $fields[4] = (string) (int) $fields[4];
            }
            $out[] = $prefix . implode("\t", $fields);
        }
        $content = implode("\n", $out);
        if (!preg_match('/\A# (?:Netscape HTTP Cookie File|HTTP Cookie File)(?:\n|$)/', $content)) {
            $content = "# Netscape HTTP Cookie File\n" . $content;
        }
        return $content;
    }

    /** Convert a JSON cookie export to Netscape records. */
    private static function fromJson(#[\SensitiveParameter] string $content): string
    {
        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Cookies look like JSON but could not be read. Paste the complete export.');
        }
        if (is_array($data) && isset($data['cookies']) && is_array($data['cookies'])) {
            $data = $data['cookies'];
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new \InvalidArgumentException('JSON cookies must be a list of cookies, as exported by Cookie-Editor or EditThisCookie.');
        }
        $lines = ['# Netscape HTTP Cookie File'];
        foreach ($data as $i => $cookie) {
            $domain = is_array($cookie) ? ($cookie['domain'] ?? null) : null;
            $name = is_array($cookie) ? ($cookie['name'] ?? null) : null;
            $value = is_array($cookie) ? ($cookie['value'] ?? '') : null;
            if (!is_string($domain) || $domain === '' || !is_string($name) || $name === '' || !is_scalar($value)
                || preg_match('/[\t\n]/', $domain . $name . (string) $value)) {
                throw new \InvalidArgumentException(sprintf('JSON cookie %d needs a domain, a name and a value.', $i + 1));
            }
            $hostOnly = !empty($cookie['hostOnly']) || !str_starts_with($domain, '.');
            $expires = $cookie['expirationDate'] ?? $cookie['expires'] ?? 0;
            $expires = !empty($cookie['session']) || !is_numeric($expires) || $expires < 0 ? 0 : (int) $expires;
            $lines[] = (!empty($cookie['httpOnly']) ? '#HttpOnly_' : '') . implode("\t", [
                $domain,
                $hostOnly ? 'FALSE' : 'TRUE',
                is_string($cookie['path'] ?? null) && str_starts_with($cookie['path'], '/') ? $cookie['path'] : '/',
                !empty($cookie['secure']) ? 'TRUE' : 'FALSE',
                (string) $expires,
                $name,
                (string) $value,
            ]);
        }
        return implode("\n", $lines);
    }

    /** Validate without trimming cookie names/values or stripping HttpOnly records. */
    public static function validate(#[\SensitiveParameter] string $content): string
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Cookies must be no larger than 1 MiB.');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (!preg_match('/\A# (?:Netscape HTTP Cookie File|HTTP Cookie File)(?:\n|$)/', $content)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
            throw new \InvalidArgumentException('Cookies must be a Netscape cookies.txt export with its header.');
        }
        $count = 0;
        foreach (explode("\n", $content) as $number => $line) {
            if ($line === '' || (str_starts_with($line, '#') && !str_starts_with($line, '#HttpOnly_'))) {
                continue;
            }
            $fields = explode("\t", str_starts_with($line, '#HttpOnly_') ? substr($line, 10) : $line);
            if (count($fields) !== 7 || $fields[0] === '' || preg_match('/\s/', $fields[0])
                || !in_array($fields[1], ['TRUE', 'FALSE'], true)
                || !str_starts_with($fields[2], '/')
                || !in_array($fields[3], ['TRUE', 'FALSE'], true)
                || !preg_match('/^\d+$/D', $fields[4])) {
                throw new \InvalidArgumentException(sprintf('Line %d is not a valid cookie record. Expected seven tab-separated Netscape fields (domain, subdomains, path, secure, expiry, name, value).', $number + 1));
            }
            $count++;
        }
        if ($count === 0) {
            throw new \InvalidArgumentException('Cookies must contain at least one cookie record.');
        }
        return rtrim($content, "\n") . "\n";
    }

    /** Blank input preserves credentials; clear and replacement are mutually exclusive. */
    public function save(#[\SensitiveParameter] string $content, bool $clear = false): void
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Cookies must be no larger than 1 MiB.');
        }
        if (trim($content) === '' && !$clear) {
            return;
        }
        if ($clear && trim($content) !== '') {
            throw new \InvalidArgumentException('Choose either replacement cookies or Clear saved cookies.');
        }
        $normalized = $clear ? null : self::validate(self::normalize($content));
        $this->prepareDirectory();
        $path = $this->directory . '/cookies.txt';
        if ($clear) {
            if ($this->configured() && !@unlink($path)) {
                throw new \RuntimeException('Could not clear saved cookies. Check credential storage permissions.');
            }
            return;
        }
        $temporary = $this->writePrivate($normalized);
        try {
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException('Could not save cookies. Check credential storage permissions.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** Snapshot the selected source; yt-dlp can write only its own disposable jar. */
    public function snapshot(string $legacyPath = ''): ?string
    {
        $this->prepareDirectory();
        $path = $this->directory . '/cookies.txt';
        // Open once: replacement/clear after this point cannot alter this inode.
        if (is_link($path)) {
            throw new \RuntimeException('Saved cookie storage must not be a symbolic link.');
        }
        if ($this->configured() && !is_file($path)) {
            throw new \RuntimeException('Saved cookies must be a readable regular file.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            if ($this->configured()) {
                throw new \RuntimeException('Saved cookies are unreadable. Check credential storage permissions.');
            }
            if ($legacyPath === '') {
                return null;
            }
            if (!is_file($legacyPath) || !is_readable($legacyPath)) {
                throw new \RuntimeException('The configured Cookies file is unreadable. Check its path and permissions.');
            }
            $handle = @fopen($legacyPath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('The configured Cookies file is unreadable. Check its path and permissions.');
            }
        }
        try {
            if ((fstat($handle)['mode'] & 0170000) !== 0100000) {
                throw new \RuntimeException('The configured Cookies file must be a readable regular file.');
            }
            $content = stream_get_contents($handle, self::MAX_BYTES + 1);
            if ($content === false) {
                throw new \RuntimeException('Could not read configured cookies.');
            }
            return $this->writePrivate(self::validate($content));
        } finally {
            fclose($handle);
        }
    }

    /** Refuse public storage, symlinks and non-private directories. */
    private function prepareDirectory(): void
    {
        $parent = realpath(dirname($this->directory));
        $web = realpath($this->webRoot);
        if ($parent === false || $web === false || $web === $parent || str_starts_with($parent . '/', $web . '/') || is_link($this->directory)) {
            throw new \RuntimeException('Cookie storage must be outside the web root in a real, private directory.');
        }
        if ((!is_dir($this->directory) && !@mkdir($this->directory, 0700) && !is_dir($this->directory)) || !@chmod($this->directory, 0700)) {
            throw new \RuntimeException('Could not prepare private cookie storage. Check storage permissions.');
        }
        if (!is_file($this->directory . '/.gitignore')) {
            $ignore = $this->writePrivate("*\n");
            if (!@rename($ignore, $this->directory . '/.gitignore')) {
                @unlink($ignore);
                throw new \RuntimeException('Could not exclude private cookie storage from Git.');
            }
        }
    }

    /** Exclusive creation with mode 0600 before writing any credential bytes. */
    private function writePrivate(#[\SensitiveParameter] string $content): string
    {
        $path = $this->directory . '/jar-' . bin2hex(random_bytes(16));
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Could not create a private cookie file. Check storage permissions.');
        }
        try {
            if (!@chmod($path, 0600) || @fwrite($handle, $content) !== strlen($content) || !@fflush($handle)) {
                throw new \RuntimeException('Could not write private cookies. Check storage permissions and disk space.');
            }
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            fclose($handle);
        }
        return $path;
    }
}
