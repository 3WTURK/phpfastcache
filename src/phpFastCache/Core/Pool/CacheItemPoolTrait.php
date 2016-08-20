<?php
/**
 *
 * This file is part of phpFastCache.
 *
 * @license MIT License (MIT)
 *
 * For full copyright and license information, please see the docs/CREDITS.txt file.
 *
 * @author Khoa Bui (khoaofgod)  <khoaofgod@gmail.com> http://www.phpfastcache.com
 * @author Georges.L (Geolim4)  <contact@geolim4.com>
 *
 */

namespace phpFastCache\Core\Pool;

use phpFastCache\Core\Item\ExtendedCacheItemInterface;
use phpFastCache\CacheManager;
use phpFastCache\EventManager;
use phpFastCache\Exceptions\phpFastCacheCoreException;
use Psr\Cache\CacheItemInterface;
use phpFastCache\Util\ClassNamespaceResolverTrait;

/**
 * Trait StandardPsr6StructureTrait
 * @package phpFastCache\Core
 *
 */
trait CacheItemPoolTrait
{
    use ClassNamespaceResolverTrait;

    /**
     * @var array
     */
    protected $deferredList = [];

    /**
     * @var ExtendedCacheItemInterface[]
     */
    protected $itemInstances = [];

    /**
     * @var EventManager
     */
    protected $eventManager;

    /**
     * @param string $key
     * @return \phpFastCache\Core\Item\ExtendedCacheItemInterface
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws phpFastCacheCoreException
     */
    public function getItem($key)
    {
        if (is_string($key)) {
            if (!array_key_exists($key, $this->itemInstances)) {

                /**
                 * @var $item ExtendedCacheItemInterface
                 */
                CacheManager::$ReadHits++;
                $class = new \ReflectionClass((new \ReflectionObject($this))->getNamespaceName() . '\Item');
                $item = $class->newInstanceArgs([$this, $key]);
                $item->setEventManager($this->eventManager);
                $driverArray = $this->driverRead($item);

                if ($driverArray) {
                    if(!is_array($driverArray)){
                        throw new phpFastCacheCoreException(sprintf('The driverRead method returned an unexpected variable type: %s', gettype($driverArray)));
                    }
                    $item->set($this->driverUnwrapData($driverArray));
                    $item->expiresAt($this->driverUnwrapEdate($driverArray));

                    if($this->config['itemDetailedDate']){

                        /**
                         * If the itemDetailedDate has been
                         * set after caching, we MUST inject
                         * a new DateTime object on the fly
                         */
                        $item->setCreationDate($this->driverUnwrapCdate($driverArray) ?: new \DateTime());
                        $item->setModificationDate($this->driverUnwrapMdate($driverArray) ?: new \DateTime());
                    }

                    $item->setTags($this->driverUnwrapTags($driverArray));
                    if ($item->isExpired()) {
                        /**
                         * Using driverDelete() instead of delete()
                         * to avoid infinite loop caused by
                         * getItem() call in delete() method
                         * As we MUST return an item in any
                         * way, we do not de-register here
                         */
                        $this->driverDelete($item);
                    } else {
                        $item->setHit(true);
                    }
                }else{
                    $item->expiresAfter(abs((int) $this->getConfig()[ 'defaultTtl' ]));
                }

            }
        } else {
            throw new \InvalidArgumentException(sprintf('$key must be a string, got type "%s" instead.', gettype($key)));
        }

        /**
         * @eventName CacheGetItem
         * @param $this ExtendedCacheItemPoolInterface
         * @param $this ExtendedCacheItemInterface
         */
        $this->eventManager->dispatch('CacheGetItem', $this, $this->itemInstances[ $key ]);

        return $this->itemInstances[ $key ];
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return $this
     * @throws \InvalidArgumentException
     */
    public function setItem(CacheItemInterface $item)
    {
        if ($this->getClassNamespace() . '\\Item' === get_class($item)) {
            $this->itemInstances[ $item->getKey() ] = $item;

            return $this;
        } else {
            throw new \InvalidArgumentException(sprintf('Invalid Item Class "%s" for this driver.', get_class($item)));
        }
    }

    /**
     * @param array $keys
     * @return CacheItemInterface[]
     * @throws \InvalidArgumentException
     */
    public function getItems(array $keys = [])
    {
        $collection = [];
        foreach ($keys as $key) {
            $collection[ $key ] = $this->getItem($key);
        }

        return $collection;
    }

    /**
     * @param string $key
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function hasItem($key)
    {
        CacheManager::$ReadHits++;

        return $this->getItem($key)->isHit();
    }

    /**
     * @return bool
     */
    public function clear()
    {
        /**
         * @eventName CacheClearItem
         * @param $this ExtendedCacheItemPoolInterface
         * @param $deferredList ExtendedCacheItemInterface[]
         */
        $this->eventManager->dispatch('CacheClearItem', $this, $this->itemInstances);

        CacheManager::$WriteHits++;
        $this->itemInstances = [];

        return $this->driverClear();
    }

    /**
     * @param string $key
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function deleteItem($key)
    {
        $item = $this->getItem($key);
        if ($this->hasItem($key) && $this->driverDelete($item)) {
            $item->setHit(false);
            CacheManager::$WriteHits++;
            /**
             * De-register the item instance
             * then collect gc cycles
             */
            $this->deregisterItem($key);

            return true;
        }

