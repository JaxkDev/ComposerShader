# ComposerShader

#### README for v0.2.0-dev

---

## Important Note:
This is not perfect, nor will it ever be,
with several checks for common uses of certain functions and namespacing we can attempt to shade these calls,
but a package may do some dynamic requiring/defining and break at runtime.

You can however fork the library/package you want and modify if possible the way it does the dynamic calls so it can be shaded.

## When not to shade:
Shading composer libs should only be done for internal plugin usage only, if you expose an API method f.e `$plugin->getSomething(string $something): Promise{}` this returns a Promise but will reference your shaded library, external plugins will have no guarenteed path for that class unless you always shade to the same place.

In simple it's not a solution for when you expose methods that include shaded usages.

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

Usage: `php shade.php SHADE_PREFIX`

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
<?php /** @noinspection ALL */
/*
 * License and notes here if applicable.
 */

namespace YourPlugin\NameSpace;

//Your IDE won't see this constant declared. so its ok to ignore the warning.
/** @noinspection PhpUndefinedConstantInspection */
require_once(\YourPlugin\NameSpace\COMPOSER_AUTOLOAD);

use React\Promise;  //Composer libs can be use'd anywhere in your plugin
                    //see notes below for threading.

class Main extends PluginBase{

}
```

If you are using the composer libraries/packages in another Thread you must call the `require_once(\YourPlugin\NameSpace\COMPOSER_AUTOLOAD);` inside the thread.

Note the constant will only be available if the thread was started with `PTHREADS_INHERIT_CONSTANTS`.

To reduce possibility of duplicate constant definitions It's suggested to start threads with `PTHREADS_INHERIT_NONE` and
pass the constant/path through the constructor.

*PluginsThread.php*
```php
<?php  /** @noinspection ALL */

namespace YourPlugin\NameSpace;

class PluginsThread extends Thread{

    private $composerPath;

    public function __construct(string $composerPath, ...){
        $this->composerPath = $composerPath;
        //...
        //Dont reference any composer libs here.
    }

    public function run(){
        /** @noinspection PhpUndefinedConstantInspection */
        require_once($this->composerPath);
        //...
        //Composer libs are now available for use/reference from here onwards.
    }
}
```

*Main.php*
```php
<?php /** @noinspection ALL */
//Somewhere in your Main class where appropriate
/** @noinspection PhpUndefinedConstantInspection */
$thread = new PluginsThread(\YourPlugin\NameSpace\COMPOSER_AUTOLOAD);
$thread->start(PTHREADS_INHERIT_NONE);
```

`\YourPlugin\NameSpace\COMPOSER_AUTOLOAD` Is the namespace to the plugins main file.

eg *plugin.yml*
```yaml
main: Test\NameSpace\Main
# so here your namespace is \Test\NameSpace\COMPOSER_AUTOLOAD

# if main was: Hello\Another\NameSpace\ButLonger\MainClass
# It would be \Hello\Another\NameSpace\ButLonger\COMPOSER_AUTOLOAD
```
