<?php
/*
 *  $Id$
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ORM\Internal\Hydration;

use \PDO;
use Doctrine\ORM\PersistentCollection;

/**
 * The ObjectHydrator constructs an object graph out of an SQL result set.
 *
 * @author robo
 * @since 2.0
 */
class ObjectHydrator extends AbstractHydrator
{
    /** Collections initialized by the hydrator */
    private $_collections = array();
    /** Memory for initialized relations */
    private $_initializedRelations = array();
    private $_metadataMap = array();
    private $_rootAliases = array();
    private $_isSimpleQuery = false;
    private $_identifierMap = array();
    private $_resultPointers = array();
    private $_idTemplate = array();
    private $_resultCounter = 0;

    /** @override */
    protected function _prepare($parserResult)
    {
        parent::_prepare($parserResult);
        $this->_isSimpleQuery = $this->_resultSetMapping->getEntityResultCount() <= 1;
        $this->_identifierMap = array();
        $this->_resultPointers = array();
        $this->_idTemplate = array();
        $this->_resultCounter = 0;
        foreach ($this->_resultSetMapping->getAliasMap() as $dqlAlias => $class) {
            $this->_identifierMap[$dqlAlias] = array();
            $this->_resultPointers[$dqlAlias] = array();
            $this->_idTemplate[$dqlAlias] = '';
        }
    }

    /**
     * {@inheritdoc}
     *
     * @override
     */
    protected function _hydrateAll()
    {
        $s = microtime(true);
        
        if ($this->_parserResult->isMixedQuery()) {
            $result = array();
        } else {
            $result = new \Doctrine\Common\Collections\Collection;
        }

        $cache = array();
        while ($data = $this->_stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->_hydrateRow($data, $cache, $result);
        }

        // Take snapshots from all initialized collections
        foreach ($this->_collections as $coll) {
            $coll->takeSnapshot();
            $coll->setHydrationFlag(false);
        }
        
        // Clean up
        $this->_collections = array();
        $this->_initializedRelations = array();
        $this->_metadataMap = array();

        $e = microtime(true);
        echo 'Hydration took: ' . ($e - $s) . PHP_EOL;

        return $result;
    }

    /**
     * Updates the result pointer for an Entity. The result pointers point to the
     * last seen instance of each Entity type. This is used for graph construction.
     *
     * @param array $resultPointers  The result pointers.
     * @param Collection $coll  The element.
     * @param boolean|integer $index  Index of the element in the collection.
     * @param string $dqlAlias
     * @param boolean $oneToOne  Whether it is a single-valued association or not.
     */
    private function updateResultPointer(&$coll, $index, $dqlAlias, $oneToOne)
    {
        if ($coll === null) {
            unset($this->_resultPointers[$dqlAlias]); // Ticket #1228
            return;
        }

        if ($index !== false) {
            $this->_resultPointers[$dqlAlias] = $coll[$index];
            return;
        }

        if ( ! is_object($coll)) {
            end($coll);
            $this->_resultPointers[$dqlAlias] =& $coll[key($coll)];
        } else if ($coll instanceof \Doctrine\Common\Collections\Collection) {
            if (count($coll) > 0) {
                $this->_resultPointers[$dqlAlias] = $coll->last();
            }
        } else {
            $this->_resultPointers[$dqlAlias] = $coll;
        }
    }

    private function getCollection($component)
    {
        $coll = new PersistentCollection($this->_em, $component);
        $this->_collections[] = $coll;
        return $coll;
    }

    private function initRelatedCollection($entity, $name)
    {
        $oid = spl_object_hash($entity);
        $classMetadata = $this->_metadataMap[$oid];
        if ( ! isset($this->_initializedRelations[$oid][$name])) {
            $relation = $classMetadata->getAssociationMapping($name);
            $relatedClass = $this->_em->getClassMetadata($relation->getTargetEntityName());
            $coll = $this->getCollection($relatedClass->getClassName());
            $coll->setOwner($entity, $relation);
            $coll->setHydrationFlag(true);
            $classMetadata->getReflectionProperty($name)->setValue($entity, $coll);
            $this->_initializedRelations[$oid][$name] = true;
            $this->_uow->setOriginalEntityProperty($oid, $name, $coll);
        }
    }

    private function isIndexKeyInUse($entity, $assocField, $indexField)
    {
        return $this->_metadataMap[spl_object_hash($entity)]
                ->getReflectionProperty($assocField)
                ->getValue($entity)
                ->containsKey($indexField);
    }

