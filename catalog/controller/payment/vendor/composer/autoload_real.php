<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInitcaf3173d44ada2237e71147ec5445757
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInitcaf3173d44ada2237e71147ec5445757', 'loadClassLoader'), true, true);
        self::$loader = $loader = new \Composer\Autoload\ClassLoader(\dirname(__DIR__));
        spl_autoload_unregister(array('ComposerAutoloaderInitcaf3173d44ada2237e71147ec5445757', 'loadClassLoader'));

        require __DIR__ . '/autoload_static.php';
        call_user_func(\Composer\Autoload\ComposerStaticInitcaf3173d44ada2237e71147ec5445757::getInitializer($loader));

        $loader->register(true);

        return $loader;
    }
}
