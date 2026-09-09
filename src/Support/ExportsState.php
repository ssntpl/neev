<?php

namespace Ssntpl\Neev\Support;

use ReflectionClass;

/**
 * Makes a rule object survive `php artisan config:cache`.
 *
 * The config cache is written with var_export() and read back with require.
 * var_export() renders an object as `\The\Class::__set_state([...])`, so a
 * class without that method makes the cache file fatal on load — which is why
 * Laravel reports the value as non-serializable. This trait supplies the
 * missing constructor-free rebuild.
 */
trait ExportsState
{
    /**
     * Rebuild the rule from the property values var_export() captured.
     */
    public static function __set_state(array $state): static
    {
        $instance = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        static::copyState($state, $instance);

        return $instance;
    }

    /**
     * Assign exported property values onto an instance.
     *
     * var_export() emits protected and private property names unmangled, so
     * each key is looked up by name, walking up to the class that declares it.
     */
    protected static function copyState(array $state, object $instance): void
    {
        foreach ($state as $name => $value) {
            $class = new ReflectionClass($instance);

            while ($class && ! $class->hasProperty($name)) {
                $class = $class->getParentClass();
            }

            if (! $class) {
                continue;
            }

            $property = $class->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($instance, $value);
        }
    }

    /**
     * Read every initialised property of an object, including inherited ones.
     *
     * Mirrors what var_export() captures, so the result can be handed back to
     * copyState() to clone an object of a different class.
     */
    protected static function stateOf(object $object): array
    {
        $state = [];

        for ($class = new ReflectionClass($object); $class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || array_key_exists($property->getName(), $state)) {
                    continue;
                }

                $property->setAccessible(true);

                if ($property->isInitialized($object)) {
                    $state[$property->getName()] = $property->getValue($object);
                }
            }
        }

        return $state;
    }
}
