<?php

declare(strict_types=1);

namespace Genkgo\Favicon;

final class AppleSafariPinGenerator implements GeneratorInterface
{
   public function __construct(
        private readonly Input $input,
        private readonly ?string $executable = null
    ) {
    }

    private function getExecutable(): string
{
        // 1. Try system PATH
        foreach (['magick', 'convert'] as $cmd) {
            $path = \trim((string) \shell_exec("command -v $cmd 2>/dev/null"));
            if ($path !== '' && \is_executable($path)) {
                return $path;
            }
        }

        // 2. Fallback to known locations
        foreach ([
            '/usr/local/imagemagick7/bin/magick',
            '/usr/local/bin/magick',
            '/usr/bin/magick',
            '/usr/local/bin/convert',
            '/usr/bin/convert',
        ] as $candidate) {
            if (\is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not find ImageMagick executable.');
    }

    public function generate(): string
    {
        if ($this->input->type === InputImageType::SVG) {
            return \stream_get_contents($this->input->newResourceHandle());
        }

        // If someone knows how to convert a PNG to SVG by using the Imagick extension in PHP, I'd love to know how.
        // https://github.com/Imagick/imagick/issues/622
        return $this->tempFile(
            function ($source, $target) {
                $sourceHandle = \fopen($source, 'r+');
                \stream_copy_to_stream($this->input->newResourceHandle(), $sourceHandle);
                \fclose($sourceHandle);

                $descriptor = [
                    0 => ["pipe", "r"],
                    1 => ["pipe", "w"],
                    2 => ["pipe", "w"],
                ];

                $executable = $this->getExecutable();

                $process = \proc_open(
                    [$executable, $source, 'SVG:' . $target],
                    $descriptor,
                    $pipes,
                    '/tmp'
                );

                if (!\is_resource($process)) {
                    throw new \RuntimeException('Failed to start ImageMagick process using executable: ' . $executable);
                }

                $stdout = isset($pipes[1]) && \is_resource($pipes[1])
                    ? \stream_get_contents($pipes[1])
                    : '';

                $stderr = isset($pipes[2]) && \is_resource($pipes[2])
                    ? \stream_get_contents($pipes[2])
                    : '';

                $return = \proc_close($process);

                if ($return !== 0) {
                    throw new \RuntimeException(
                        'Failed to convert PNG to SVG. Got return code ' . $return . '. ' . $stdout . $stderr
                    );
                }

                return \file_get_contents($target);
            }
        );
    }

    public function tempFile(callable $callback, string $prefix = 'favicon-tmp'): mixed
    {
        $tempSource = \tempnam(\sys_get_temp_dir(), $prefix);
        if ($tempSource === false) {
            throw new \UnexpectedValueException('Cannot create temporary file');
        }

        $tempTarget = \tempnam(\sys_get_temp_dir(), $prefix);
        if ($tempTarget === false) {
            \unlink($tempSource);
            throw new \UnexpectedValueException('Cannot create temporary file');
        }

        try {
            return $callback($tempSource, $tempTarget);
        } finally {
            if (\is_file($tempSource)) {
                \unlink($tempSource);
            }
            if (\is_file($tempTarget)) {
                \unlink($tempTarget);
            }
        }
    }

    public static function cliImageMagick6(Input $input): self
    {
        return new self($input, 'convert');
    }

    public static function cliImageMagick7(Input $input): self
    {
        return new self($input, 'magick');
    }

    public static function cliDetectImageMagickVersion(Input $input): self
    {
        $version = \Imagick::getVersion();
        if (\str_starts_with($version['versionString'], 'ImageMagick 7')) {
            return self::cliImageMagick7($input);
        }

        if (\str_starts_with($version['versionString'], 'ImageMagick 6')) {
            return self::cliImageMagick6($input);
        }

        throw new \RuntimeException('Failed to detect ImageMagick version, version: ' . $version['versionString']);
    }
}
