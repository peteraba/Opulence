<?php
/**
 * Copyright (C) 2014 David Young
 *
 * Defines a unit of work that tracks changes made to entities and atomically persists them
 */
namespace RDev\ORM;
use RDev\Databases\SQL;
use RDev\Exceptions;
use RDev\ORM\DataMappers;

class UnitOfWork
{
    /** @var SQL\IConnection The connection to use in our unit of work */
    private $connection = null;
    /** @var array The mapping of class names to their data mappers */
    private $dataMappers = [];
    /** @var array The list of entities scheduled for insertion */
    private $scheduledForInsertion = [];
    /** @var array The list of entities scheduled for update */
    private $scheduledForUpdate = [];
    /** @var array The list of entities scheduled for deletion */
    private $scheduledForDeletion = [];
    /** @var array The mapping of object Ids to their original data */
    private $objectHashIdsToOriginalData = [];
    /** @var array The mapping of entities' object hash Ids to their various states */
    private $entityStates = [];
    /** @var array The mapping of class names to a list of entities of that class */
    private $managedEntities = [];
    /**
     * Maps aggregate root children to their roots as well as functions that can set the child's aggregate root Id
     * Each entry is an array of arrays with the following keys:
     *      "aggregateRoot" => The aggregate root
     *      "child" => The entity whose aggregate root Id will be set to the Id of the aggregate root
     *      "function" => The function to execute that actually sets the aggregate root Id in the child
     *          Note:  The function MUST have two parameters: first for the aggregate root and a second for the child
     *
     * @var array
     */
    private $aggregateRootChildren = [];
    /**
     * The mapping of class names to their comparison functions, which can be used to speed up checking for updates
     * The functions should accept two instances of the same class, and it should return true if they contain the same
     * data, otherwise false
     *
     * @var array
     */
    private $comparisonFunctions = [];