    private function getLastKey($coll)
    {
        // Check needed because of mixed results.
        // is_object instead of is_array because is_array is slow on large arrays.
        if (is_object($coll)) {
            $coll->last();
            return $coll->key();
        } else {
            end($coll);
            return key($coll);
        }
    }

    private function getEntity(array $data, $className)
    {
        $entity = $this->_uow->createEntity($className, $data);
        $oid = spl_object_hash($entity);
        $this->_metadataMap[$oid] = $this->_em->getClassMetadata($className);
        return $entity;
    }

    /**
     * Adds an element to an indexed collection-valued property.
     *
     * @param <type> $entity1
     * @param <type> $property
     * @param <type> $entity2
     * @param <type> $indexField
     */
    private function addRelatedIndexedEntity($entity1, $property, $entity2, $indexField)
    {
        $classMetadata1 = $this->_metadataMap[spl_object_hash($entity1)];
        $classMetadata2 = $this->_metadataMap[spl_object_hash($entity2)];
        $indexValue = $classMetadata2->getReflectionProperty($indexField)->getValue($entity2);
        $classMetadata1->getReflectionProperty($property)->getValue($entity1)->set($indexValue, $entity2);
    }

    /**
     * Adds an element to a collection-valued property.
     *
     * @param <type> $entity1
     * @param <type> $property
     * @param <type> $entity2
     */
    private function addRelatedEntity($entity1, $property, $entity2)
    {
        $classMetadata1 = $this->_metadataMap[spl_object_hash($entity1)];
        $classMetadata1->getReflectionProperty($property)->getValue($entity1)->add($entity2);
    }

    /**
     * Checks whether a field on an entity has a non-null value.
     *
     * @param object $entity
     * @param string $field
     * @return boolean
     */
    private function isFieldSet($entity, $field)
    {
        return $this->_metadataMap[spl_object_hash($entity)]
                ->getReflectionProperty($field)
                ->getValue($entity) !== null;
    }

    /**
     * Sets a related element.
     *
     * @param <type> $entity1
     * @param <type> $property
     * @param <type> $entity2
     */
    private function setRelatedElement($entity1, $property, $entity2)
    {
        $oid = spl_object_hash($entity1);
        $classMetadata1 = $this->_metadataMap[$oid];
        $classMetadata1->getReflectionProperty($property)->setValue($entity1, $entity2);
        $this->_uow->setOriginalEntityProperty($oid, $property, $entity2);
        $relation = $classMetadata1->getAssociationMapping($property);
        if ($relation->isOneToOne()) {
            $targetClass = $this->_em->getClassMetadata($relation->getTargetEntityName());
            if ($relation->isOwningSide()) {
                // If there is an inverse mapping on the target class its bidirectional
                if ($targetClass->hasInverseAssociationMapping($property)) {
                    $oid2 = spl_object_hash($entity2);
                    $sourceProp = $targetClass->getInverseAssociationMapping($fieldName)->getSourceFieldName();
                    $targetClass->getReflectionProperty($sourceProp)->setValue($entity2, $entity1);
                }
            } else {
                // For sure bidirectional, as there is no inverse side in unidirectional
                $mappedByProp = $relation->getMappedByFieldName();
                $targetClass->getReflectionProperty($mappedByProp)->setValue($entity2, $entity1);
            }
        }
    }