        return false;
    }

    /**
     * @param array $keys
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function deleteItems(array $keys)
    {
        $return = null;
        foreach ($keys as $key) {
            $result = $this->deleteItem($key);
            if ($result !== false) {
                $return = $result;
            }
        }

        return (bool) $return;
    }

    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return mixed
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function save(CacheItemInterface $item)
    {
        /**
         * @var ExtendedCacheItemInterface $item
         */
        if (!array_key_exists($item->getKey(), $this->itemInstances)) {
            $this->itemInstances[ $item->getKey() ] = $item;
        } else if(spl_object_hash($item) !== spl_object_hash($this->itemInstances[ $item->getKey() ])){
            throw new \RuntimeException('Spl object hash mismatches ! You probably tried to save a detached item which has been already retrieved from cache.');
        }

        /**
         * @eventName CacheSaveItem
         * @param $this ExtendedCacheItemPoolInterface
         * @param $this ExtendedCacheItemInterface
         */
        $this->eventManager->dispatch('CacheSaveItem', $this, $item);

        if ($this->driverWrite($item) && $this->driverWriteTags($item)) {
            $item->setHit(true);
            CacheManager::$WriteHits++;

            return true;
        }

        return false;
    }


    /**
     * @param \Psr\Cache\CacheItemInterface $item
     * @return \Psr\Cache\CacheItemInterface
     * @throws \RuntimeException
     */
    public function saveDeferred(CacheItemInterface $item)
    {
        if (!array_key_exists($item->getKey(), $this->itemInstances)) {
            $this->itemInstances[ $item->getKey() ] = $item;
        }else if(spl_object_hash($item) !== spl_object_hash($this->itemInstances[ $item->getKey() ])){
            throw new \RuntimeException('Spl object hash mismatches ! You probably tried to save a detached item which has been already retrieved from cache.');
        }

        /**
         * @eventName CacheSaveDeferredItem
         * @param $this ExtendedCacheItemPoolInterface
         * @param $this ExtendedCacheItemInterface
         */
        $this->eventManager->dispatch('CacheSaveDeferredItem', $this, $item);

        return $this->deferredList[ $item->getKey() ] = $item;
    }

    /**
     * @return mixed|null
     * @throws \InvalidArgumentException
     */
    public function commit()
    {
        /**
         * @eventName CacheCommitItem
         * @param $this ExtendedCacheItemPoolInterface
         * @param $deferredList ExtendedCacheItemInterface[]
         */
        $this->eventManager->dispatch('CacheCommitItem', $this, $this->deferredList);

        $return = null;
        foreach ($this->deferredList as $key => $item) {
            $result = $this->save($item);
            if ($return !== false) {
                unset($this->deferredList[ $key ]);
                $return = $result;
            }
        }

        return (bool) $return;
    }
}