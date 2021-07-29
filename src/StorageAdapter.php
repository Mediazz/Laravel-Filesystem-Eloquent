<?php


namespace Mediazz\Storage;

use Carbon\Carbon;
use http\QueryString;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\FileExistsException;
use League\Flysystem\FileNotFoundException;
use Mediazz\Storage\Exceptions\StorageException;
use Mediazz\Storage\Exceptions\StorageFileAlreadyExistsException;
use Mediazz\Storage\Exceptions\StorageFileNotFoundException;
use Mediazz\Storage\Exceptions\StorageFolderNotEmptyException;
use Mediazz\Storage\Exceptions\StorageStreamException;

// TODO: deprecated
function str_contains(string $haystack, string $needle)
{
    return strpos($haystack, $needle) !== false;
}

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
     * If set to true it is possible to pass ../ within an Path
     * @var bool
     */
    protected $allowFolderUp = false;

    /**
     * StorageAdapterBase constructor.
     */
    public function __construct()
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

    /**
     * @param string $subFolder
     * @return StorageAdapter
     */
    public function setSubFolder(string $subFolder): self
    {
        $this->subFolder = $this->cleanPath($subFolder);

        return $this;
    }

    /**
     * @param string $subFolder
     * @return StorageAdapter
     * @throws StorageException
     */
    public function appendSubFolder(string $subFolder): self
    {
        $subFolder = $this->cleanPath($subFolder);

        if ($this->subFolder === '') {
            //set as subfolder if no subfolder has been set
            $this->setSubFolder($subFolder);
        } else if ($subFolder === '') {
            return $this;
        } else {
            $this->subFolder .= '/' . $subFolder;
        }

        return $this;
    }

    /**
     * @param StorageAdapter $toLocation
     * @param string $fileName
     * @param string|null $newFilename
     * @throws StorageException
     * @throws StorageFileAlreadyExistsException
     * @throws StorageFileNotFoundException
     */
    public function moveTo(StorageAdapter $toLocation, string $fileName, ?string $newFilename = null): void
    {
        $fileName = $this->cleanPath($fileName);
        $newFilename = $this->cleanPath($newFilename);
        try {
            $this->fileSystem->move($this->getFullPath($fileName), $toLocation->getFullPath($newFilename ?? $fileName));
        } catch (FileNotFoundException $exception) {
            // File not available - nothing to move
            throw new StorageFileNotFoundException($exception->getMessage());
        } catch (FileExistsException $exception) {
            // File already exists at path
            throw new StorageFileAlreadyExistsException($exception->getMessage());
        }
    }

    /**
     * @param StorageAdapter $adapter
     * @param string $fileName
     * @param string|null $newFileName
     * @throws StorageException
     * @throws StorageFileAlreadyExistsException
     * @throws StorageFileNotFoundException
     */
    public function copyTo(StorageAdapter $adapter, string $fileName, ?string $newFileName = null): void
    {
        try {
            $this->fileSystem->copy($this->getFullPath($fileName), $adapter->getFullPath($newFileName ?? $fileName));
        } catch (FileNotFoundException $exception) {
            // File not available - nothing to move
            throw new StorageFileNotFoundException($exception->getMessage());
        } catch (FileExistsException $exception) {
            // File already exists at path
            throw new StorageFileAlreadyExistsException($exception->getMessage());
        }
    }

    /**
     * @param string $folder
     * @return Collection
     * @throws StorageException
     */
    public function listFiles(string $folder = ''): Collection
    {
        return collect($this->fileSystem->files($this->getFolderPath($folder)));
    }

    /**
     * Return a list of all files within all directories of the current directory
     * @param string $folder
     * @return Collection
     * @throws StorageException
     */
    public function listAllFiles(string $folder = ''): Collection
    {
        return collect($this->fileSystem->allFiles($this->getFolderPath($folder)));
    }

    /**
     * * Returns a list of all the folders within the current directory
     * @param string $folder
     * @return Collection
     * @throws StorageException
     */
    public function listFolders(string $folder = ''): Collection
    {
        return collect($this->fileSystem->directories($this->getFolderPath($folder)));
    }

    /**
     * Returns Folders with all the subfolders
     * @param string $folder
     * @return Collection
     * @throws StorageException
     */
    public function listAllFolders(string $folder = ''): Collection
    {
        return collect($this->fileSystem->allDirectories($this->getFolderPath($folder)));
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
     * @param string $fileName
     * @param $content
     * @param array $options
     * @throws StorageException
     */
    public function put(string $fileName, $content, array $options = []): void
    {
        // TODO: Check if this already exists
        $this->fileSystem->put($this->getFullPath($fileName), $content, [
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
     * @param string $file
     * @param string $newFilename
     * @throws StorageException
     */
    public function rename(string $file, string $newFilename): void
    {
        $this->moveTo($this, $file, $newFilename);
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
     * @param string $newFolderName
     * @return StorageAdapter
     * @throws StorageException
     */
    public function renameFolder(string $newFolderName): StorageAdapter
    {
        $moveTo = clone $this;
        $moveTo->setSubFolder($newFolderName);

        $this->moveToFolder($moveTo);

        return $moveTo;
    }

    /**
     * @param StorageAdapter $moveTo
     * @throws StorageException
     */
    public function moveToFolder(StorageAdapter $moveTo)
    {
        if (!$moveTo->isFolderEmpty()) {
            throw new StorageFolderNotEmptyException('Cannot move because destination exists.');
        }

        // Move all files from the current folder to the new folder (updates path)
        $this->listAllFiles()->each(function (string $filePath) use ($moveTo) {
            $cleanFile = str_replace($this->getFolderPath(), '', $filePath);

            $this->moveTo($moveTo, $cleanFile);
        });

        // Move all folders from the current folder to the new folder
        // We create new folders and delete the old ones
        // This fixes the issue with moving empty folders
        //https://github.com/thephpleague/flysystem-aws-s3-v3/issues/128
        $this->listAllFolders()->each(function (string $folderPath) use ($moveTo) {
            $updatedPath = str_replace($this->getFolderPath(), $moveTo->getFolderPath(), $folderPath);
            $cleanPath = str_replace($this->getFolderPath() . '/', '', $folderPath);

            $this->fileSystem->makeDirectory($updatedPath);
            $storage = clone $this;
            $storage->appendSubFolder($cleanPath);
            $storage->deleteFolder(true);
        });

        //Create the new "moveTo" folder
        $this->fileSystem->makeDirectory($moveTo->getFolderPath());

        //Delete the old "moveFrom" folder
        $this->deleteFolder(true);
    }

    /**
     * @param string $path
     * @return string
     */
    private function filenameFromPath(string $path): string
    {
        if (str_contains($path, '/')) {
            $parts = explode('/', $path);
            $filename = end($parts);
        } else {
            $filename = $path;
        }

        return $filename;
    }

    /**
     * Copy the current folder into the new Folder
     * @param StorageAdapter $copyTo
     * @throws StorageException
     */
    public function copyToFolder(StorageAdapter $copyTo)
    {
        // Copy all files from the current folder to the new folder
        $this->listAllFiles()->each(function (string $filePath) use ($copyTo) {
            $cleanFile = str_replace($this->getFolderPath(), '', $filePath);

            $this->copyTo($copyTo, $cleanFile);
        });

        // Copy all folders from the current folder by creating new ones
        // This fixes the issue with copying empty folders
        // https://github.com/thephpleague/flysystem-aws-s3-v3/issues/128
        $this->listAllFolders()->each(function (string $folderPath) use ($copyTo) {
            $updatedPath = str_replace($this->getFolderPath(), $copyTo->getFolderPath(), $folderPath);

            $this->fileSystem->makeDirectory($updatedPath);
        });

        // Create the "copyFrom" folder in the destination
        $this->fileSystem->makeDirectory($copyTo->getFolderPath());
    }

    /**
     * @param string $folder
     * @return bool
     * @throws StorageException
     */
    public function isFolderEmpty(string $folder = ''): bool
    {
        return $this->listFiles($folder)->isEmpty() && $this->listFolders()->isEmpty();
    }

    /**
     * @param bool $force
     * @param string|null $folder
     * @throws StorageException
     * @throws StorageFolderNotEmptyException
     */
    public function deleteFolder(bool $force = false, string $folder = ''): void
    {
        if (!$force) {
            // Throw exception if the folder is not empty
            if (!$this->isFolderEmpty($folder)) {
                throw new StorageFolderNotEmptyException();
            }
        }

        $this->fileSystem->deleteDirectory($this->getFolderPath($folder));
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
     * @param string $file
     * @param bool|bool $prependPath
     * @return mixed
     */
    public function getMetadata(string $file, bool $prependPath = false): Collection
    {
        $file = $this->cleanPath($file);

        return collect([
            'name' => $prependPath ? $file : $this->filenameFromPath($file),
            'path' => $prependPath ? $this->getFullPath($file) : $file,
            'size' => $this->getSize($file, $prependPath),
            'modified' => $this->lastModified($file, $prependPath),
        ]);
    }

    /**
     * @param string $folder
     * @param bool|bool $prependPath
     * @return mixed
     */
    public function getFolderMetadata(string $folder, bool $prependPath = false): Collection
    {
        $folder = $this->cleanPath($folder);

        return collect([
            'name' => $prependPath ? $folder : $this->filenameFromPath($folder),
            'path' => $prependPath ? $this->getFullPath($folder) : $folder,
        ]);
    }

    /**
     * @param string $file
     * @param bool $prependPath
     * @return int
     * @throws StorageException
     */
    public function getSize(string $file, bool $prependPath = false): int
    {
        $file = $this->cleanPath($file);
        $file = $prependPath ? $this->getFullPath($file) : $file;

        return $this->fileSystem->size($file);
    }

    /**
     * @param string $file
     * @param bool $prependPath
     * @return Carbon
     * @throws StorageException
     */
    public function lastModified(string $file, bool $prependPath = false): Carbon
    {
        $file = $this->cleanPath($file);

        $file = $prependPath ? $this->getFullPath($file) : $file;

        return new Carbon($this->fileSystem->lastModified($file));
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
     * @param string $folder
     * @return string
     * @throws StorageException
     */
    public function getFolderPath(string $folder = ''): string
    {
        $folder = $this->cleanPath($folder);

        $this->validateBasePath();
        $fullPath = static::BASE_PATH ?? '';

        if ($this->subFolder !== '') {
            $fullPath .= '/' . $this->subFolder;
        }

        if ($folder !== '') {
            $fullPath .= '/' . $folder;
        }

        return $fullPath;
    }

    /**
     * Returns the full path based on the set subFolder and the provided Filename
     * @param string $file
     * @return string
     * @throws StorageException
     */
    public function getFullPath(string $file = ''): string
    {
        $file = $this->cleanPath($file);

        $fullPath = $this->getFolderPath();

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
            throw new StorageException('Working in root is prohibited. Please set BASE_PATH.');
        }
    }

    /**
     * @return Filesystem
     */
    public function getFilesystem(): Filesystem
    {
        return $this->fileSystem;
    }

    private function cleanPath(?string $path): ?string
    {
        if (is_null($path)) {
            return null;
        }

        if (!$this->allowFolderUp) {
            $path = str_replace('../', '', $path);
            $path = str_replace('..', '', $path);
        }

        return $path;
    }
}
