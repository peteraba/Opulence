<?php

/*
 * Opulence
 *
 * @link      https://www.opulencephp.com
 * @copyright Copyright (C) 2021 David Young
 * @license   https://github.com/opulencephp/Opulence/blob/1.2/LICENSE.md
 */

namespace Opulence\Ioc\Bootstrappers;

use InvalidArgumentException;

/**
 * Defines the bootstrapper registry
 */
class BootstrapperRegistry implements IBootstrapperRegistry
{
    /** @var array The list of lazy bootstrapper classes */
    private $bindingsToLazyBootstrapperClasses = [];
    /** @var array The list of expedited bootstrapper classes */
    private $eagerBootstrapperClasses = [];

    /**
     * @inheritdoc
     */
    public function getEagerBootstrappers() : array
    {
        return $this->eagerBootstrapperClasses;
    }

    /**
     * @inheritdoc
     */
    public function getLazyBootstrapperBindings() : array
    {
        return $this->bindingsToLazyBootstrapperClasses;
    }

    /**
     * @inheritdoc
     */
    public function registerBootstrapper(Bootstrapper $bootstrapper)
    {
        if ($bootstrapper instanceof ILazyBootstrapper) {
            $this->registerLazyBootstrapper(
                $bootstrapper->getBindings(),
                get_class($bootstrapper)
            );
        } else {
            $this->registerEagerBootstrapper(get_class($bootstrapper));
        }
    }

    /**
     * @inheritdoc
     */
    public function registerEagerBootstrapper($eagerBootstrapperClasses)
    {
        $eagerBootstrapperClasses = (array)$eagerBootstrapperClasses;
        $this->eagerBootstrapperClasses = array_merge($this->eagerBootstrapperClasses, $eagerBootstrapperClasses);
    }

    /**
     * @inheritdoc
     */
    public function registerLazyBootstrapper(array $bindings, string $lazyBootstrapperClass)
    {
        foreach ($bindings as $boundClass) {
            $targetClass = null;

            // If it's a targeted binding
            if (is_array($boundClass)) {
                if (count($boundClass) !== 1) {
                    throw new InvalidArgumentException(
                        'Targeted bindings must be in format "BoundClass => TargetClass"'
                    );
                }

                $targetClass = array_values($boundClass)[0];
                $boundClass = array_keys($boundClass)[0];
            }

            $this->bindingsToLazyBootstrapperClasses[$boundClass] = [
                'bootstrapper' => $lazyBootstrapperClass,
                'target' => $targetClass
            ];
        }
    }

    /**
     * @inheritdoc
     */
    public function registerManyBootstrappers(array $boostrappers)
    {
        foreach ($boostrappers as $boostrapper) {
            $this->registerBootstrapper($boostrapper);
        }
    }
}
