<?php


namespace Mediazz\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Mediazz\Storage\Exceptions\StorageException;
use Mediazz\Storage\Exceptions\StorageFileAlreadyExistsException;
use Mediazz\Storage\Exceptions\StorageFileNotFoundException;
use Mediazz\Storage\Exceptions\StorageStreamException;

/**
 * https://laravel.com/docs/5.7/filesystem
 * Build as Adapter upon "league/flysystem-aws-s3-v3": "1.0"
 * Class StorageAdapter
 * @package Mediazz\Storage;
 */
abstract class StorageAdapter
{

    /**
     * Must be set to be working
     * @var string Defines the BasePath of this Storage
     */
    public const BASE_PATH = null;

    /**
     * Defines the Connection which is defined in config/filesystems.php
     * cdn should/should be on another have cdn
     * @var string
     */
    public const CONNECTION = null;

    /**
     * Sometimes one might want to store something temporary
     * @var string
     */
    protected $tempFolder = '';

    /**
     * The subfolder used in the basePath
     * May be used e.g. when a user has his own folder in e.g. the invoice content
     * @var string
     */
    protected $subFolder = '';

    /**
     * @var Filesystem
     */
    protected $fileSystem;

    /**
     * If set to true it is possible to work in the root of the disk.
     * No baseFolder needed
     * @var bool
     */
    protected $allowWorkingInRoot = false;

    /**
     * StorageAdapterBase constructor.
     */
    private function __construct()
    {
        $this->fileSystem = Storage::disk(static::CONNECTION);
    }

    /**
     * Create a new instance of the Storage Adapter
     * @return StorageAdapter
     */
    public static function init(): self
    {
        return new static();
    }

    // TODO: Old modified returns Carbon date

    /**
     * @param string $subFolder
     * @return StorageAdapter
     */
    public function setSubFolder(string $subFolder): self
    {
        $this->subFolder = $subFolder;

        return $this;
    }

    /**
     * Return a list of the files within the current directory
     * @return Collection
     * @throws StorageException
     */
    public function listFiles(): Collection
    {
        return collect($this->fileSystem->files($this->getFullPath()));
    }

    /**
     * Return a list of all files within all directories of the current directory
     * @return Collection
     * @throws StorageException
     */
    public function listAllFiles(): Collection
    {
        return collect($this->fileSystem->allFiles($this->getFullPath()));
    }

    /**
     * Returns a list of all the folders within the current directory
     * @return Collection
     * @throws StorageException
     */
    public function listFolders(): Collection
    {
        return collect($this->fileSystem->directories($this->getFullPath()));
    }

    /**
     * Returns Folders with all the subfolders
     * @return Collection
     * @throws StorageException
     */
    public function listAllFolders(): Collection
    {
        return collect($this->fileSystem->allDirectories($this->getFullPath()));
    }

    /**
     * Return the content of a file
     * @param string $file
     * @return string
     * @throws StorageException
     * @throws StorageFileNotFoundException
     */
    public function get(string $file): string
    {
        try {
            return $this->fileSystem->get($this->getFullPath($file));
        } catch (\Illuminate\Contracts\Filesystem\FileNotFoundException $e) {
            throw new StorageFileNotFoundException($this->getFullPath($file) . ' does not exist');
        }
    }

    /**
     * Put the content of a file onto the disc
     * @param string $file
     * @param $content
     * @param array $options
     * @throws StorageException
     */
    public function put(string $file, $content, array $options = []): void
    {
        $this->fileSystem->put($this->getFullPath($file), $content, [
            'visibility' => $options['visibility'] ?? Filesystem::VISIBILITY_PRIVATE,
            // TODO: mime, header, filesize, visibiltiy
        ]);
    }

    /**
     * Checks if the file exitsts
     * @param string $file
     * @return bool
     * @throws StorageException
     */
    public function exists(string $file): bool
    {
        return $this->fileSystem->exists($this->getFullPath($file));
    }

    /**
     * Delete a File
     * @param string $file
     * @throws StorageException
     */
    public function delete(string $file): void
    {
        $this->fileSystem->delete($this->getFullPath($file));
    }

    /**
     * Create a new Folder in the given base/subdirectory
     * @param string $folder
     * @throws StorageException
     */
    public function makeFolder(string $folder): void
    {
        $this->fileSystem->makeDirectory($this->getFullPath($folder));
    }

    /**
     * Delete a Folder
     * // TODO: Maybe a check if the folder is empty
     * @throws StorageException
     */
    public function deleteFolder(): void
    {
        $this->fileSystem->deleteDirectory($this->getFullPath());
    }

    /**
     * Return the visibility of a file
     * @param string $file
     * @return string private or public
     * @throws StorageException
     */
    public function getVisibility(string $file): string
    {
        return $this->fileSystem->getVisibility($this->getFullPath($file));
    }

    /**
     * Returns the Size of a file in TODO
     * @param string $file
     * @return int
     * @throws StorageException
     */
    public function getSize(string $file): int
    {
        return $this->fileSystem->size($this->getFullPath($file));
    }

    /**
     * @param string $file
     * @param $resource
     * @param array $options
     * @throws StorageException
     * @throws StorageFileAlreadyExistsException
     */
    public function writeStream(string $file, $resource, array $options = []): void
    {
        try {
            $this->fileSystem->writeStream($this->getFullPath($file), $resource, [
                'visibility' => $options['visibility'] ?? Filesystem::VISIBILITY_PRIVATE,
            ]);
        } catch (\Illuminate\Contracts\Filesystem\FileExistsException $exception) {
            throw new StorageFileAlreadyExistsException($this->getFullPath($file) . ' already exists');
        }

    }

    /**
     * @param string $file
     * @return resource|null
     * @throws StorageException
     * @throws StorageFileNotFoundException
     */
    public function readStream(string $file)
    {
        try {
            $stream = $this->fileSystem->readStream($this->getFullPath($file));

            if (is_null($stream)) {
                throw new StorageStreamException('Unable to open stream: ' . $this->getFullPath($file));
            }

            return $stream;

        } catch (\Illuminate\Contracts\Filesystem\FileNotFoundException $exception) {
            throw new StorageFileNotFoundException($this->getFullPath($file) . ' does not exist');
        }
    }

    /**
     * Returns the full path based on the set subFolder and the provided Filename
     * @param string $file
     * @return string
     * @throws StorageException
     */
    public function getFullPath(string $file = ''): string
    {
        $this->validateBasePath();
        $fullPath = static::BASE_PATH ?? '';

        if ($this->subFolder !== '') {
            $fullPath .= '/' . $this->subFolder;
        }

        if ($file !== '') {
            $fullPath .= '/' . $file;
        }

        return $fullPath;
    }

    /**
     * @throws StorageException
     */
    private function validateBasePath(): void
    {
        if ($this->allowWorkingInRoot) {
            return;
        }

        if (is_null(static::BASE_PATH) || static::BASE_PATH === '' || static::BASE_PATH === '/') {
            throw new StorageException('Working in toot is prohibited');
        }
    }

    /**
     * @return Filesystem
     */
    public function getFilesystem(): Filesystem
    {
        return $this->fileSystem;
    }
}
