<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

/**
 * A file attached to a query (td::HttpFile): a multipart part with a
 * filename, or a whole request body of an unsupported content type.
 *
 * The bytes live in a temporary file, as in TDLib, so large uploads are
 * not kept in worker memory once the request has been read.
 */
final class HttpFile
{
    public function __construct(
        public readonly string $fieldName,
        public readonly string $name,
        public readonly string $contentType,
        public readonly int $size,
        public readonly string $tempFileName,
    ) {
    }

    public function delete(): void
    {
        if ($this->tempFileName !== '' && is_file($this->tempFileName)) {
            @unlink($this->tempFileName);
            // HttpReader::delete_temp_file also removes the per-file directory.
            $directory = dirname($this->tempFileName);
            if (str_starts_with(basename($directory), 'tdlib-server-tmp')) {
                @rmdir($directory);
            }
        }
    }
}
