<?php

declare(strict_types=1);

namespace yii2\extensions\psrbridge\creator;

use Psr\Http\Message\{StreamFactoryInterface, UploadedFileFactoryInterface, UploadedFileInterface};
use Throwable;
use yii\base\InvalidArgumentException;
use yii2\extensions\psrbridge\exception\Message;

use function array_key_exists;
use function array_map;
use function is_array;
use function is_int;
use function is_string;

/**
 * PSR-7 UploadedFile creation utility for SAPI and worker environments.
 *
 * Provides a factory for creating {@see UploadedFileInterface} instances from PHP file arrays, enabling seamless
 * interoperability between PSR-7 compatible HTTP stacks and Yii2 applications.
 *
 * This class delegates the creation of uploaded files and file trees to the provided PSR-7 factories, supporting both
 * traditional SAPI and worker-based environments. It ensures strict type safety and immutability throughout the file
 * creation process, handling both single and multiple file uploads.
 *
 * The creation process includes.
 * - Building nested file trees for multiple file uploads.
 * - Creating PSR-7 UploadedFileInterface instances from file arrays and global variables.
 * - Exception-safe conversion and validation of file input structures.
 * - Immutable, type-safe file creation from PHP globals and arrays.
 * - Validating file specification arrays for required and optional keys.
 *
 * Key features.
 * - Automatic handling of single and multiple uploaded files.
 * - Designed for compatibility with SAPI and worker runtimes.
 * - Exception-safe file spec validation and conversion.
 * - Immutable, type-safe uploaded file creation from PHP globals.
 * - PSR-7 factory integration for uploaded files and streams.
 *
 * @phpstan-type TmpName array<array<mixed>|string>
 * @phpstan-type TmpSize array<array<mixed>|int>
 * @phpstan-type TmpError array<array<mixed>|int>
 * @phpstan-type Name array<array<mixed>|string|null>
 * @phpstan-type Type array<array<mixed>|string|null>
 * @phpstan-type UnknownFileInput array<mixed>
 * @phpstan-type FilesArray array<UploadedFileInterface|UnknownFileInput>
 * @phpstan-type FileSpec array{tmp_name: string, size: int, error: int, name?: string|null, type?: string|null}
 * @phpstan-type MultiFileSpec array{
 *   tmp_name: TmpName,
 *   size: TmpSize,
 *   error: TmpError,
 *   name?: Name|null,
 *   type?: Type|null,
 * }
 *
 * @copyright Copyright (C) 2025 Terabytesoftw.
 * @license https://opensource.org/license/bsd-3-clause BSD 3-Clause License.
 */
final class UploadedFileCreator
{
    /**
     * Optional keys for file specification.
     */
    private const OPTIONAL_KEYS = ['name', 'type'];

    /**
     * Required keys for file specification.
     */
    private const REQUIRED_KEYS = ['tmp_name', 'size', 'error'];

    /**
     * Creates a new instance of the {@see UploadedFileCreator} class.
     *
     * @param UploadedFileFactoryInterface $uploadedFileFactory Factory to create uploaded files.
     * @param StreamFactoryInterface $streamFactory Factory to create streams.
     */
    public function __construct(
        private readonly UploadedFileFactoryInterface $uploadedFileFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * Creates a UploadedFileInterface instance from a file specification array.
     *
     * Validates the input file specification and delegates stream and uploaded file creation to the configured PSR-7
     * factories.
     *
     * This method ensures strict type safety and immutability when converting PHP file arrays to PSR-7 uploaded file
     * objects.
     *
     * @param array $file File specification array containing required keys ('tmp_name', 'size', 'error') and optional
     * keys ('name', 'type').
     *
     * @return UploadedFileInterface PSR-7 UploadedFileInterface instance created from the file specification
     * array.
     *
     * @phpstan-param FileSpec $file
     *
     * Usage example:
     * ```php
     * $file = [
     *     'tmp_name' => '/path/to/temp/file',
     *     'size' => 12345,
     *     'error' => 0,
     *     'name' => 'example.txt',
     *     'type' => 'text/plain',
     * ];
     *
     * $uploadedFile = $creator->createFromArray($file);
     * ```
     */
    public function createFromArray(array $file): UploadedFileInterface
    {
        $this->validateFileSpec($file);

        try {
            $stream = $this->streamFactory->createStreamFromFile($file['tmp_name']);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                Message::FAILED_CREATE_STREAM_FROM_TMP_FILE->getMessage($file['tmp_name']),
            );
        }

        return $this->uploadedFileFactory->createUploadedFile(
            $stream,
            $file['size'],
            $file['error'],
            $file['name'] ?? null,
            $file['type'] ?? null,
        );
    }

