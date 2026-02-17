<?php

namespace Iperamuna\Hypercacheio;

use Illuminate\Cache\Lock;

class HypercacheioLock extends Lock
{
    /**
     * The Hypercacheio store instance.
     *
     * @var \Iperamuna\Hypercacheio\HypercacheioStore
     */
    protected $store;

    /**
     * Create a new lock instance.
     *
     * @param  \Iperamuna\Hypercacheio\HypercacheioStore  $store
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
