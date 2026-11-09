<?php

declare(strict_types=1);

namespace LaraGram\Server\Http;

use LaraGram\Server\Support\FileName;

use LaraGram\Log\LoggerInterface;

/**
 * Turns the body of an HTTP message into Query arguments and files: a port
 * of the body handling in td::HttpReader.
 *
 * The body arrives in pieces through write(), and file parts go straight to
 * temporary files, so an upload costs a small buffer however large it is.
 * URL decoding, form, JSON and multipart parsing, limits and their error
 * codes follow TDLib so arguments match byte for byte.
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

    private const BODY_NONE = 0;
    private const BODY_ARGS = 1;
    private const BODY_MULTIPART = 2;
    private const BODY_FILE = 3;

    private const FORM_SKIP_PROLOGUE = 0;
    private const FORM_READ_PART_HEADERS = 1;
    private const FORM_READ_PART_VALUE = 2;
    private const FORM_READ_FILE = 3;
    private const FORM_CHECK_FOR_LAST_BOUNDARY = 4;
    private const FORM_SKIP_EPILOGUE = 5;

    private string $path = '';

    private string $contentType = 'application/octet-stream';

    private string $contentEncoding = '';

    private int $bodyMode = self::BODY_NONE;

    private string $buffer = '';

    private string $boundary = '';

    private int $formState = self::FORM_SKIP_PROLOGUE;

    /** Bytes of the buffer already known not to start the searched delimiter (form_data_read_length_). */
    private int $readLength = 0;

    private string $fieldName = '';

    private string $fileFieldName = '';

    private string $fileName = '';

    private string $fieldContentType = 'application/octet-stream';

    private bool $inflating = false;

    private \InflateContext|null $inflate = null;

    private string $inflateHead = '';

    private int $inflatedSize = 0;

    /** @var resource|null */
    private $tempHandle = null;

    private string $tempPath = '';

    private int $tempSize = 0;

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
     * Read a whole message at once (the body of a webhook response).
     *
     * @param string $target request target: path plus optional `?query`
     * @param list<array{0: string, 1: string}> $headers lower-cased, trimmed
     * @return array{path: string, args: list<array{0: string, 1: string}>, files: list<HttpFile>, params_size: int}
     *
     * @throws HttpReaderException
     */
    public function read(string $target, array $headers, string $body): array
    {
        try {
            $this->begin($target, $headers);
            if ($body !== '') {
                $this->openBody();
                $this->write($body);
            }

            return $this->finish();
        } catch (HttpReaderException $e) {
            $this->abort();

            throw $e;
        }
    }

    /**
     * Start a message: the URL arguments and the headers that drive the body.
     *
     * @param list<array{0: string, 1: string}> $headers lower-cased, trimmed
     *
     * @throws HttpReaderException
     */
    public function begin(string $target, array $headers): void
    {
        $this->args = [];
        $this->files = [];
        $this->totalParametersLength = 0;
        $this->totalHeadersLength = strlen($target);
        $this->contentType = 'application/octet-stream';
        $this->contentEncoding = '';
        $this->bodyMode = self::BODY_NONE;

        foreach ($headers as [$name, $value]) {
            $this->totalHeadersLength += strlen($name) + strlen($value) + 4;
            if ($name === 'content-type') {
                $this->contentType = $value;
            } elseif ($name === 'content-encoding') {
                $this->contentEncoding = strtolower($value);
            }
        }

        if ($this->totalHeadersLength > self::MAX_TOTAL_HEADERS_LENGTH) {
            throw new HttpReaderException(431, 'Request Header Fields Too Large: total headers size exceeded');
        }

        $this->path = $this->parseUrl($target);
    }

    /**
     * The message has a body: choose how it is consumed (HttpReader::do_read_next).
     *
     * @throws HttpReaderException
     */
    public function openBody(): void
    {
        if ($this->contentEncoding !== '' && $this->contentEncoding !== 'none') {
            if ($this->contentEncoding !== 'gzip' && $this->contentEncoding !== 'deflate') {
                throw new HttpReaderException(415, 'Unsupported Media Type: unsupported content-encoding');
            }
            $this->inflating = true;
        }

        $lowered = strtolower($this->contentType);
        if (str_contains($lowered, 'multipart/form-data')) {
            $this->boundary = "\r\n--".$this->parseBoundary($this->contentType, $lowered);
            $this->formState = self::FORM_SKIP_PROLOGUE;
            $this->readLength = 0;
            $this->bodyMode = self::BODY_MULTIPART;
        } elseif (str_contains($lowered, 'application/x-www-form-urlencoded') || str_contains($lowered, 'application/json')) {
            $this->bodyMode = self::BODY_ARGS;
        } else {
            // MAX_IN_MEMORY_POST_SIZE is 0 in the Bot API server: the whole body becomes the file "file"
            $this->bodyMode = self::BODY_FILE;
        }
    }

    /**
     * The next piece of the body.
     *
     * @throws HttpReaderException
     */
    public function write(string $chunk): void
    {
        if ($this->inflating) {
            $chunk = $this->inflateChunk($chunk);
        }
        if ($chunk === '') {
            return;
        }

        switch ($this->bodyMode) {
            case self::BODY_ARGS:
                if (strlen($this->buffer) + strlen($chunk) > self::MAX_TOTAL_PARAMETERS_LENGTH - $this->totalParametersLength) {
                    throw new HttpReaderException(413, 'Request Entity Too Large: too many parameters');
                }
                $this->buffer .= $chunk;
                break;
            case self::BODY_FILE:
                $this->openTempFile('file', '');
                $this->saveFilePart($chunk);
                break;
            case self::BODY_MULTIPART:
                $this->buffer .= $chunk;
                $this->parseMultipartFormData(false);
                break;
        }
    }

    /**
     * The body ended.
     *
     * @return array{path: string, args: list<array{0: string, 1: string}>, files: list<HttpFile>, params_size: int}
     *
     * @throws HttpReaderException
     */
    public function finish(): array
    {
        if ($this->inflating && !$this->inflateFinished()) {
            throw new HttpReaderException(400, 'Bad Request: gzip decode failed');
        }

        switch ($this->bodyMode) {
            case self::BODY_ARGS:
                $this->parseArgsBody();
                break;
            case self::BODY_FILE:
                if ($this->tempHandle !== null) {
                    $this->closeTempFile('file', '', $this->contentType);
                }
                break;
            case self::BODY_MULTIPART:
                if (!$this->parseMultipartFormData(true)) {
                    throw self::unexpectedEnd();
                }
                break;
        }

        return ['path' => $this->path, 'args' => $this->args, 'files' => $this->files, 'params_size' => $this->totalParametersLength];
    }

    /**
     * Drop everything saved for an unfinished message.
     */
    public function abort(): void
    {
        if ($this->tempHandle !== null) {
            fclose($this->tempHandle);
            $this->tempHandle = null;
            @unlink($this->tempPath);
            @rmdir(dirname($this->tempPath));
        }
        foreach ($this->files as $file) {
            $file->delete();
        }
        $this->files = [];
        $this->buffer = '';
    }

    /**
     * GzipByteFlow in decode mode: gzip or zlib, chosen by the stream itself.
     *
     * @throws HttpReaderException
     */
    private function inflateChunk(string $chunk): string
    {
        if ($this->inflate === null) {
            $this->inflateHead .= $chunk;
            if (strlen($this->inflateHead) < 2) {
                return '';
            }
            $chunk = $this->inflateHead;
            $this->inflateHead = '';
            $isGzip = $chunk[0] === "\x1F" && $chunk[1] === "\x8B";
            $this->inflate = @inflate_init($isGzip ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE) ?: null;
            if ($this->inflate === null) {
                throw new HttpReaderException(400, 'Bad Request: gzip decode failed');
            }
        }

        $decoded = @inflate_add($this->inflate, $chunk, ZLIB_SYNC_FLUSH);
        if ($decoded === false) {
            throw new HttpReaderException(400, 'Bad Request: gzip decode failed');
        }
        $this->inflatedSize += strlen($decoded);
        if ($this->inflatedSize >= self::MAX_CONTENT_SIZE) {
            throw new HttpReaderException(413, 'Request Entity Too Large: content length is '.$this->inflatedSize);
        }

        return $decoded;
    }

    private function inflateFinished(): bool
    {
        return $this->inflate !== null && inflate_get_status($this->inflate) === ZLIB_STREAM_END;
    }

    /**
     * @throws HttpReaderException
     */
    private function parseArgsBody(): void
    {
        $body = $this->buffer;
        $this->buffer = '';
        if ($body === '') {
            return;
        }

        try {
            if (str_contains(strtolower($this->contentType), 'application/x-www-form-urlencoded')) {
                $this->parseParameters($body);
            } else {
                $this->parseJsonParameters($body);
            }
        } catch (HttpReaderException $e) {
            if ($e->getCode() === 413) {
                throw $e;
            }
            // TDLib only logs malformed bodies; the arguments read so far stay.
            $this->logger?->info($e->getMessage());
        }
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

    /**
     * HttpReader::parse_multipart_form_data.
     *
     * @return bool whether the form has been read completely
     *
     * @throws HttpReaderException
     */
    private function parseMultipartFormData(bool $bodyEnded): bool
    {
        while (true) {
            switch ($this->formState) {
                case self::FORM_SKIP_PROLOGUE:
                    $found = $this->findDelimiter(substr($this->boundary, 2));
                    if ($found !== null) {
                        $this->advance($found + strlen($this->boundary) - 2);
                        $this->formState = self::FORM_READ_PART_HEADERS;
                        continue 2;
                    }
                    $this->advance($this->readLength);

                    return false;

                case self::FORM_READ_PART_HEADERS:
                    $found = $this->findDelimiter("\r\n\r\n");
                    if ($found === null) {
                        if ($this->totalHeadersLength + $this->readLength > self::MAX_TOTAL_HEADERS_LENGTH) {
                            throw new HttpReaderException(431, 'Request Header Fields Too Large: total headers size exceeded');
                        }

                        return false;
                    }

                    $this->totalHeadersLength += $found;
                    if ($this->totalHeadersLength > self::MAX_TOTAL_HEADERS_LENGTH) {
                        throw new HttpReaderException(431, 'Request Header Fields Too Large: total headers size exceeded');
                    }
                    if ($found === 0) {
                        throw new HttpReaderException(400, 'Bad Request: headers in multipart/form-data are empty');
                    }

                    // The "\r\n" after the boundary is skipped without being checked.
                    $headers = substr($this->buffer, 2, $found);
                    $this->advance($found + 4);

                    [$this->fieldName, $hasFileName, $this->fileName, $this->fieldContentType] = $this->parsePartHeaders($headers);

                    if ($hasFileName) {
                        if (count($this->files) === self::MAX_FILE_COUNT) {
                            throw new HttpReaderException(413, 'Request Entity Too Large: too many files attached');
                        }
                        $this->fileFieldName = $this->fieldName;
                        $this->formState = self::FORM_READ_FILE;
                    } else {
                        $this->formState = self::FORM_READ_PART_VALUE;
                    }
                    continue 2;

                case self::FORM_READ_PART_VALUE:
                    $found = $this->findDelimiter($this->boundary);
                    $valueLength = $found ?? $this->readLength;
                    if ($this->totalParametersLength + $valueLength > self::MAX_TOTAL_PARAMETERS_LENGTH) {
                        throw new HttpReaderException(413, 'Request Entity Too Large: too many parameters in form data');
                    }
                    if ($found === null) {
                        return false;
                    }

                    $value = substr($this->buffer, 0, $found);
                    $this->advance($found + strlen($this->boundary));
                    if (str_starts_with($this->fieldContentType, 'application/x-www-form-urlencoded')) {
                        // treat the value as ordinary parameters
                        $this->parseParameters($value);
                    } else {
                        $this->totalParametersLength += $valueLength;
                        $this->args[] = [$this->fieldName, $value];
                    }
                    $this->formState = self::FORM_CHECK_FOR_LAST_BOUNDARY;
                    continue 2;

                case self::FORM_READ_FILE:
                    $this->openTempFile($this->fileFieldName, $this->fileName);
                    $found = $this->findDelimiter($this->boundary);
                    if ($found === null) {
                        if ($this->readLength > 0) {
                            $this->saveFilePart(substr($this->buffer, 0, $this->readLength));
                            $this->advance($this->readLength);
                        }

                        return false;
                    }

                    if ($found > 0) {
                        $this->saveFilePart(substr($this->buffer, 0, $found));
                    }
                    $this->advance($found + strlen($this->boundary));
                    $this->closeTempFile($this->fileFieldName, $this->fileName, $this->fieldContentType);
                    $this->formState = self::FORM_CHECK_FOR_LAST_BOUNDARY;
                    continue 2;

                case self::FORM_CHECK_FOR_LAST_BOUNDARY:
                    if (strlen($this->buffer) < 2) {
                        return false;
                    }
                    if ($this->buffer[0] === '-' && $this->buffer[1] === '-') {
                        $this->advance(2);
                        $this->formState = self::FORM_SKIP_EPILOGUE;
                    } else {
                        $this->formState = self::FORM_READ_PART_HEADERS;
                    }
                    continue 2;

                case self::FORM_SKIP_EPILOGUE:
                    $this->buffer = '';

                    return $bodyEnded;
            }
        }
    }

    /**
     * td::find_boundary: the position of the delimiter in the buffer; when it
     * isn't there, readLength says how many leading bytes can't be part of it.
     */
    private function findDelimiter(string $delimiter): ?int
    {
        $position = strpos($this->buffer, $delimiter, $this->readLength);
        if ($position !== false) {
            return $position;
        }
        $this->readLength = max($this->readLength, strlen($this->buffer) - strlen($delimiter) + 1);

        return null;
    }

    private function advance(int $length): void
    {
        $this->buffer = $length >= strlen($this->buffer) ? '' : substr($this->buffer, $length);
        $this->readLength = 0;
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
     *
     * @throws HttpReaderException
     */
    private function openTempFile(string $fieldName, string $fileName): void
    {
        if ($this->tempHandle !== null) {
            return;
        }

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
                $this->tempHandle = $handle;
                $this->tempPath = $path;
                $this->tempSize = 0;

                return;
            }
            @rmdir($directory);
        }

        throw new HttpReaderException(500, 'Internal Server Error: can\'t create temporary file');
    }

    /**
     * @throws HttpReaderException
     */
    private function saveFilePart(string $part): void
    {
        $written = @fwrite($this->tempHandle, $part);
        if ($written !== strlen($part)) {
            throw new HttpReaderException(500, 'Internal Server Error: can\'t save file part');
        }
        $this->tempSize += $written;
    }

    private function closeTempFile(string $fieldName, string $fileName, string $contentType): void
    {
        fclose($this->tempHandle);
        $this->tempHandle = null;
        $this->files[] = new HttpFile($fieldName, $fileName, $contentType, $this->tempSize, $this->tempPath);
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
