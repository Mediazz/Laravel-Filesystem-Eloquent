# Use Laravel Filesystem like Eloquents

## About

Adapter to use Storage Connections like Laravel Eloquents

## Install
```bash
composer require mediazz/laravel-filesystem-adapter
```

## Usage

The `$connection` is defined in the Laravel `config/filesystems.php` File. 

Minimal Setup. Just set the `CONNECTION` and the `BASE_PATH` you want to use. A sub-folder, for e.g. user sub-folder, can be set via `->setSubFolder('my-subfolder');` 

```$php
use Mediazz\Storage\StorageAdapter;

class ExampleStorage extends StorageAdapter
{
    public const CONNECTION = 'cdn';
    public const BASE_PATH = 'documents';
}

```

May be used as: 

`ExampleStorage::init()->put('hello-world.txt', 'Hello World!');`  
Writes the File to `/documents/hello-world.txt`
  
`ExampleStorage::init()->get('hello-world.txt');`    
Reads `Hello World!`

### License

This project is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
