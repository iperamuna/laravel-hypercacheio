package queue

import (
	"sync"
)

var (
	queues = make(map[string]*QueueManager)
	mu     sync.RWMutex
)

// GetQueue returns the QueueManager for the specified queue name, creating it if it doesn't exist
func GetQueue(name string) *QueueManager {
	mu.RLock()
	qm, exists := queues[name]
	mu.RUnlock()

	if exists {
		return qm
	}

	mu.Lock()
	defer mu.Unlock()
	// Double check to prevent race conditions
	if qm, exists := queues[name]; exists {
		return qm
	}

	qm = NewQueueManager(name)
	queues[name] = qm
	return qm
}

// GetQueueManagers returns a slice of all active queues
func GetQueueManagers() []*QueueManager {
	mu.RLock()
	defer mu.RUnlock()
	
	list := make([]*QueueManager, 0, len(queues))
	for _, qm := range queues {
		list = append(list, qm)
	}
	return list
}

// QueueStats holds the metrics for a single queue
type QueueStats struct {
	Ready        int   `json:"ready"`
	Delayed      int   `json:"delayed"`
	Reserved     int   `json:"reserved"`
	PayloadBytes int64 `json:"payload_bytes"`
}

// GetAllQueueStats returns stats for all active queues
func GetAllQueueStats() map[string]QueueStats {
	mu.RLock()
	defer mu.RUnlock()

	stats := make(map[string]QueueStats)
	for name, qm := range queues {
		qm.mu.RLock()
		
		var payloadBytes int64
		for _, job := range qm.Ready {
			payloadBytes += int64(len(job.ID) + len(job.Payload))
		}
		for _, job := range qm.Delayed {
			payloadBytes += int64(len(job.ID) + len(job.Payload))
		}
		for _, job := range qm.Reserved {
			payloadBytes += int64(len(job.ID) + len(job.Payload))
		}
		
		stats[name] = QueueStats{
			Ready:        len(qm.Ready),
			Delayed:      qm.Delayed.Len(),
			Reserved:     qm.Reserved.Len(),
			PayloadBytes: payloadBytes,
		}
		qm.mu.RUnlock()
	}

	return stats
}