    /**
     * Creates UploadedFileInterface instances from PHP global files array.
     *
     * Iterates over the provided files array and processes each entry using {@see processFileInput},
     *
     * This method enables seamless conversion of PHP global file structures to PSR-7 UploadedFileInterface objects,
     * supporting both single and multiple file uploads in SAPI and worker environments.
     *
     * @param array $files Array of uploaded file specifications or PSR-7 UploadedFileInterface instances.
     *
     * @return array Array of processed uploaded files as PSR-7 UploadedFileInterface instances or nested file trees as
     * appropriate.
     *
     * @phpstan-param FilesArray $files
     *
     * @phpstan-return FilesArray
     *
     * Usage example:
     * ```php
     * $files = $creator->createFromGlobals($_FILES);
     * ```
     */
    public function createFromGlobals(array $files = []): array
    {
        return array_map(
            $this->processFileInput(...),
            $files,
        );
    }

    /**
     * Builds a nested file tree from PHP file specification arrays for multiple file uploads.
     *
     * Iterates recursively over the provided file specification arrays ('tmp_name', 'size', 'error', 'name', 'type'),
     * constructing a tree of PSR-7 UploadedFileInterface instances or nested arrays for each file input.
     *
     * This method validates the structure and types of each input array, ensuring strict type safety and consistency
     * when handling both single and multiple file uploads. For array values, it recurses into subtrees; for scalar
     * values, it creates a single uploaded file instance using {@see createSingleFileFromArrays}.
     *
     * @param array $tmpNames Array of temporary file names or nested arrays for uploaded files.
     * @param array $sizes Array of file sizes or nested arrays matching the structure of $tmpNames.
     * @param array $errors Array of error codes or nested arrays matching the structure of $tmpNames.
     * @param array $names Array of file names or nested arrays matching the structure of $tmpNames.
     * @param array $types Array of file types or nested arrays matching the structure of $tmpNames.
     *
     * @throws InvalidArgumentException if one or more arguments are invalid, of incorrect type or format.
     *
     * @return array Nested array of PSR-7 UploadedFileInterface instances or file trees.
     *
     * @phpstan-param TmpName $tmpNames
     * @phpstan-param TmpSize $sizes
     * @phpstan-param TmpError $errors
     * @phpstan-param Name $names
     * @phpstan-param Type $types
     *
     * @phpstan-return FilesArray
     */
    private function buildFileTree(
        array $tmpNames,
        array $sizes,
        array $errors,
        array $names = [],
        array $types = [],
        int $depth = 0,
    ): array {
        if ($depth > 10) {
            throw new InvalidArgumentException(
                Message::MAXIMUM_NESTING_DEPTH_EXCEEDED->getMessage($depth),
            );
        }

        $tree = [];

        foreach ($tmpNames as $key => $tmpName) {
            if (is_array($tmpName)) {
                if (array_key_exists($key, $sizes) === false || is_array($sizes[$key]) === false) {
                    throw new InvalidArgumentException(
                        Message::MISMATCHED_ARRAY_STRUCTURE_SIZES->getMessage($key),
                    );
                }

                if (array_key_exists($key, $errors) === false || is_array($errors[$key]) === false) {
                    throw new InvalidArgumentException(
                        Message::MISMATCHED_ARRAY_STRUCTURE_ERRORS->getMessage($key),
                    );
                }

                /** @phpstan-var TmpName $subTmpNames */
                $subTmpNames = $tmpName;
                /** @phpstan-var TmpSize $subSizes */
                $subSizes = $sizes[$key];
                /** @phpstan-var TmpError $subErrors */
                $subErrors = $errors[$key];
                /** @phpstan-var Name $subNames */
                $subNames = array_key_exists($key, $names) && is_array($names[$key]) ? $names[$key] : [];
                /** @phpstan-var Type $subTypes */
                $subTypes = array_key_exists($key, $types) && is_array($types[$key]) ? $types[$key] : [];

                $tree[$key] = $this->buildFileTree(
                    $subTmpNames,
                    $subSizes,
                    $subErrors,
                    $subNames,
                    $subTypes,
                    $depth + 1,
                );
            } else {
                if (array_key_exists($key, $sizes) === false || is_int($sizes[$key]) === false) {
                    throw new InvalidArgumentException(
                        Message::SIZE_MUST_BE_INTEGER->getMessage($key),
                    );
                }

                if (array_key_exists($key, $errors) === false || is_int($errors[$key]) === false) {
                    throw new InvalidArgumentException(
                        Message::ERROR_MUST_BE_INTEGER->getMessage($key),
                    );
                }

                $tree[$key] = $this->createSingleFileFromArrays(
                    $tmpName,
                    $sizes[$key],
                    $errors[$key],
                    array_key_exists($key, $names) && is_string($names[$key]) ? $names[$key] : null,
                    array_key_exists($key, $types) && is_string($types[$key]) ? $types[$key] : null,
                );
            }
        }

        return $tree;
    }

