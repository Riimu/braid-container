<?php

namespace Riimu\Braid\Container;

use Interop\Container\ContainerInterface;
use Interop\Container\Exception\ContainerException;
use Interop\Container\Exception\NotFoundException;

/**
 * Dependency Injection Container with various value strategies.
 *
 * @author Riikka Kalliomäki <riikka.kalliomaki@gmail.com>
 * @copyright Copyright (c) 2016, Riikka Kalliomäki
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class Container implements ContainerInterface
{
    /** Type for plain value entries */
    const TYPE_VALUE = 1;

    /** Type for entries that are values or invokables */
    const TYPE_STANDARD = 2;

    /** Type for entries that are blueprints for classes */
    const TYPE_BLUEPRINT = 3;

    /** @var ContainerInterface The delegated container */
    private $delegate;

    /**
     * The types for different container entries.
     * @var int[]
     */
    private $types;

    /**
     * The container entries
     * @var array
     */
    private $values;

    /**
     * Container constructor.
     * @param ContainerInterface|null $delegate Optional delegate container
     */
    public function __construct(ContainerInterface $delegate = null)
    {
        $this->delegate;
    }

    /**
     * Sets new container entries.
     *
     * A standard entry may either be a plain php value or an invokable that is
     * called with the container as a parameter to initialize the value for the
     * container. After the invokable has been called, the same value will be
     * returned for further calls.
     *
     * @param array $values Array of container entry id-value pairs
     * @throws ContainerException If duplicate keys were detected
     */
    public function set(array $values)
    {
        $this->setEntries($values, self::TYPE_STANDARD);
    }

    /**
     * Sets new container blueprint entries.
     *
     * A blueprint entry instructs the container how to create the instance for
     * the entry. The blueprint is an array that must at least have a key
     * 'class', which determines the class for the instance. Additional array
     * keys may indicate methods that are called for the class to set it up.
     * The value for these keys must be an array that contains the IDs of the
     * container entries that are passed as arguments to the method (an
     * identifier path may also be used).
     *
     * @param array $blueprints Array of container id-value pairs.
     * @throws ContainerException If duplicate keys were detected
     */
    public function setBlueprints(array $blueprints)
    {
        $this->setEntries($blueprints, self::TYPE_BLUEPRINT);
    }

    /**
     * Sets new container entries with given type.
     * @param array $entries The new container entries
     * @param int $type Type of the new container entries
     * @throws ContainerException If duplicate keys were detected
     */
    private function setEntries(array $entries, $type)
    {
        if (array_intersect_key($entries, $this->values)) {
            throw new Exception\ContainerException("Duplicate entry identifiers");
        }

        $this->values += $entries;
        $this->types += array_fill_keys(array_keys($entries), $type);
    }

    /**
     * Loads an container entry using an identifier path.
     *
     * The identifier path is a period separated string, which indicates the
     * entry identifier and the identifiers of further traversed entries. The
     * traversed entries may be additional containers, arrays or objects
     * with or without array access. For example, assuming the entry is an
     * array, the following two would be equivalent:
     *
     *   - `$container->get('config')['session']['name']`
     *   - `$container->load('config.session.name')`
     *
     * Note that if a delegate container has been set, the initial lookup is
     * performed on the delegate container.
     *
     * This method also allows providing a default value that is returned when
     * the provided path cannot be found within the container. If no default
     * value has been provided, an exception will be thrown instead.
     *
     * @param string $path The identifier path to load
     * @param mixed $default Optional default value if not found
     * @return mixed The value for the path
     * @throws ContainerException If the path contains non-traversable entries
     * @throws NotFoundException If any path entry is not found
     */
    public function load($path, $default = null)
    {
        $parts = explode('.', (string) $path);
        $container = $this->delegate ?: $this;

        try {
            $value = $container->get(array_shift($parts));

            foreach ($parts as $part) {
                $value = $this->loadKey($value, $part);
            }
        } catch (NotFoundException $exception) {
            if (func_num_args() === 1) {
                throw $exception;
            }

            $value = $default;
        }

        return $value;
    }

    /**
     * Loads an entry from the value based on the type of the value.
     * @param array|object $value The value to load from
     * @param string $key The key to load
     * @return mixed The loaded value
     * @throws ContainerException The value is not a supported type
     * @throws NotFoundException If the key is not found within the value
     */
    private function loadKey($value, $key)
    {
        if (is_array($value)) {
            if (array_key_exists($key, $value)) {
                return $value[$key];
            }
        } elseif ($value instanceof ContainerInterface) {
            if (!$value->has($key)) {
                return $value->get($key);
            }
        } elseif ($value instanceof \ArrayAccess) {
            if ($value->offsetExists($key)) {
                return $value->offsetGet($key);
            }
        } elseif (is_object($value)) {
            if (isset($value->$key)) {
                return $value->$key;
            }

            $values = get_object_vars($value);

            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
        } else {
            throw new Exception\ContainerException("Unexpected value encountered in identifier path key '$key'");
        }

        throw new Exception\NotFoundException("No entry was found for the identifier path key '$key'");
    }

    /**
     * Returns the entry with the given identifier.
     * @param string $id The identifier to find
     * @return mixed The value for the entry
     * @throws NotFoundException If no entry exists for the key
     */
    public function get($id)
    {
        $id = (string) $id;

        if (!isset($this->types[$id])) {
            throw new Exception\NotFoundException("No entry was found for the identifier '$id'");
        }

        $value = $this->values[$id];

        if ($this->types[$id] === self::TYPE_STANDARD) {
            if (is_object($value) && method_exists($value, '__invoke')) {
                $value = $this->values[$id] = call_user_func($value, $this->delegate ?: $this);
            }

            $this->types[$id] = self::TYPE_VALUE;
        } elseif ($this->types[$id] === self::TYPE_BLUEPRINT) {
            $value = $this->values[$id] = $this->createFromBlueprint($value);
            $this->types[$id] = self::TYPE_VALUE;
        }

        return $value;
    }

    /**
     * Creates the entry value from the blueprint array.
     * @param array $blueprint The blueprint for the instance
     * @return object The instance created from the blueprint
     */
    private function createFromBlueprint($blueprint)
    {
        if (isset($blueprint['__construct'])) {
            $reflection = new \ReflectionClass($blueprint['class']);
            $instance = $reflection->newInstanceArgs(
                array_map([$this, 'load'], $blueprint['__construct'])
            );
        } else {
            $instance = new $blueprint['class'];
        }

        $methods = array_diff_key($blueprint, array_flip(['class', '__construct']));

        foreach ($methods as $method => $arguments) {
            call_user_func_array([$instance, $method], array_map([$this, 'load'], $arguments));
        }

        return $instance;
    }

    /**
     * Tells if the container has an entry with the given identifier.
     * @param string $id The identifier to find
     * @return bool True if the entry exists, false if not
     */
    public function has($id)
    {
        return isset($this->types[(string) $id]);
    }
}
