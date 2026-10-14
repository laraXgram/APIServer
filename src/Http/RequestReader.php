<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Server\Support\FileName;

use LaraGram\Log\LoggerInterface;

/**
 * Turns a buffered HTTP request into Query arguments and files: a port of
 * the body handling in td::HttpReader.
 *
 * Swoole frames the request and decodes chunked transfer encoding; every
 * other step (URL decoding, form, JSON and multipart parsing, limits and
 * their error codes) follows TDLib so arguments match byte for byte.
 */
final class RequestReader
{
    /** Some reasonable limits, as in td::HttpReader. */
    public const MAX_CONTENT_SIZE = 4294967295;
    public const MAX_TOTAL_PARAMETERS_LENGTH = 1 << 20;
    public const MAX_TOTAL_HEADERS_LENGTH = 1 << 18;
    public const MAX_BOUNDARY_LENGTH = 70;

    /** HttpServer::MAX_FILE_COUNT */
    public const MAX_FILE_COUNT = 50;

    private int $totalParametersLength = 0;

    private int $totalHeadersLength = 0;

    /** @var list<array{0: string, 1: string}> */
    private array $args = [];

    /** @var list<HttpFile> */
    private array $files = [];

    public function __construct(
        private readonly string $tempDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param string $target request target: path plus optional `?query`
     * @param list<array{0: string, 1: string}> $headers lower-cased, trimmed
     * @return array{path: string, args: list<array{0: string, 1: string}>, files: list<HttpFile>}
     *
     * @throws HttpReaderException
     */
    public function read(string $target, array $headers, string $body): array
    {
        $this->args = [];
        $this->files = [];
        $this->totalParametersLength = 0;
        $this->totalHeadersLength = strlen($target);

        $contentType = '';
        $contentEncoding = '';
        foreach ($headers as [$name, $value]) {
            $this->totalHeadersLength += strlen($name) + strlen($value) + 4;
            if ($name === 'content-type') {
                $contentType = $value;
            } elseif ($name === 'content-encoding') {
                $contentEncoding = strtolower($value);
            }
        }

        if ($this->totalHeadersLength > self::MAX_TOTAL_HEADERS_LENGTH) {
            throw new HttpReaderException(431, 'Request Header Fields Too Large: total headers size exceeded');
        }

        $path = $this->parseUrl($target);

        try {
            if ($body !== '') {
                $this->readBody($body, $contentType, $contentEncoding);
            }
        } catch (HttpReaderException $e) {
            foreach ($this->files as $file) {
                $file->delete();
            }

            throw $e;
        }

        return ['path' => $path, 'args' => $this->args, 'files' => $this->files];
    }

    private function readBody(string $body, string $contentType, string $contentEncoding): void
    {
        if ($contentEncoding !== '' && $contentEncoding !== 'none') {
            if ($contentEncoding !== 'gzip' && $contentEncoding !== 'deflate') {
                throw new HttpReaderException(415, 'Unsupported Media Type: unsupported content-encoding');
            }

            $decoded = @zlib_decode($body);
            if ($decoded === false) {
                throw new HttpReaderException(400, 'Bad Request: gzip decode failed');
            }
            $body = $decoded;
        }

        if (strlen($body) >= self::MAX_CONTENT_SIZE) {
            throw new HttpReaderException(413, 'Request Entity Too Large: content length is '.strlen($body));
        }

        $lowered = strtolower($contentType);

        if (str_contains($lowered, 'multipart/form-data')) {
            $this->parseMultipartFormData($body, $this->parseBoundary($contentType, $lowered));

            return;
        }

        $isForm = str_contains($lowered, 'application/x-www-form-urlencoded');
        if ($isForm || str_contains($lowered, 'application/json')) {
            if (strlen($body) > self::MAX_TOTAL_PARAMETERS_LENGTH - $this->totalParametersLength) {
                throw new HttpReaderException(413, 'Request Entity Too Large: too many parameters');
            }

            try {
                $isForm ? $this->parseParameters($body) : $this->parseJsonParameters($body);
            } catch (HttpReaderException $e) {
                if ($e->getCode() === 413) {
                    throw $e;
                }
                // TDLib only logs malformed bodies; the arguments read so far stay.
                $this->logger?->info($e->getMessage());
            }

            return;
        }

        // Any other content type: the whole body becomes the file "file"
        // (MAX_IN_MEMORY_POST_SIZE is 0 in the Bot API server).
        $this->files[] = $this->saveFile('file', '', $contentType, $body);
    }

    private function parseUrl(string $target): string
    {
        $pathSize = strcspn($target, '?#');
        $path = self::urlDecode(substr($target, 0, $pathSize), false);

        if ($pathSize < strlen($target) && $target[$pathSize] === '?') {
            $this->parseParameters(substr($target, $pathSize + 1));
        }

        return $path;
    }

    /**
     * `key=value&key=value`, with `+` decoded as a space.
     */
    private function parseParameters(string $parameters): void
    {
        $this->totalParametersLength += strlen($parameters);
        if ($this->totalParametersLength > self::MAX_TOTAL_PARAMETERS_LENGTH) {
            throw new HttpReaderException(413, 'Request Entity Too Large: too many parameters');
        }

        if ($parameters === '') {
            return;
        }

        // Empty pieces ("a&&b") are arguments too; only a trailing '&' adds none.
        $pieces = explode('&', $parameters);
        if (str_ends_with($parameters, '&')) {
            array_pop($pieces);
        }

        foreach ($pieces as $keyValue) {
            $eq = strpos($keyValue, '=');
            if ($eq === false) {
                $this->args[] = [self::urlDecode($keyValue, true), ''];
            } else {
                $this->args[] = [self::urlDecode(substr($keyValue, 0, $eq), true), self::urlDecode(substr($keyValue, $eq + 1), true)];
            }
        }
    }

    /**
     * A JSON object whose string values are decoded and whose other values
     * are kept as their raw JSON text; a bare JSON string becomes `content`.
     */
    private function parseJsonParameters(string $parameters): void
    {
        if ($parameters === '') {
            return;
        }

        $this->totalParametersLength += strlen($parameters);
        if ($this->totalParametersLength > self::MAX_TOTAL_PARAMETERS_LENGTH) {
            throw new HttpReaderException(413, 'Request Entity Too Large: too many parameters');
        }

        $parser = new JsonScanner($parameters);
        $parser->skipWhitespaces();

        if ($parser->peek() === '"') {
            $value = $parser->decodeString('Bad Request: can\'t parse string content: ');
            if (!$parser->isEmpty()) {
                throw new HttpReaderException(400, 'Bad Request: extra data after string');
            }
            $this->args[] = ['content', $value];

            return;
        }

        if (!$parser->trySkip('{')) {
            throw new HttpReaderException(400, 'Bad Request: JSON object expected');
        }

        while (true) {
            $parser->skipWhitespaces();
            if ($parser->trySkip('}')) {
                $parser->skipWhitespaces();
                if ($parser->isEmpty()) {
                    return;
                }
                throw new HttpReaderException(400, 'Bad Request: unexpected data after object end');
            }
            if ($parser->isEmpty()) {
                throw new HttpReaderException(400, 'Bad Request: expected parameter name');
            }

            $key = $parser->decodeString('Bad Request: can\'t parse parameter name: ');
            $parser->skipWhitespaces();
            if (!$parser->trySkip(':')) {
                throw new HttpReaderException(400, 'Bad Request: can\'t parse object, \':\' expected');
            }
            $parser->skipWhitespaces();

            $value = $parser->peek() === '"'
                ? $parser->decodeString('Bad Request: can\'t parse parameter value: ')
                : $parser->rawValue('Bad Request: can\'t parse parameter value: ');

            $this->args[] = [$key, $value];

            $parser->skipWhitespaces();
            if ($parser->peek() !== '}' && !$parser->trySkip(',')) {
                throw new HttpReaderException(400, 'Bad Request: expected next field or object end');
            }
        }
    }

    private function parseBoundary(string $contentType, string $lowered): string
    {
        $p = strpos($lowered, 'boundary');
        if ($p === false) {
            throw new HttpReaderException(400, 'Bad Request: boundary not found');
        }

        $eq = strpos($contentType, '=', $p + 8);
        if ($eq === false) {
            throw new HttpReaderException(400, 'Bad Request: boundary value not found');
        }

        $start = $eq + 1;
        $end = strpos($contentType, ';', $start);
        if ($end === false) {
            $end = strlen($contentType);
        }
        if ($start < strlen($contentType) && $contentType[$start] === '"' && $start + 1 < $end && $contentType[$end - 1] === '"') {
            $start++;
            $end--;
        }

        $boundary = substr($contentType, $start, $end - $start);
        if ($boundary === '' || strlen($boundary) > self::MAX_BOUNDARY_LENGTH) {
            throw new HttpReaderException(400, 'Bad Request: boundary too big or empty');
        }

        return $boundary;
    }

    private function parseMultipartFormData(string $body, string $boundaryValue): void
    {
        $boundary = "\r\n--".$boundaryValue;
        $boundaryLength = strlen($boundary);
        $length = strlen($body);

        // Skip the prologue up to the first "--boundary".
        $found = strpos($body, substr($boundary, 2));
        if ($found === false) {
            throw self::unexpectedEnd();
        }
        $pos = $found + $boundaryLength - 2;

        while (true) {
            // Part headers, terminated by an empty line.
            $headersEnd = strpos($body, "\r\n\r\n", $pos);
            if ($headersEnd === false) {
                if ($this->totalHeadersLength + ($length - $pos) > self::MAX_TOTAL_HEADERS_LENGTH) {
                    throw new HttpReaderException(431, 'Request Header Fields Too Large: total headers size exceeded');
                }
                throw self::unexpectedEnd();
            }

            $headersLength = $headersEnd - $pos;
            $this->totalHeadersLength += $headersLength;
            if ($this->totalHeadersLength > self::MAX_TOTAL_HEADERS_LENGTH) {
                throw new HttpReaderException(431, 'Request Header Fields Too Large: total headers size exceeded');
            }
            if ($headersLength === 0) {
                throw new HttpReaderException(400, 'Bad Request: headers in multipart/form-data are empty');
            }

            // The "\r\n" after the boundary is skipped without being checked.
            $headers = substr($body, $pos + 2, $headersLength);
            $pos = $headersEnd + 4;

            [$fieldName, $hasFileName, $fileName, $fieldContentType] = $this->parsePartHeaders($headers);

            $valueEnd = strpos($body, $boundary, $pos);

            if ($hasFileName) {
                if (count($this->files) === self::MAX_FILE_COUNT) {
                    throw new HttpReaderException(413, 'Request Entity Too Large: too many files attached');
                }
                if ($valueEnd === false) {
                    throw self::unexpectedEnd();
                }

                $this->files[] = $this->saveFile($fieldName, $fileName, $fieldContentType, substr($body, $pos, $valueEnd - $pos));
            } else {
                $valueLength = ($valueEnd === false ? $length : $valueEnd) - $pos;
                if ($this->totalParametersLength + $valueLength > self::MAX_TOTAL_PARAMETERS_LENGTH) {
                    throw new HttpReaderException(413, 'Request Entity Too Large: too many parameters in form data');
                }
                if ($valueEnd === false) {
                    throw self::unexpectedEnd();
                }

                $value = substr($body, $pos, $valueLength);
                if (str_starts_with($fieldContentType, 'application/x-www-form-urlencoded')) {
                    $this->parseParameters($value);
                } else {
                    $this->totalParametersLength += $valueLength;
                    $this->args[] = [$fieldName, $value];
                }
            }

            $pos = $valueEnd + $boundaryLength;

            // "--" after a boundary closes the body; anything after it is the epilogue.
            if ($length - $pos < 2) {
                throw self::unexpectedEnd();
            }
            if ($body[$pos] === '-' && $body[$pos + 1] === '-') {
                return;
            }
        }
    }

    /**
     * @return array{0: string, 1: bool, 2: string, 3: string} field name, has file name, file name, content type
     */
    private function parsePartHeaders(string $headers): array
    {
        $fieldName = '';
        $fileName = '';
        $hasFileName = false;
        $contentType = 'application/octet-stream';

        $pos = 0;
        $length = strlen($headers);

        while ($pos < $length) {
            $colon = strpos($headers, ':', $pos);
            if ($colon === false) {
                throw new HttpReaderException(400, 'Bad Request: can\'t parse form data headers');
            }
            $headerName = strtolower(self::trim(substr($headers, $pos, $colon - $pos)));
            $pos = $colon + 1;

            // The value runs to "\r\n", continued by lines starting with a space or tab.
            $valueStart = $pos;
            do {
                $cr = strpos($headers, "\r", $pos);
                if ($cr === false || $cr + 1 >= $length || $headers[$cr + 1] !== "\n") {
                    throw new HttpReaderException(400, 'Bad Request: can\'t parse form data headers');
                }
                $valueEnd = $cr;
                $pos = $cr + 2;
            } while ($pos < $length && ($headers[$pos] === ' ' || $headers[$pos] === "\t"));

            $headerValue = self::trim(substr($headers, $valueStart, $valueEnd - $valueStart));

            if ($headerName === 'content-disposition') {
                [$name, $file] = $this->parseContentDisposition($headerValue);
                if ($name !== null) {
                    $fieldName = $name;
                }
                if ($file !== null) {
                    $fileName = $file;
                    $hasFileName = true;
                }
            } elseif ($headerName === 'content-type') {
                $contentType = $headerValue;
            }
        }

        if ($fieldName === '') {
            throw new HttpReaderException(400, 'Bad Request: field name in multipart/form-data not found');
        }

        return [$fieldName, $hasFileName, $fileName, $contentType];
    }

    /**
     * @return array{0: string|null, 1: string|null} the last `name` and `filename` values
     */
    private function parseContentDisposition(string $value): array
    {
        if (substr($value, 0, 10) !== 'form-data;') {
            throw new HttpReaderException(400, 'Bad Request: expected form-data content disposition');
        }

        $name = null;
        $fileName = null;
        $value = substr($value, 10);

        while (true) {
            $value = self::trim($value);
            $eq = strpos($value, '=');
            if ($eq === false) {
                break;
            }

            $key = self::trim(substr($value, 0, $eq));
            $value = ltrim(substr($value, $eq + 1), " \t\r\n\0\x0B");

            if ($value !== '' && $value[0] === '"') {
                $decoded = '';
                $i = 1;
                $length = strlen($value);
                while (true) {
                    if ($i === $length) {
                        throw new HttpReaderException(400, 'Bad Request: unclosed quoted string in Content-Disposition header');
                    }
                    $c = $value[$i++];
                    if ($c === '"') {
                        break;
                    }
                    if ($c === '\\') {
                        if ($i === $length) {
                            throw new HttpReaderException(400, 'Bad Request: wrong escape sequence in Content-Disposition header');
                        }
                        $c = $value[$i++];
                    }
                    $decoded .= $c;
                }

                $value = ltrim(substr($value, $i), " \t\r\n\0\x0B");
                if ($value !== '') {
                    if ($value[0] !== ';') {
                        throw new HttpReaderException(400, 'Bad Request: expected \';\' in Content-Disposition header');
                    }
                    $value = substr($value, 1);
                }
            } else {
                $semicolon = strpos($value, ';');
                if ($semicolon !== false) {
                    $decoded = self::trim(substr($value, 0, $semicolon));
                    $value = substr($value, $semicolon + 1);
                } else {
                    $decoded = self::trim($value);
                    $value = '';
                }
            }

            $decoded = self::urlDecode($decoded, false);

            if ($key === 'name') {
                $name = $decoded;
            } elseif ($key === 'filename') {
                $fileName = $decoded;
            }
        }

        return [$name, $fileName];
    }

    /**
     * HttpReader::open_temp_file: the file is stored under its cleaned
     * client name in a new "tdlib-server-tmpXXXXXX" directory.
     */
    private function saveFile(string $fieldName, string $fileName, string $contentType, string $content): HttpFile
    {
        $directory = self::makeTempDirectory($this->tempDir, 'tdlib-server-tmp');
        if ($directory !== null) {
            $cleanName = FileName::clean($fieldName === 'file' && $fileName === '' ? 'file' : $fileName);
            foreach ([$cleanName === '' ? 'file' : $cleanName, 'file'] as $name) {
                $path = $directory.'/'.$name;
                $handle = @fopen($path, 'x');
                if ($handle === false) {
                    continue;
                }
                @chmod($path, 0640);
                $written = @fwrite($handle, $content);
                fclose($handle);
                if ($written === strlen($content)) {
                    return new HttpFile($fieldName, $fileName, $contentType, strlen($content), $path);
                }
                @unlink($path);
                break;
            }
            @rmdir($directory);
        }

        throw new HttpReaderException(500, 'Internal Server Error: can\'t create temporary file');
    }

    /**
     * td::mkdtemp: a new directory "<dir>/<prefix>XXXXXX".
     */
    private static function makeTempDirectory(string $dir, string $prefix): ?string
    {
        $base = realpath($dir === '' ? sys_get_temp_dir() : $dir);
        if ($base === false) {
            return null;
        }
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, 61)];
            }
            $path = rtrim($base, '/').'/'.$prefix.$suffix;
            if (@mkdir($path, 0700)) {
                return $path;
            }
        }

        return null;
    }

    private static function unexpectedEnd(): HttpReaderException
    {
        return new HttpReaderException(400, 'Bad Request: unexpected end of request content');
    }

    /**
     * td::url_decode: `%XX` with two hex digits, optionally `+` as a space;
     * any malformed escape is kept as it is.
     */
    public static function urlDecode(string $value, bool $plusAsSpace): string
    {
        if ($plusAsSpace) {
            $value = strtr($value, '+', ' ');
        }

        if (!str_contains($value, '%')) {
            return $value;
        }

        // td only decodes "%XY" when it is not the last two characters' prefix
        // (it requires from_i + 2 < n), which matches a full "%XY" sequence.
        return preg_replace_callback('/%([0-9A-Fa-f]{2})/', static fn (array $m): string => chr(hexdec($m[1])), $value);
    }

    /**
     * td::trim: strips ' ', \t, \r, \n, \0 and \v.
     */
    public static function trim(string $value): string
    {
        return trim($value, " \t\r\n\0\x0B");
    }
}