    /**
     * @param SQL\IConnection $connection The connection to use in our unit of work
     */
    public function __construct(SQL\IConnection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Commits any entities that have been scheduled for insertion/updating/deletion
     *
     * @throws ORMException Thrown if there was an error committing the transaction
     */
    public function commit()
    {
        $this->checkForUpdates();
        $this->preCommit();
        $this->connection->beginTransaction();

        try
        {
            $this->insert();
            $this->update();
            $this->delete();
            $this->connection->commit();
        }
        catch(\Exception $ex)
        {
            $this->connection->rollBack();
            $this->postRollback();
            throw new ORMException($ex->getMessage());
        }

        $this->postCommit();

        // Clear our schedules
        $this->scheduledForInsertion = [];
        $this->scheduledForUpdate = [];
        $this->scheduledForDeletion = [];
        $this->aggregateRootChildren = [];
    }

    /**
     * Detaches an entity from being managed
     *
     * @param IEntity $entity The entity to detach
     */
    public function detach(IEntity $entity)
    {
        $entityState = $this->getEntityState($entity);

        if($entityState == EntityStates::ADDED || $entityState == EntityStates::MANAGED)
        {
            $className = $this->getClassName($entity);
            $objectHashId = $this->getObjectHashId($entity);
            $this->entityStates[$objectHashId] = EntityStates::DETACHED;
            unset($this->managedEntities[$className][$entity->getId()]);
            unset($this->objectHashIdsToOriginalData[$objectHashId]);
            unset($this->scheduledForInsertion[$objectHashId]);
            unset($this->scheduledForUpdate[$objectHashId]);
            unset($this->scheduledForDeletion[$objectHashId]);
            unset($this->aggregateRootChildren[$objectHashId]);
        }
    }

    /**
     * Disposes of all data in this unit of work
     */
    public function dispose()
    {
        $this->scheduledForInsertion = [];
        $this->scheduledForUpdate = [];
        $this->scheduledForDeletion = [];
        $this->aggregateRootChildren = [];
        $this->managedEntities = [];
        $this->entityStates = [];
        $this->objectHashIdsToOriginalData = [];
    }

    /**
     * Gets the data mapper for the input class
     *
     * @param string $className The name of the class whose data mapper we're searching for
     * @return DataMappers\SQLDataMapper The data mapper for the input class
     * @throws \RuntimeException Thrown if there was no data mapper for the input class name
     */
    public function getDataMapper($className)
    {
        if(!isset($this->dataMappers[$className]))
        {
            throw new \RuntimeException("No data mapper for " . $className);
        }

        return $this->dataMappers[$className];
    }

    /**
     * Gets the entity state for the input entity
     *
     * @param IEntity $entity The entity to check
     * @return int The entity state
     */
    public function getEntityState(IEntity $entity)
    {
        $objectHashId = $this->getObjectHashId($entity);

        if(!isset($this->entityStates[$objectHashId]))
        {
            return EntityStates::UNMANAGED;
        }

        return $this->entityStates[$objectHashId];
    }

    /**
     * Attempts to get a managed entity
     *
     * @param string $className The name of the class the entity belongs to
     * @param int|string $id The entity's Id
     * @return IEntity|null The entity if it was found, otherwise null
     */
    public function getManagedEntity($className, $id)
    {
        if(!isset($this->managedEntities[$className]) || !isset($this->managedEntities[$className][$id]))
        {
            return null;
        }

        return $this->managedEntities[$className][$id];
    }

    /**
     * Gets the list of entities that are scheduled for deletion
     *
     * @return IEntity[] The list of entities scheduled for deletion
     */
    public function getScheduledEntityDeletions()
    {
        return array_values($this->scheduledForDeletion);
    }

    /**
     * Gets the list of entities that are scheduled for insertion
     *
     * @return IEntity[] The list of entities scheduled for insertion
     */
    public function getScheduledEntityInsertions()
    {
        return array_values($this->scheduledForInsertion);
    }

    /**
     * Gets the list of entities that are scheduled for update
     *
     * @return IEntity[] The list of entities scheduled for update
     */
    public function getScheduledEntityUpdates()
    {
        return array_values($this->scheduledForUpdate);
    }

    /**
     * Gets whether or not an entity is being managed
     *
     * @param IEntity $entity The entity to check
     * @return bool True if the entity is managed, otherwise false
     */
    public function isManaged(IEntity $entity)
    {
        return $this->getEntityState($entity) == EntityStates::MANAGED
        || isset($this->managedEntities[$this->getClassName($entity)][$entity->getId()]);
    }

    /**
     * Adds entities to manage
     *
     * @param IEntity[] $entities The entities to manage
     */
    public function manageEntities(array $entities)
    {
        foreach($entities as $entity)
        {
            $this->manageEntity($entity);
        }
    }

    /**
     * Adds an entity to manage
     *
     * @param IEntity $entity The entity to manage
     */
    public function manageEntity(IEntity &$entity)
    {
        $className = $this->getClassName($entity);
        $objectHashId = $this->getObjectHashId($entity);

        if(!isset($this->managedEntities[$className]))
        {
            $this->managedEntities[$className] = [];
        }

        if(isset($this->managedEntities[$className][$entity->getId()]))
        {
            // Change the reference of the input entity to the one that's already managed
            $entity = $this->getManagedEntity($this->getClassName($entity), $entity->getId());
        }
        else
        {
            // Manage this entity
            $this->objectHashIdsToOriginalData[$objectHashId] = clone $entity;
            $this->managedEntities[$className][$entity->getId()] = $entity;
            $this->entityStates[$objectHashId] = EntityStates::MANAGED;
        }
    }

    /**
     * Registers a function to set the aggregate root Id in a child entity after the aggregate root has been inserted
     * Since the child depends on the aggregate root's Id being set, make sure the root is inserted before the child
     *
     * @param IEntity $aggregateRoot The aggregate root
     * @param IEntity $child The child of the aggregate root
     * @param callable $function The function that contains the logic to set the aggregate root Id in the child
     */
    public function registerAggregateRootChild(IEntity $aggregateRoot, IEntity $child, callable $function)
    {
        $childObjectHashId = $this->getObjectHashId($child);

        if(!isset($this->aggregateRootChildren[$childObjectHashId]))
        {
            $this->aggregateRootChildren[$childObjectHashId] = [];
        }

        $this->aggregateRootChildren[$childObjectHashId][] = [
            "aggregateRoot" => $aggregateRoot,
            "child" => $child,
            "function" => $function
        ];
    }

    /**
     * Registers a comparison function for a class, which speeds up the check for updates
     * Registering a comparison function for a class will overwrite any previously-set comparison functions for that class
     *
     * @param string $className The name of the class whose comparison function we're registering
     * @param callable $function The function that takes two instances of the same class and returns whether or not
     *      they're considered identical
     */
    public function registerComparisonFunction($className, callable $function)
    {
        $this->comparisonFunctions[$className] = $function;
    }

    /**
     * Registers a data mapper for a class
     * Registering a data mapper for a class will overwrite any previously-set data mapper for that class
     *
     * @param string $className The name of the class whose data mapper we're registering
     * @param DataMappers\IDataMapper $dataMapper The data mapper for the class
     */
    public function registerDataMapper($className, DataMappers\IDataMapper $dataMapper)
    {
        $this->dataMappers[$className] = $dataMapper;
    }

    /**
     * Schedules an entity for deletion
     *
     * @param IEntity $entity The entity to schedule for deletion
     */
    public function scheduleForDeletion(IEntity $entity)
    {
        $this->scheduledForDeletion[$this->getObjectHashId($entity)] = $entity;
    }

    /**
     * Schedules an entity for insertion
     *
     * @param IEntity $entity The entity to schedule for insertion
     */
    public function scheduleForInsertion(IEntity $entity)
    {
        $objectHashId = $this->getObjectHashId($entity);
        $this->scheduledForInsertion[$objectHashId] = $entity;
        $this->entityStates[$objectHashId] = EntityStates::ADDED;
    }

    /**
     * Schedules an entity for insertion
     *
     * @param IEntity $entity The entity to schedule for insertion
     */
    public function scheduleForUpdate(IEntity $entity)
    {
        $this->scheduledForUpdate[$this->getObjectHashId($entity)] = $entity;
    }

    /**
     * Performs any actions after the commit
     */
    protected function postCommit()
    {
        /**
         * @var string $className
         * @var DataMappers\IDataMapper $dataMapper
         */
        foreach($this->dataMappers as $className => $dataMapper)
        {
            if($dataMapper instanceof DataMappers\ICachedSQLDataMapper)
            {
                // Now that the database writes have been committed, we can write to cache
                /** @var DataMappers\ICachedSQLDataMapper $dataMapper */
                $dataMapper->commit();
            }
        }
    }

    /**
     * Performs any actions after a rollback
     */
    protected function postRollback()
    {
        // Unset each of the new entities' Ids
        /** @var IEntity $entity */
        foreach($this->scheduledForInsertion as $objectHashId => $entity)
        {
            $dataMapper = $this->getDataMapper($this->getClassName($entity));
            $entity->setId($dataMapper->getIdGenerator()->getEmptyValue());
        }
    }

    /**
     * Performs any actions before a commit
     */
    protected function preCommit()
    {
        // Leave blank for extending classes to implement
    }

    /**
     * Checks to see if an entity has changed using a comparison function
     *
     * @param string $objectHashId The object hash Id of the entity
     * @param IEntity $entity The entity to check for changes
     * @return bool True if the entity has changed, otherwise false
     */
    private function checkEntityForUpdatesWithComparisonFunction($objectHashId, IEntity $entity)
    {
        $originalData = $this->objectHashIdsToOriginalData[$objectHashId];

        return !$this->comparisonFunctions[$this->getClassName($entity)]($originalData, $entity);
    }

    /**
     * Checks to see if an entity has changed using reflection
     *
     * @param string $objectHashId The object hash Id of the entity
     * @param IEntity $entity The entity to check for changes
     * @return bool True if the entity has changed, otherwise false
     */
    private function checkEntityForUpdatesWithReflection($objectHashId, IEntity $entity)
    {
        // Get all the properties in the original entity and the current one
        $currentEntityReflection = new \ReflectionClass($entity);
        $currentProperties = $currentEntityReflection->getProperties();
        $currentPropertiesAsHash = [];
        $originalData = $this->objectHashIdsToOriginalData[$objectHashId];
        $originalEntityReflection = new \ReflectionClass($originalData);
        $originalProperties = $originalEntityReflection->getProperties();
        $originalPropertiesAsHash = [];

        // Map each property name to its value for the current entity
        foreach($currentProperties as $currentProperty)
        {
            $currentProperty->setAccessible(true);
            $currentPropertiesAsHash[$currentProperty->getName()] = $currentProperty->getValue($entity);
        }

        // Map each property name to its value for the original entity
        foreach($originalProperties as $originalProperty)
        {
            $originalProperty->setAccessible(true);
            $originalPropertiesAsHash[$originalProperty->getName()] = $originalProperty->getValue($originalData);
        }

        if(count($originalProperties) != count($currentProperties))
        {
            // Clearly there's a difference here, so update
            return true;
        }

        // Compare all the property values to see if they are identical
        foreach($originalPropertiesAsHash as $name => $value)
        {
            if(!array_key_exists($name, $currentPropertiesAsHash) || $currentPropertiesAsHash[$name] !== $value)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks for any changes made to entities, and if any are found, they're scheduled for update
     */
    private function checkForUpdates()
    {
        foreach($this->managedEntities as $className => $entities)
        {
            /** @var IEntity $entity */
            foreach($entities as $entityId => $entity)
            {
                $objectHashId = $this->getObjectHashId($entity);

                // No point in checking for changes if it's already scheduled for an action
                if($this->isManaged($entity)
                    && !isset($this->scheduledForInsertion[$objectHashId])
                    && !isset($this->scheduledForUpdate[$objectHashId])
                    && !isset($this->scheduledForDeletion[$objectHashId])
                )
                {
                    // If a comparison function was specified, we don't bother using reflection to check for updates
                    if(isset($this->comparisonFunctions[$className]))
                    {
                        if($this->checkEntityForUpdatesWithComparisonFunction($objectHashId, $entity))
                        {
                            $this->scheduleForUpdate($entity);
                        }
                    }
                    elseif($this->checkEntityForUpdatesWithReflection($objectHashId, $entity))
                    {
                        $this->scheduleForUpdate($entity);
                    }
                }
            }
        }
    }

    /**
     * Attempts to update all the entities scheduled for deletion
     */
    private function delete()
    {
        /** @var IEntity $entity */
        foreach($this->scheduledForDeletion as $objectHashId => $entity)
        {
            $dataMapper = $this->getDataMapper($this->getClassName($entity));
            $dataMapper->delete($entity);
            // Order here matters
            $this->detach($entity);
            $this->entityStates[$objectHashId] = EntityStates::DELETED;
        }
    }

    /**
     * Executes the aggregate root functions, if there any for the input entity
     *
     * @param string $objectHashId The object hash Id of the child
     * @param IEntity $child The child entity
     */
    private function doAggregateRootFunctions($objectHashId, IEntity $child)
    {
        if(isset($this->aggregateRootChildren[$objectHashId]))
        {
            foreach($this->aggregateRootChildren[$objectHashId] as $aggregateRootData)
            {
                $aggregateRoot = $aggregateRootData["aggregateRoot"];
                $aggregateRootData["function"]($aggregateRoot, $child);
            }
        }
    }

    /**
     * Gets the object's class name
     *
     * @param mixed $object The object whose class name we want
     * @return string The object's class name
     */
    private function getClassName($object)
    {
        return get_class($object);
    }

    /**
     * Gets a unique hash Id for an object
     *
     * @param mixed $object The object whose hash we want
     * @return string The object hash Id
     */
    private function getObjectHashId($object)
    {
        return spl_object_hash($object);
    }

    /**
     * Attempts to insert all the entities scheduled for insertion
     */
    private function insert()
    {
        /** @var IEntity $entity */
        foreach($this->scheduledForInsertion as $objectHashId => $entity)
        {
            // If this entity was a child of aggregate roots, then call its methods to set the aggregate root Id
            $this->doAggregateRootFunctions($objectHashId, $entity);
            $dataMapper = $this->getDataMapper($this->getClassName($entity));
            $dataMapper->add($entity);
            $entity->setId($dataMapper->getIdGenerator()->generate($entity, $this->connection));
            $this->manageEntity($entity);
        }
    }

    /**
     * Attempts to update all the entities scheduled for updating
     */
    private function update()
    {
        /** @var IEntity $entity */
        foreach($this->scheduledForUpdate as $objectHashId => $entity)
        {
            // If this entity was a child of aggregate roots, then call its methods to set the aggregate root Id
            $this->doAggregateRootFunctions($objectHashId, $entity);
            $dataMapper = $this->getDataMapper($this->getClassName($entity));
            $dataMapper->update($entity);
            $this->manageEntity($entity);
        }
    }
} 