    /**
     * {@inheritdoc}
     * 
     * @override
     */
    protected function _hydrateRow(array &$data, array &$cache, &$result)
    {
        // 1) Initialize
        $id = $this->_idTemplate; // initialize the id-memory
        $nonemptyComponents = array();
        $rowData = $this->_gatherRowData($data, $cache, $id, $nonemptyComponents);

        // Extract scalar values. They're appended at the end.
        if (isset($rowData['scalars'])) {
            $scalars = $rowData['scalars'];
            unset($rowData['scalars']);
        }

        // Now hydrate the entity data found in the current row.
        foreach ($rowData as $dqlAlias => $data) {
            $index = false;
            $entityName = $this->_resultSetMapping->getClass($dqlAlias)->getClassName();
            
            if ($this->_resultSetMapping->hasParentAlias($dqlAlias)) {
                // It's a joined result
                
                $parent = $this->_resultSetMapping->getParentAlias($dqlAlias);
                $relation = $this->_resultSetMapping->getRelation($dqlAlias);
                $relationAlias = $relation->getSourceFieldName();
                $path = $parent . '.' . $dqlAlias;

                // Get a reference to the right element in the result tree.
                // This element will get the associated element attached.
                if ($this->_parserResult->isMixedQuery() && isset($this->_rootAliases[$parent])) {
                    $key = key(reset($this->_resultPointers));
                    // TODO: Exception if $key === null ?
                    $baseElement =& $this->_resultPointers[$parent][$key];
                } else if (isset($this->_resultPointers[$parent])) {
                    $baseElement =& $this->_resultPointers[$parent];
                } else {
                    unset($this->_resultPointers[$dqlAlias]); // Ticket #1228
                    continue;
                }

                $oid = spl_object_hash($baseElement);

                // Check the type of the relation (many or single-valued)
                if ( ! $relation->isOneToOne()) {
                    $oneToOne = false;
                    if (isset($nonemptyComponents[$dqlAlias])) {
                        $this->initRelatedCollection($baseElement, $relationAlias);
                        $indexExists = isset($this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]]);
                        $index = $indexExists ? $this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]] : false;
                        $indexIsValid = $index !== false ? $this->isIndexKeyInUse($baseElement, $relationAlias, $index) : false;
                        if ( ! $indexExists || ! $indexIsValid) {
                            $element = $this->getEntity($data, $entityName);
                            if ($field = $this->_getCustomIndexField($dqlAlias)) {
                                $this->addRelatedIndexedEntity($baseElement, $relationAlias, $element, $field);
                            } else {
                                $this->addRelatedEntity($baseElement, $relationAlias, $element);
                            }
                            $this->_identifierMap[$path][$id[$parent]][$id[$dqlAlias]] = $this->getLastKey(
                                $this->_metadataMap[$oid]
                                        ->getReflectionProperty($relationAlias)
                                        ->getValue($baseElement));
                        }
                    } else if ( ! $this->isFieldSet($baseElement, $relationAlias)) {
                        $coll = new PersistentCollection($this->_em, $entityName);
                        $this->_collections[] = $coll;
                        $this->setRelatedElement($baseElement, $relationAlias, $coll);
                    }
                } else {
                    $oneToOne = true;
                    if ( ! isset($nonemptyComponents[$dqlAlias]) &&
                            ! $this->isFieldSet($baseElement, $relationAlias)) {
                        $this->setRelatedElement($baseElement, $relationAlias, null);
                    } else if ( ! $this->isFieldSet($baseElement, $relationAlias)) {
                        $this->setRelatedElement($baseElement, $relationAlias,
                                $this->getEntity($data, $entityName));
                    }
                }

                $coll = $this->_metadataMap[$oid]
                        ->getReflectionProperty($relationAlias)
                        ->getValue($baseElement);

                if ($coll !== null) {
                    $this->updateResultPointer($coll, $index, $dqlAlias, $oneToOne);
                }
            } else {
                // Its a root result element

                $this->_rootAliases[$dqlAlias] = true; // Mark as root alias

                if ($this->_isSimpleQuery || ! isset($this->_identifierMap[$dqlAlias][$id[$dqlAlias]])) {
                    $element = $this->_uow->createEntity($entityName, $rowData[$dqlAlias]);
                    $oid = spl_object_hash($element);
                    $this->_metadataMap[$oid] = $this->_em->getClassMetadata($entityName);
                    if ($field = $this->_getCustomIndexField($dqlAlias)) {
                        if ($this->_parserResult->isMixedQuery()) {
                            $result[] = array(
                                $this->_metadataMap[$oid]
                                        ->getReflectionProperty($field)
                                        ->getValue($element) => $element
                            );
                            ++$this->_resultCounter;
                        } else {
                            $result->set($element, $this->_metadataMap[$oid]
                                    ->getReflectionProperty($field)
                                    ->getValue($element));
                        }
                    } else {
                        if ($this->_parserResult->isMixedQuery()) {
                            $result[] = array($element);
                            ++$this->_resultCounter;
                        } else {
                            $result->add($element);
                        }
                    }
                    $this->_identifierMap[$dqlAlias][$id[$dqlAlias]] = $this->getLastKey($result);
                } else {
                    $index = $this->_identifierMap[$dqlAlias][$id[$dqlAlias]];
                }
                $this->updateResultPointer($result, $index, $dqlAlias, false);
                //unset($rowData[$dqlAlias]);
            }
        }

        // Append scalar values to mixed result sets
        if (isset($scalars)) {
            foreach ($scalars as $name => $value) {
                $result[$this->_resultCounter - 1][$name] = $value;
            }
        }
    }

    /** {@inheritdoc} */
    protected function _getRowContainer()
    {
        return new \Doctrine\Common\Collections\Collection;
    }
}