    /**
     * Builds a nested tree of PSR-7 UploadedFileInterface instances for multiple file uploads.
     *
     * Validates the provided multi-file specification array and delegates the construction of the file tree to
     * {@see buildFileTree}, ensuring strict type safety and consistency for both single and multiple file uploads.
     *
     * This method is used to process PHP file arrays containing nested structures for multiple uploaded files,
     * returning a tree of PSR-7 UploadedFileInterface instances or nested arrays as appropriate.
     *
     * @param array $files Multi-file specification array containing 'tmp_name', 'size', 'error', and optional 'name',
     * 'type' keys.
     *
     * @return array Nested array of PSR-7 UploadedFileInterface instances or file trees.
     *
     * @phpstan-param MultiFileSpec $files
     *
     * @phpstan-return FilesArray
     *
     * Usage example:
     * ```php
     * $tree = $creator->createMultipleUploadedFiles($files);
     * ```
     */
    private function createMultipleUploadedFiles(array $files): array
    {
        $this->validateMultiFileSpec($files);

        return $this->buildFileTree(
            $files['tmp_name'],
            $files['size'],
            $files['error'],
            $files['name'] ?? [],
            $files['type'] ?? [],
        );
    }

    /**
     * Creates a PSR-7 UploadedFileInterface instance from single file specification arrays.
     *
     * Validates that the provided temporary file name is a string and delegates the creation of the uploaded file
     * to the configured PSR-7 factories.
     *
     * This method ensures strict type safety and immutability for single file uploads, supporting both SAPI and worker
     * environments.
     *
     * @param string $tmpName Temporary file name for the uploaded file.
     * @param int $size Size of the uploaded file in bytes.
     * @param int $error Error code associated with the file upload.
     * @param string|null $name Optional original file name as provided by the client.
     * @param string|null $type Optional media type of the uploaded file as provided by the client.
     *
     * @return UploadedFileInterface PSR-7 UploadedFileInterface instance created from the file specification.
     */
    private function createSingleFileFromArrays(
        string $tmpName,
        int $size,
        int $error,
        string|null $name = null,
        string|null $type = null,
    ): UploadedFileInterface {
        return $this->uploadedFileFactory->createUploadedFile(
            $this->streamFactory->createStreamFromFile($tmpName),
            $size,
            $error,
            $name,
            $type,
        );
    }

