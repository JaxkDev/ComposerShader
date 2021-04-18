# ComposerShader

#### README for v1.0

---

## Important Note:
This is not perfect, nor will it ever be,
with several checks for common uses of certain functions and namespacing we can attempt to shade these calls,
but a package may do some dynamic requiring/defining and break at runtime.

You can however fork the library/package you want and modify if possible the way it does the dynamic calls so it can be shaded.

## Why shade ?
As more advanced projects and plugins become readily available through github and poggit the chance of a namespace collision increases exponentially.

For example take the following structure

- plugin A (`JaxkDev\TestPlugin`)
  - Promise v1.0 (`React\Promise`)


- plugin B (`AnotherPersons\TestPlugin`)
  - Promise v2.0 (`React\Promise`)

Unshaded these plugins would collide, as you can see they both require React\Promise but two different versions.
So not only do they have conflicts in namespace they also differ in behaviour and cannot be shared between them.

And that's where shading comes in, like poggit's virion scheme all the composer dependencies for a plugin gets put into a unique namespace covered by the plugins main class namespace,

In this case plugin A's dependency would be shaded to `JaxkDev\TestPlugin\vendor12345678\React\Promise`

and plugin B's shaded to `AnotherPerson\TestPlugin\vendor87654321\React\Promise`

Now each plugin has its own version of the same library/package so the plugins are using exactly what they expect and behaviour is constant because
as you can see the namespaces no longer collide.

## Requirements
- PHP >= 8.0
- PHP extension yaml
- composer dependencies pre-installed (run `composer install`)
- plugin source code

## Usage & Docs

The script [shade.php](shade.php) should be run via CLI (outside of pmmp) in the directory of the plugin.

Usage: `php shade.php -p SHADE_PREFIX`

`SHADE_PREFIX` Optional, 4+ chars (a-Z, 0-9)

Examples:
`php shade.php somePrefixHere` shades to `YourPlugin\NameSpace\somePrefixHere\`

#### Usage in the plugin:

By default, it's more than likely shade.php will show:
`Plugin source does not require 'COMPOSER_AUTOLOAD' but XX autoload files have been found.`

This is because your plugin does not call: `require_once(\YourPlugin\NameSpace\COMPOSER_AUTOLOAD);`

If your plugin uses the dependencies on the main thread you should call this in the main class.
eg

*Main.php*
```php
<?php
/*
 * License and notes here if applicable.
 */

namespace YourPlugin\NameSpace;

/** @noinspection PhpUndefinedConstantInspection */
require_once(\YourPlugin\NameSpace\COMPOSER_AUTOLOAD);

use React\Promise;  //Composer libs can be use'd anywhere in your plugin
                    //see notes below for threading.

class Main extends PluginBase{

}
```

If you are using the composer libraries/packages in another Thread you must call the `require_once(\YourPlugin\NameSpace\COMPOSER_AUTOLOAD);` inside the thread.

Note the constant will only be available if the thread was started with `PTHREADS_INHERIT_CONSTANTS`.

To reduce possibility of duplicate constant definitions It's suggested to start threads with `PTHREADS_INHERIT_NONE` and pass the constant as a variable to the thread constructor.

*PluginsThread.php*
```php
<?php

namespace YourPlugin\NameSpace;

class PluginsThread extends Thread{

    private $composerPath;

    public function __construct(string $composerPath, ...){
        $this->composerPath = $composerPath;
        ...
        //Dont do anything here that references composer libs.
    }

    public function run(){
        require_once($this->composerPath);
        //Do the stuff that references the libs here.
    }
}
```

*Main.php*
```php
//Somewhere in your Main class where appropriate
$thread = new PluginsThread(\YourPlugin\NameSpace\COMPOSER_AUTOLOAD);
$thread->start(PTHREADS_INHERIT_NONE);
```