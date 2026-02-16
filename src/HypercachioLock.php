<?php

namespace Iperamuna\Hypercachio;

use Illuminate\Cache\Lock;

class HypercachioLock extends Lock
{
    /**
     * The Hypercachio store instance.
     *
     * @var \Iperamuna\Hypercachio\HypercachioStore
     */
    protected $store;

    /**
     * Create a new lock instance.
     *
     * @param  \Iperamuna\Hypercachio\HypercachioStore  $store
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     * @return void
     */
    public function __construct($store, $name, $seconds, $owner = null)
    {
        parent::__construct($name, $seconds, $owner);

        $this->store = $store;
    }

    /**
     * Attempt to acquire the lock.
     *
     * @return bool
     */
    public function acquire()
    {
        return $this->store->acquireLock($this->name, $this->owner, $this->seconds);
    }

    /**
     * Release the lock.
     *
     * @return bool
     */
    public function release()
    {
        return $this->store->releaseLock($this->name, $this->owner);
    }

    /**
     * Forcefully release the lock.
     *
     * @return void
     */
    public function forceRelease()
    {
        $this->store->releaseLock($this->name, $this->owner);
    }

    /**
     * Get the current owner of the lock.
     *
     * @return string
     */
    protected function getCurrentOwner()
    {
        return $this->store->getLockOwner($this->name);
    }
}
