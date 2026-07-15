package api

import (
	// "net"
	// We would import the peer map from main here if it were exported, 
	// or we can expose a callback from main to register the broadcast function.
)

var BroadcastQueuePushFunc func(jobID, queueName, payload string, availableAt int64)
var BroadcastQueueDelFunc func(jobID, queueName string)
var BroadcastQueueClearFunc func(queueName string)

func BroadcastQueuePush(jobID, queueName, payload string, availableAt int64) {
	if BroadcastQueuePushFunc != nil {
		// Run concurrently so we don't block the HTTP response
		go BroadcastQueuePushFunc(jobID, queueName, payload, availableAt)
	}
}

func BroadcastQueueDel(jobID, queueName string) {
	if BroadcastQueueDelFunc != nil {
		go BroadcastQueueDelFunc(jobID, queueName)
	}
}

func BroadcastQueueClear(queueName string) {
	if BroadcastQueueClearFunc != nil {
		go BroadcastQueueClearFunc(queueName)
	}
}
var AcquireDistributedLockFunc func(key string, ttl int64) bool

func AcquireDistributedLock(key string, ttl int64) bool {
	if AcquireDistributedLockFunc != nil {
		return AcquireDistributedLockFunc(key, ttl)
	}
	// If HA is off or function not set, assume we always get the lock
	return true
}