    /**
     * Determines whether the provided file specification array contains all required keys.
     *
     * Iterates over the required keys ('tmp_name', 'size', 'error') and checks for their presence in the input array.
     *
     * This method is used to validate file specification arrays before processing them as uploaded files, ensuring
     * strict type safety and consistency in the file creation workflow.
     *
     * @param array $file File specification array to validate for required keys.
     *
     * @return bool `true` if all required keys are present; `false` otherwise.
     *
     * @phpstan-param UnknownFileInput $file
     */
    private function hasRequiredKeys(array $file): bool
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (array_key_exists($key, $file) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determines whether the provided file specification array represents a multiple file upload.
     *
     * Checks if the 'tmp_name' key exists and its value is an array, indicating a multiple file upload structure as
     * produced by PHP for input fields with the 'multiple' attribute.
     *
     * This method is used to distinguish between single and multiple file upload specifications, ensuring correct
     * processing and conversion to PSR-7 UploadedFileInterface instances.
     *
     * @param array $file File specification array to check for multiple file upload structure.
     *
     * @return bool `true` if the file specification represents a multiple file upload; `false` otherwise.
     *
     * @phpstan-param UnknownFileInput $file
     */
    private function isMultipleFileUpload(array $file): bool
    {
        return array_key_exists('tmp_name', $file) && is_array($file['tmp_name']);
    }

    /**
     * Processes a file input and returns a PSR-7 UploadedFileInterface instance or a nested array of uploaded files.
     *
     * Determines the type of file input and delegate processing to the appropriate creation method.
     *
     * - If the input is already an {@see UploadedFileInterface} instance, it is returned as-is.
     * - If required keys are missing, the input is treated as a global files array and processed recursively.
     * - For multiple file uploads, the input is converted to a nested array of uploaded files. For single file
     *   specifications, a PSR-7 UploadedFileInterface instance is created.
     *
     * This method ensures strict type safety and immutability when converting PHP file arrays to PSR-7 uploaded file
     * objects, supporting both single and multiple file uploads in SAPI and worker environments.
     *
     * @param array|UploadedFileInterface $file File input to process, which may be a file specification array or an
     * uploaded file instance.
     *
     * @return array|UploadedFileInterface PSR-7 UploadedFileInterface instance or nested array of uploaded files.
     *
     * @phpstan-param UnknownFileInput|UploadedFileInterface $file
     *
     * @phpstan-return array<mixed>|UploadedFileInterface
     */
    private function processFileInput(array|UploadedFileInterface $file): array|UploadedFileInterface
    {
        if ($file instanceof UploadedFileInterface) {
            return $file;
        }

        if ($this->hasRequiredKeys($file) === false) {
            /** @var FilesArray $file */
            return $this->createFromGlobals($file);
        }

        if ($this->isMultipleFileUpload($file)) {
            /** @var MultiFileSpec $file */
            return $this->createMultipleUploadedFiles($file);
        }

        /** @var FileSpec $file */
        return $this->createFromArray($file);
    }

    /**
     * Validates a file specification array for required and optional keys.
     *
     * Ensures strict type safety and consistency by verifying the presence and types of required keys ('tmp_name',
     * 'size', 'error') and optional keys ('name', 'type') in the file specification array before processing it as an
     * uploaded file.
     *
     * @param array $file File specification array to validate for required and optional keys.
     *
     * @throws InvalidArgumentException if one or more arguments are invalid, of incorrect type or format.
     *
     * @phpstan-param UnknownFileInput $file
     */
    private function validateFileSpec(array $file): void
    {
        foreach (self::REQUIRED_KEYS as $requiredKey) {
            if (array_key_exists($requiredKey, $file) === false) {
                throw new InvalidArgumentException(
                    Message::MISSING_REQUIRED_KEY_IN_FILE_SPEC->getMessage($requiredKey, 'validateFileSpec()'),
                );
            }
        }

        if (isset($file['tmp_name']) === false || is_string($file['tmp_name']) === false) {
            throw new InvalidArgumentException(
                Message::TMP_NAME_MUST_BE_STRING->getMessage(),
            );
        }

        if (isset($file['size']) === false || is_int($file['size']) === false) {
            throw new InvalidArgumentException(
                Message::SIZE_MUST_BE_INTEGER->getMessage(),
            );
        }

        if (isset($file['error']) === false || is_int($file['error']) === false) {
            throw new InvalidArgumentException(
                Message::ERROR_MUST_BE_INTEGER->getMessage(),
            );
        }

        if (array_key_exists('name', $file) && $file['name'] !== null && is_string($file['name']) === false) {
            throw new InvalidArgumentException(
                Message::NAME_MUST_BE_STRING_OR_NULL->getMessage(),
            );
        }

        if (array_key_exists('type', $file) && $file['type'] !== null && is_string($file['type']) === false) {
            throw new InvalidArgumentException(
                Message::TYPE_MUST_BE_STRING_OR_NULL->getMessage(),
            );
        }
    }

    /**
     * Validates a multiple file specification array for required and optional keys.
     *
     * Ensures strict type safety and consistency by verifying that all required keys ('tmp_name', 'size', 'error')
     * are present and their values are arrays, as expected for multiple file uploads. Also checks that optional keys
     * ('name', 'type') are either arrays or null when present.
     *
     * @param array $files Multiple file specification array to validate for required and optional keys.
     *
     * @throws InvalidArgumentException if one or more arguments are invalid, missing, or of incorrect type or format.
     *
     * @phpstan-param UnknownFileInput $files
     */
    private function validateMultiFileSpec(array $files): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (array_key_exists($key, $files) === false || is_array($files[$key]) === false) {
                throw new InvalidArgumentException(
                    Message::MISSING_OR_INVALID_ARRAY_IN_MULTI_SPEC->getMessage($key, 'validateMultiFileSpec()'),
                );
            }
        }

        foreach (self::OPTIONAL_KEYS as $key) {
            if (array_key_exists($key, $files) && $files[$key] !== null && is_array($files[$key]) === false) {
                throw new InvalidArgumentException(
                    Message::INVALID_OPTIONAL_ARRAY_IN_MULTI_SPEC->getMessage($key, 'validateMultiFileSpec()'),
                );
            }
        }
    }
}
