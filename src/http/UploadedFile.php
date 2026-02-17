<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\http;

use Psr\Http\Message\UploadedFileInterface;
use yii\base\Model;
use yii\helpers\Html;
use yii2\extensions\psrbridge\adapter\ServerRequestAdapter;

use function fclose;
use function is_array;
use function is_resource;
use function is_string;
use function str_ends_with;
use function str_starts_with;
use function substr;

/**
 * Uploaded file handler with PSR-7 bridge support.
 *
 * Provides a drop-in replacement for {@see \yii\web\UploadedFile} that integrates PSR-7 UploadedFileInterface handling,
 * enabling seamless interoperability with PSR-7 compatible HTTP stacks and modern PHP runtimes.
 *
 * This class allows file data to be sourced from either the global $_FILES array or a PSR-7 ServerRequestAdapter,
 * supporting both traditional SAPI and worker-based environments. The internal file cache is populated accordingly,
 * ensuring compatibility with Yii2 file validation and processing workflows.
 *
 * Key features.
 * - Compatible with both legacy and modern PHP runtimes.
 * - Conversion utilities for PSR-7 UploadedFileInterface to Yii2 format.
 * - Internal cache for efficient file lookup and repeated access.
 * - PSR-7 ServerRequestAdapter integration for file handling without global state.
 *
 * @see ServerRequestAdapter for PSR-7 to Yii2 file adapter.
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
class UploadedFile extends \yii\web\UploadedFile
{
    /**
     * @var array[] Uploaded files cache.
     *
     * @phpstan-var array<
     *   string,
     *   array{
     *     name: string,
     *     tempName: string,
     *     tempResource: resource|null,
     *     type: string,
     *     size: int,
     *     error: int,
     *     fullPath: string|null
     *   }
     * >
     */
    public static $_files = [];

    /**
     * PSR-7 ServerRequestAdapter for bridging PSR-7 UploadedFileInterface.
     */
    private static ServerRequestAdapter|null $psr7Adapter = null;

    /**
     * Returns the instance of the uploaded file associated with the specified model attribute.
     *
     * Retrieves the uploaded file instance for the given model and attribute, supporting array-indexed attribute names
     * such as '[1]file' for tabular uploads or 'file[1]' for file arrays. The method resolves the input name using
     * {@see Html::getInputName()} and delegates to {@see getInstanceByName()} for file lookup.
     *
     * @param Model $model Data model.
     * @param string $attribute Attribute name, which may contain array indexes (for example, '[1]file', 'file[1]').
     *
     * @return UploadedFile|null Instance of the uploaded file, or `null` if no file was uploaded for the attribute.
     *
     * Usage example:
     * ```php
     * $file = UploadedFile::getInstance($model, 'file');
     *
     * if ($file !== null) {
     *     // process the uploaded file
     * }
     * ```
     */
    public static function getInstance($model, $attribute): self|null
    {
        $name = Html::getInputName($model, $attribute);

        return static::getInstanceByName($name);
    }

    /**
     * Returns the instance of the uploaded file for the specified input name.
     *
     * @param string $name Name of the file input field.
     *
     * @return self|null Instance of the uploaded file, or `null` if no file was uploaded for the name.
     *
     * Usage example:
     * ```php
     * $file = UploadedFile::getInstanceByName('file');
     *
     * if ($file !== null) {
     *     // process the uploaded file
     * }
     * ```
     */
    public static function getInstanceByName($name): self|null
    {
        $files = self::loadFiles();

        return isset($files[$name]) ? new self($files[$name]) : null;
    }

    /**
     * Returns an array of uploaded file instances associated with the specified model attribute.
     *
     * Resolves the input name for the given model and attribute using {@see Html::getInputName()} and delegates to
     * {@see getInstancesByName()} for file lookup. Supports array-indexed attribute names for tabular file uploading,
     * such as '[1]file'.
     *
     * @param Model $model Data model.
     * @param string $attribute Attribute name, which may contain array indexes (for example, '[1]file').
     *
     * @return array Array of UploadedFile objects for the specified attribute. Returns an empty array if no
     * files were uploaded.
     *
     * @phpstan-return UploadedFile[]
     *
     * Usage example:
     * ```php
     * $files = UploadedFile::getInstances($model, 'file');
     *
     * foreach ($files as $file) {
     *     // process each uploaded file
     * }
     * ```
     */
    public static function getInstances($model, $attribute): array
    {
        $name = Html::getInputName($model, $attribute);

        return static::getInstancesByName($name);
    }

    /**
     * Returns an array of uploaded file instances for the specified input name.
     *
     * Iterates over the internal file cache and collects all uploaded files whose keys match the given input name or
     * are prefixed with the input name followed by an opening bracket (for array-style file inputs).
     *
     * @param string $name Name of the file input field or array of files.
     *
     * @return array Array of UploadedFile objects for the specified input name. Returns an empty array if no files were
     * uploaded.
     *
     * @phpstan-return UploadedFile[]
     *
     * Usage example:
     * ```php
     * $files = UploadedFile::getInstancesByName('file');
     * foreach ($files as $file) {
     *     // process each uploaded file
     * }
     * ```
     */
    public static function getInstancesByName($name): array
    {
        $files = self::loadFiles();

        if (str_ends_with($name, '[]')) {
            $name = substr($name, 0, -2);
        }

        if (isset($files[$name])) {
            return [new self($files[$name])];
        }

        $results = [];

        foreach ($files as $key => $file) {
            if (str_starts_with($key, "{$name}[")) {
                $results[] = new self($file);
            }
        }

        return $results;
    }

    /**
     * Resets the internal uploaded files cache, PSR-7 adapter state, and closes any open tempResource handles.
     *
     * Clears all cached uploaded file data, PSR-7 adapter references and closes any open tempResource handles, ensuring
     * a clean state for subsequent file handling operations. This method should be called to fully reset the file
     * handling environment, including both legacy and PSR-7 file sources.
     *
     * Usage example:
     * ```php
     * UploadedFile::reset();
     * ```
     */
    public static function reset(): void
    {
        self::closeResources();

        self::$_files = [];
        self::$psr7Adapter = null;
    }

    /**
     * Sets the PSR-7 ServerRequestAdapter for file handling.
     *
     * Configures the bridge to use PSR-7 UploadedFileInterface data instead of reading from $_FILES global.
     *
     * This method should be called when initializing PSR-7 request processing to enable clean file handling without
     * global state modification.
     *
     * The adapter will be used by {@see loadFiles()} to populate the internal file cache directly from PSR-7 data,
     * ensuring compatibility with both worker environments and traditional SAPI setups.
     *
     * @param ServerRequestAdapter $adapter PSR-7 adapter containing UploadedFileInterface instances.
     *
     * Usage example:
     * ```php
     * UploadedFile::setPsr7Adapter($serverRequestAdapter);
     * ```
     */
    public static function setPsr7Adapter(ServerRequestAdapter $adapter): void
    {
        self::$psr7Adapter = $adapter;
    }

    /**
     * Closes any open tempResource handles stored in the internal cache.
     */
    private static function closeResources(): void
    {
        foreach (self::$_files as $entry) {
            if (is_resource($entry['tempResource'])) {
                @fclose($entry['tempResource']);
            }
        }
    }

    /**
     * Converts a PSR-7 UploadedFileInterface to Yii2 UploadedFile format.
     *
     * Maps PSR-7 file properties to the array structure expected by Yii2 UploadedFile constructor, ensuring proper
     * handling of file metadata, error codes, and stream resources.
     *
     * Handles edge cases such as missing metadata, stream resource extraction, and proper error code mapping to
     * maintain full compatibility with Yii2 file validation and processing workflows.
     *
     * @param UploadedFileInterface $psr7File PSR-7 UploadedFileInterface to convert.
     *
     * @return array Yii2 compatible file data array.
     *
     * @phpstan-return array{
     *   name: string,
     *   tempName: string,
     *   tempResource: resource|null,
     *   type: string,
     *   size: int,
     *   error: int,
     *   fullPath: string|null
     * }
     */
    private static function convertPsr7FileToLegacyFormat(UploadedFileInterface $psr7File): array
    {
        $error = $psr7File->getError();

        if ($error !== UPLOAD_ERR_OK) {
            return [
                'name' => $psr7File->getClientFilename() ?? '',
                'tempName' => '',
                'tempResource' => null,
                'type' => $psr7File->getClientMediaType() ?? '',
                'size' => (int) $psr7File->getSize(),
                'error' => $error,
                'fullPath' => null,
            ];
        }

        $stream = $psr7File->getStream();
        $uri = $stream->getMetadata('uri');

        return [
            'name' => $psr7File->getClientFilename() ?? '',
            'tempName' => is_string($uri) ? $uri : '',
            'tempResource' => $stream->detach(),
            'type' => $psr7File->getClientMediaType() ?? '',
            'size' => (int) $psr7File->getSize(),
            'error' => $error,
            'fullPath' => null,
        ];
    }

    /**
     * Loads and returns the internal uploaded files cache.
     *
     * Populates the internal file cache from either the PSR-7 adapter or the legacy $_FILES global, depending on the
     * current configuration and state.
     *
     * This method ensures that the file cache is initialized only once per request lifecycle, and subsequent calls
     * return the cached result.
     *
     * @return array Internal uploaded files cache.
     *
     * @phpstan-return array<
     *   string,
     *   array{
     *     name: string,
     *     tempName: string,
     *     tempResource: resource|null,
     *     type: string,
     *     size: int,
     *     error: int,
     *     fullPath: string|null,
     *   }
     * >
     */
    private static function loadFiles(): array
    {
        if (self::$_files === []) {
            if (self::$psr7Adapter !== null) {
                self::loadPsr7Files();
            } elseif (self::$psr7Adapter === null) {
                self::loadLegacyFiles();
            }
        }

        return self::$_files;
    }

    /**
     * Recursive reformats data of uploaded file(s) for legacy compatibility.
     *
     * This is a copy of the parent class private method to maintain compatibility with legacy $_FILES processing.
     *
     * The method handles both single files and array structures, preserving the original Yii2 logic.
     *
     * @param string $key Key for identifying uploaded file (sub-array index).
     * @param string|string[] $names File name(s) provided by PHP.
     * @param string|string[] $tempNames Temporary file name(s) provided by PHP.
     * @param string|string[] $types File type(s) provided by PHP.
     * @param int|int[] $sizes File size(s) provided by PHP.
     * @param int|int[] $errors Uploading issue(s) provided by PHP.
     * @param mixed[]|string|null $fullPaths Full path(s) as submitted by the browser/PHP.
     * @param mixed|mixed[] $tempResources Resource(s) of temporary file(s) provided by PHP.
     */
    private static function loadFilesRecursiveInternal(
        string $key,
        array|string $names,
        array|string $tempNames,
        array|string $types,
        array|int $sizes,
        array|int $errors,
        array|string|null $fullPaths,
        mixed $tempResources,
    ): void {
        if (is_array($names)) {
            foreach ($names as $i => $name) {
                self::loadFilesRecursiveInternal(
                    $key . '[' . $i . ']',
                    $name,
                    $tempNames[$i] ?? '',
                    $types[$i] ?? '',
                    $sizes[$i] ?? 0,
                    $errors[$i] ?? UPLOAD_ERR_NO_FILE,
                    isset($fullPaths[$i]) && is_string($fullPaths[$i]) ? $fullPaths[$i] : null,
                    (is_array($tempResources) && isset($tempResources[$i])) ? $tempResources[$i] : null,
                );
            }
        } elseif ($errors !== UPLOAD_ERR_NO_FILE) {
            self::$_files[$key] = [
                'name' => $names,
                'tempName' => is_array($tempNames) ? '' : $tempNames,
                'tempResource' => is_resource($tempResources) ? $tempResources : null,
                'type' => is_array($types) ? '' : $types,
                'size' => is_array($sizes) ? 0 : $sizes,
                'error' => is_array($errors) ? UPLOAD_ERR_NO_FILE : $errors,
                'fullPath' => is_string($fullPaths) ? $fullPaths : null,
            ];
        }
    }

    /**
     * Loads uploaded files from legacy $_FILES global array.
     *
     * Provides fallback functionality for traditional SAPI environments where PSR-7 is not available.
     *
     * This method is called automatically when no PSR-7 adapter is set, ensuring seamless operation in legacy
     * environments without any configuration changes.
     */
    private static function loadLegacyFiles(): void
    {
        /**
         * @phpstan-var array<
         *   string,
         *     array{
         *       name: string|string[],
         *       tmp_name: string|string[],
         *       type: string|string[],
         *       size: int|int[],
         *       error: int|int[],
         *       full_path?: string|string[]|null,
         *       tmp_resource?: resource|null|array<mixed>,
         *    }
         * > $_FILES
         */
        foreach ($_FILES as $key => $info) {
            self::loadFilesRecursiveInternal(
                $key,
                $info['name'],
                $info['tmp_name'],
                $info['type'],
                $info['size'],
                $info['error'],
                $info['full_path'] ?? null,
                $info['tmp_resource'] ?? null,
            );
        }
    }

    /**
     * Loads uploaded files from PSR-7 UploadedFileInterface instances.
     *
     * Converts PSR-7 UploadedFileInterface data to Yii2 compatible format and populates the internal file cache
     * directly, avoiding global $_FILES manipulation while maintaining full compatibility with Yii2 file handling
     * expectations.
     *
     * Handles both simple file uploads and complex nested file arrays, ensuring proper structure preservation for
     * form-based file uploads with array notation.
     *
     * This method enables clean separation of PSR-7 file handling from global state, improving testability and worker
     * environment compatibility.
     */
    private static function loadPsr7Files(): void
    {
        /** @phpstan-var array<string, UploadedFileInterface|mixed[]> */
        $uploadedFiles = self::$psr7Adapter?->getUploadedFiles() ?? [];

        foreach ($uploadedFiles as $name => $file) {
            self::processPsr7File($name, $file);
        }
    }

    /**
     * Processes a PSR-7 UploadedFileInterface and converts it to Yii2 format.
     *
     * Handles both single files and arrays of files, recursively processing nested structures to maintain compatibility
     * with complex form-based file uploads using array notation.
     *
     * Converts PSR-7 UploadedFileInterface properties to the format expected by Yii2 UploadedFile, including proper
     * error code mapping and stream resource handling.
     *
     * @param string $name Field name for the uploaded file(s).
     * @param mixed[]|UploadedFileInterface $file PSR-7 UploadedFileInterface or array of files.
     */
    private static function processPsr7File(string $name, UploadedFileInterface|array $file): void
    {
        if ($file instanceof UploadedFileInterface) {
            self::$_files[$name] = self::convertPsr7FileToLegacyFormat($file);
        } elseif (is_array($file)) {
            foreach ($file as $key => $nestedFile) {
                $nestedName = $name . '[' . $key . ']';

                if ($nestedFile instanceof UploadedFileInterface || is_array($nestedFile)) {
                    self::processPsr7File($nestedName, $nestedFile);
                }
            }
        }
    }
}
