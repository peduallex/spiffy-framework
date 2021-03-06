<?php

namespace Spiffy\Framework;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\Finder;

abstract class AbstractPackage implements ApplicationPackage
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $namespace;

    /**
     * @var string
     */
    private $path;

    /**
     * {@inheritDoc}
     */
    public function bootstrap(Application $app)
    {
    }

    /**
     * @return bool|void
     */
    public function isAutoloadServicesEnabled()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function bootstrapConsole(ConsoleApplication $console)
    {
        $consoleDir = realpath($this->getPath() . '/src/Console');
        if (!$consoleDir) {
            return;
        }

        $finder = new Finder();
        $finder
            ->files()
            ->ignoreUnreadableDirs()
            ->name('*Command.php')
            ->in($consoleDir);

        foreach ($finder as $file) {
            $classes = get_declared_classes();
            include_once $file;
            $newClasses = get_declared_classes();

            foreach (array_diff($newClasses, $classes) as $className) {
                $refl = new \ReflectionClass($className);
                if ($refl->isAbstract()) {
                    continue;
                }
                $command = $refl->newInstance();
                if (!$command instanceof Command) {
                    continue;
                }
                $console->add(new $command);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    final public function getName()
    {
        if ($this->name) {
            return $this->name;
        }

        $replace = function ($match) {
            return $match[1] . '-' . $match[2];
        };

        $name = preg_replace('@Package$@', '', $this->getNamespace());
        $name = str_replace('\\', '.', $name);
        $name = preg_replace_callback('@([a-z])([A-Z])@', $replace, $name);
        $name = strtolower($name);

        if (strstr($name, '.')) {
            $this->name = substr($name, strpos($name, '.') + 1);
        } else {
            $this->name = $name;
        }

        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    final public function getNamespace()
    {
        if ($this->namespace) {
            return $this->namespace;
        }
        $class = get_class($this);
        $this->namespace = substr($class, 0, strrpos($class, '\\'));

        return $this->namespace;
    }

    /**
     * {@inheritDoc}
     */
    public function getPath()
    {
        if ($this->path) {
            return $this->path;
        }

        $refl = new \ReflectionObject($this);
        $this->path = realpath(dirname($refl->getFileName()) . '/..');
        return $this->path;
    }

    /**
     * {@inheritDoc}
     */
    public function getConfig()
    {
        $path = $this->getPath();
        if (!file_exists($path . '/config/package.php')) {
            return [];
        }
        return include $path . '/config/package.php';
    }

    /**
     * {@inheritDoc}
     */
    public function getRoutes()
    {
        $path = $this->getPath();
        if (!file_exists($path . '/config/routes.php')) {
            return [];
        }
        return include $path . '/config/routes.php';
    }

    /**
     * {@inheritDoc}
     */
    public function getServices()
    {
        $path = $this->getPath();
        if (!file_exists($path . '/config/services.php')) {
            return [];
        }
        return include $path . '/config/services.php';
    }
}
