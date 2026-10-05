<?php

declare(strict_types=1);

namespace Grav\Plugin\SupertextTranslation;

use Symfony\Component\Yaml\Yaml;

/** A Grav page file: YAML front matter between "---" lines, then Markdown. */
final class PageFile
{
    /** @param array<string, mixed> $header */
    public function __construct(
        public array $header,
        public string $body,
    ) {}

    public static function read(string $path): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Cannot read ' . basename($path));
        }
        return self::fromString($content);
    }

    public static function fromString(string $content): self
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = (string)preg_replace('/^\x{FEFF}/u', '', $content);
        if (preg_match('/^---\n(.*?)\n?---[ \t]*(?:\n|$)(.*)$/s', $content, $m)) {
            $header = trim($m[1]) === '' ? [] : Yaml::parse($m[1]);
            return new self(is_array($header) ? $header : [], ltrim($m[2], "\n"));
        }
        return new self([], $content);
    }

    public function toString(): string
    {
        $yaml = $this->header === [] ? '' : Yaml::dump($this->header, 10, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $body = rtrim($this->body, "\n");
        return "---\n" . $yaml . "---\n" . ($body === '' ? '' : "\n" . $body . "\n");
    }

    public function write(string $path): void
    {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $this->toString()) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write ' . basename($path) . '. Please check the folder permissions.');
        }
    }

    public function get(string $path): mixed
    {
        $value = $this->header;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public function set(string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $ref = &$this->header;
        foreach ($keys as $key) {
            if (!is_array($ref)) {
                $ref = [];
            }
            if (!array_key_exists($key, $ref)) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
    }
}
