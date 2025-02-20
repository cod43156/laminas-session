<?php

namespace Laminas\Session\Storage;

use ArrayIterator;
use ArrayObject;
use IteratorAggregate;
use Laminas\Session\Exception;
use ReturnTypeWillChange;

use function array_flip;
use function array_key_exists;
use function array_keys;
use function array_replace_recursive;
use function count;
use function is_array;
use function is_object;
use function microtime;
use function serialize;
use function sprintf;
use function unserialize;

/**
 * Session storage in $_SESSION
 *
 * Replaces the $_SESSION superglobal with an ArrayObject that allows for
 * property access, metadata storage, locking, and immutability.
 *
 * @see ReturnTypeWillChange
 *
 * @template TKey of array-key
 * @template TValue
 * @template-implements IteratorAggregate<TKey, TValue>
 * @template-implements StorageInterface<TKey, TValue>
 */
abstract class AbstractSessionArrayStorage implements
    IteratorAggregate,
    StorageInterface,
    StorageInitializationInterface
{
    /**
     * Constructor
     *
     * @param array|null $input
     */
    public function __construct($input = null)
    {
        // this is here for B.C.
        $this->init($input);
    }

    /**
     * Initialize Storage
     *
     * @param  array $input
     * @return void
     */
    public function init($input = null)
    {
        if ((null === $input) && isset($_SESSION)) {
            $input = $_SESSION;
            if (is_object($input) && ! $_SESSION instanceof ArrayObject) {
                $input = (array) $input;
            }
        } elseif (null === $input) {
            $input = [];
        }
        $_SESSION = $input;
        $this->setRequestAccessTime(microtime(true));
    }

    /**
     * Get Offset
     *
     * @return mixed
     */
    public function __get(mixed $key)
    {
        return $this->offsetGet($key);
    }

    /**
     * Set Offset
     *
     * @return void
     */
    public function __set(mixed $key, mixed $value)
    {
        $this->offsetSet($key, $value);
    }

    /**
     * Isset Offset
     *
     * @return bool
     */
    public function __isset(mixed $key)
    {
        return $this->offsetExists($key);
    }

    /**
     * Unset Offset
     *
     * @return void
     */
    public function __unset(mixed $key)
    {
        $this->offsetUnset($key);
    }

    /**
     * Destructor
     *
     * @return void
     */
    public function __destruct()
    {
    }

    /**
     * Offset Exists
     *
     * @return bool
     */
    #[ReturnTypeWillChange]
    public function offsetExists(mixed $key)
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Offset Get
     *
     * @return mixed
     */
    #[ReturnTypeWillChange]
    public function offsetGet(mixed $key)
    {
        return $_SESSION[$key] ?? null;
    }

    /**
     * Offset Set
     *
     * @return void
     */
    #[ReturnTypeWillChange]
    public function offsetSet(mixed $offset, mixed $value)
    {
        $_SESSION[$offset] = $value;
    }

    /**
     * Offset Unset
     *
     * @return void
     */
    #[ReturnTypeWillChange]
    public function offsetUnset(mixed $offset)
    {
        unset($_SESSION[$offset]);
    }

    /**
     * Count
     *
     * @return int
     */
    #[ReturnTypeWillChange]
    public function count()
    {
        return count($_SESSION);
    }

    /**
     * Seralize
     *
     * @return string
     */
    public function serialize()
    {
        return serialize($_SESSION);
    }

    /**
     * Unserialize
     *
     * @param  string $session
     * @return mixed
     */
    public function unserialize($session)
    {
        return unserialize($session);
    }

    /** @inheritDoc */
    #[ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($_SESSION);
    }

    /**
     * Load session object from an existing array
     *
     * Ensures $_SESSION is set to an instance of the object when complete.
     *
     * @param  array          $array
     * @return SessionStorage
     */
    public function fromArray(array $array)
    {
        $ts       = $this->getRequestAccessTime();
        $_SESSION = $array;
        $this->setRequestAccessTime($ts);

        return $this;
    }

    /**
     * Mark object as isImmutable
     *
     * @return SessionStorage
     */
    public function markImmutable()
    {
        $_SESSION['_IMMUTABLE'] = true;

        return $this;
    }

    /**
     * Determine if this object is isImmutable
     *
     * @return bool
     */
    public function isImmutable()
    {
        return isset($_SESSION['_IMMUTABLE']) && $_SESSION['_IMMUTABLE'];
    }

    /**
     * Lock this storage instance, or a key within it
     *
     * @param  null|int|string $key
     * @return $this
     */
    public function lock($key = null)
    {
        if (null === $key) {
            $this->setMetadata('_READONLY', true);

            return $this;
        }
        if (isset($_SESSION[$key])) {
            $this->setMetadata('_LOCKS', [$key => true]);
        }

        return $this;
    }

    /**
     * Is the object or key marked as locked?
     *
     * @param  null|int|string $key
     * @return bool
     */
    public function isLocked($key = null)
    {
        if ($this->isImmutable()) {
            // isImmutable trumps all
            return true;
        }

        if (null === $key) {
            // testing for global lock
            return $this->getMetadata('_READONLY');
        }

        $locks    = $this->getMetadata('_LOCKS');
        $readOnly = $this->getMetadata('_READONLY');

        if ($readOnly && ! $locks) {
            // global lock in play; all keys are locked
            return true;
        }
        if ($readOnly && $locks) {
            return array_key_exists($key, $locks);
        }

        // test for individual locks
        if (! $locks) {
            return false;
        }

        return array_key_exists($key, $locks);
    }

    /**
     * Unlock an object or key marked as locked
     *
     * @param  null|int|string $key
     * @return $this
     */
    public function unlock($key = null)
    {
        if (null === $key) {
            // Unlock everything
            $this->setMetadata('_READONLY', false);
            $this->setMetadata('_LOCKS', false);

            return $this;
        }

        $locks = $this->getMetadata('_LOCKS');
        if (! $locks) {
            if (! $this->getMetadata('_READONLY')) {
                return $this;
            }
            $array = $this->toArray();
            $keys  = array_keys($array);
            $locks = array_flip($keys);
            unset($array, $keys);
        }

        if (array_key_exists($key, $locks)) {
            unset($locks[$key]);
            $this->setMetadata('_LOCKS', $locks, true);
        }

        return $this;
    }

    /**
     * Set storage metadata
     *
     * Metadata is used to store information about the data being stored in the
     * object. Some example use cases include:
     * - Setting expiry data
     * - Maintaining access counts
     * - localizing session storage
     * - etc.
     *
     * @param  string                     $key
     * @param  mixed                      $value
     * @param  bool                       $overwriteArray Whether to overwrite or merge array values; by default, merges
     * @return $this
     * @throws Exception\RuntimeException
     */
    public function setMetadata($key, $value, $overwriteArray = false)
    {
        if ($this->isImmutable()) {
            throw new Exception\RuntimeException(
                sprintf('Cannot set key "%s" as storage is marked isImmutable', $key)
            );
        }

        if (! isset($_SESSION['__Laminas']) || ! is_array($_SESSION['__Laminas'])) {
            $_SESSION['__Laminas'] = [];
        }
        if (isset($_SESSION['__Laminas'][$key]) && is_array($value)) {
            if ($overwriteArray) {
                $_SESSION['__Laminas'][$key] = $value;
            } else {
                $_SESSION['__Laminas'][$key] = array_replace_recursive($_SESSION['__Laminas'][$key], $value);
            }
        } else {
            if ((null === $value) && isset($_SESSION['__Laminas'][$key])) {
                $array = $_SESSION['__Laminas'];
                unset($array[$key]);
                $_SESSION['__Laminas'] = $array;
                unset($array);
            } elseif (null !== $value) {
                $_SESSION['__Laminas'][$key] = $value;
            }
        }

        return $this;
    }

    /**
     * Retrieve metadata for the storage object or a specific metadata key
     *
     * Returns false if no metadata stored, or no metadata exists for the given
     * key.
     *
     * @param  null|int|string $key
     * @return mixed
     */
    public function getMetadata($key = null)
    {
        if (! isset($_SESSION['__Laminas'])) {
            return false;
        }

        if (null === $key) {
            return $_SESSION['__Laminas'];
        }

        if (! array_key_exists($key, $_SESSION['__Laminas'])) {
            return false;
        }

        return $_SESSION['__Laminas'][$key];
    }

    /**
     * Clear the storage object or a subkey of the object
     *
     * @param  null|int|string            $key
     * @return $this
     * @throws Exception\RuntimeException
     */
    public function clear($key = null)
    {
        if ($this->isImmutable()) {
            throw new Exception\RuntimeException('Cannot clear storage as it is marked immutable');
        }
        if (null === $key) {
            $this->fromArray([]);

            return $this;
        }

        unset($_SESSION[$key]);
        $this->setMetadata($key, null)
            ->unlock($key);

        return $this;
    }

    /**
     * Retrieve the request access time
     *
     * @return float
     */
    public function getRequestAccessTime()
    {
        return $this->getMetadata('_REQUEST_ACCESS_TIME');
    }

    /**
     * Set the request access time
     *
     * @param  float        $time
     * @return $this
     */
    protected function setRequestAccessTime($time)
    {
        $this->setMetadata('_REQUEST_ACCESS_TIME', $time);

        return $this;
    }

    /**
     * Cast the object to an array
     *
     * @param  bool $metaData Whether to include metadata
     * @return array<TKey, TValue>
     */
    public function toArray($metaData = false)
    {
        if (isset($_SESSION)) {
            $values = $_SESSION;
        } else {
            $values = [];
        }

        if ($metaData) {
            return $values;
        }

        if (isset($values['__Laminas'])) {
            unset($values['__Laminas']);
        }

        return $values;
    }

    public function __serialize(): array
    {
        return $_SESSION;
    }

    public function __unserialize(array $session)
    {
    }
}
