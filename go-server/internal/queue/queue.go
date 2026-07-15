package queue

import (
	"container/heap"
	"sync"
	"time"
)

// Job represents a queued job
type Job struct {
	ID        string
	Payload   string
	Timestamp int64 // For sorting in Delayed/Reserved queues
	Index     int   // The index of the item in the heap (needed by container/heap)
}

// PriorityQueue implements heap.Interface and holds Jobs.
// It acts as a Min-Heap (lowest timestamp comes first).
type PriorityQueue []*Job

func (pq PriorityQueue) Len() int { return len(pq) }

func (pq PriorityQueue) Less(i, j int) bool {
	// We want Pop to give us the highest priority (lowest timestamp)
	return pq[i].Timestamp < pq[j].Timestamp
}

func (pq PriorityQueue) Swap(i, j int) {
	pq[i], pq[j] = pq[j], pq[i]
	pq[i].Index = i
	pq[j].Index = j
}

func (pq *PriorityQueue) Push(x interface{}) {
	n := len(*pq)
	item := x.(*Job)
	item.Index = n
	*pq = append(*pq, item)
}

func (pq *PriorityQueue) Pop() interface{} {
	old := *pq
	n := len(old)
	item := old[n-1]
	old[n-1] = nil  // avoid memory leak
	item.Index = -1 // for safety
	*pq = old[0 : n-1]
	return item
}

// QueueManager manages the Ready, Delayed, and Reserved collections for a specific queue name
type QueueManager struct {
	Name     string
	Ready    []*Job // FIFO slice
	Delayed  PriorityQueue
	Reserved PriorityQueue
	
	Lookup   map[string]*Job // Fast O(1) lookup by Job ID
	
	mu       sync.RWMutex
}

func NewQueueManager(name string) *QueueManager {
	qm := &QueueManager{
		Name:     name,
		Ready:    make([]*Job, 0),
		Delayed:  make(PriorityQueue, 0),
		Reserved: make(PriorityQueue, 0),
		Lookup:   make(map[string]*Job),
	}
	heap.Init(&qm.Delayed)
	heap.Init(&qm.Reserved)
	return qm
}

// Clear removes all jobs from all states in this queue.
// Returns the list of deleted Job IDs so persistence can clean them up.
func (qm *QueueManager) Clear() []string {
	qm.mu.Lock()
	defer qm.mu.Unlock()

	deletedIds := make([]string, 0, len(qm.Lookup))
	for id := range qm.Lookup {
		deletedIds = append(deletedIds, id)
	}

	qm.Ready = make([]*Job, 0)
	qm.Delayed = make(PriorityQueue, 0)
	qm.Reserved = make(PriorityQueue, 0)
	qm.Lookup = make(map[string]*Job)

	return deletedIds
}

// GetSize returns the total number of jobs in all states of the queue.
func (qm *QueueManager) GetSize() int {
	qm.mu.RLock()
	defer qm.mu.RUnlock()
	return len(qm.Lookup)
}

// Push adds a job to either the Ready or Delayed queue based on availableAt
func (qm *QueueManager) Push(job *Job, availableAt int64) {
	qm.mu.Lock()
	defer qm.mu.Unlock()

	job.Timestamp = availableAt
	qm.Lookup[job.ID] = job

	now := time.Now().Unix()
	if availableAt <= now {
		// Ready to run immediately
		qm.Ready = append(qm.Ready, job)
	} else {
		// Delayed execution
		heap.Push(&qm.Delayed, job)
	}
}

// Migrate checks the Delayed and Reserved queues for jobs that are ready to run
// and moves them to the Ready queue.
func (qm *QueueManager) Migrate(now int64) {
	qm.mu.Lock()
	defer qm.mu.Unlock()

	// Migrate from Delayed to Ready
	for qm.Delayed.Len() > 0 {
		job := qm.Delayed[0] // Peek at min-heap root
		if job.Timestamp > now {
			break // Nothing else is ready yet
		}
		heap.Pop(&qm.Delayed)
		qm.Ready = append(qm.Ready, job)
	}

	// Migrate from Reserved (timed out jobs) to Ready
	for qm.Reserved.Len() > 0 {
		job := qm.Reserved[0]
		if job.Timestamp > now {
			break
		}
		heap.Pop(&qm.Reserved)
		qm.Ready = append(qm.Ready, job)
	}
}

// Pop attempts to reserve the next available job in the queue
func (qm *QueueManager) Pop(timeout int64) *Job {
	now := time.Now().Unix()
	
	// First, migrate any delayed/timed-out jobs
	qm.Migrate(now)

	qm.mu.Lock()
	defer qm.mu.Unlock()

	if len(qm.Ready) == 0 {
		return nil
	}

	// Pop from head of Ready queue (FIFO)
	job := qm.Ready[0]
	qm.Ready = qm.Ready[1:] // Reslice to remove head
	
	// Move to reserved queue with a timeout
	job.Timestamp = now + timeout
	heap.Push(&qm.Reserved, job)

	return job
}

// Delete permanently removes a job (e.g. when completed successfully)
func (qm *QueueManager) Delete(jobID string) bool {
	qm.mu.Lock()
	defer qm.mu.Unlock()

	job, exists := qm.Lookup[jobID]
	if !exists {
		return false
	}

	// If it is in a heap (Delayed or Reserved), remove it
	if job.Index != -1 {
		// To safely determine which heap it's in, we just attempt to remove from both
		// In a real optimized system, we'd track its state explicitly
		if qm.Delayed.Len() > job.Index && qm.Delayed[job.Index] == job {
			heap.Remove(&qm.Delayed, job.Index)
		} else if qm.Reserved.Len() > job.Index && qm.Reserved[job.Index] == job {
			heap.Remove(&qm.Reserved, job.Index)
		}
	}
	
	delete(qm.Lookup, jobID)
	return true
}

// Release puts a reserved job back onto the queue (possibly delayed)
func (qm *QueueManager) Release(jobID string, availableAt int64) bool {
	qm.mu.Lock()
	defer qm.mu.Unlock()

	job, exists := qm.Lookup[jobID]
	if !exists {
		return false
	}

	// Remove from Reserved heap
	if job.Index != -1 && qm.Reserved.Len() > job.Index && qm.Reserved[job.Index] == job {
		heap.Remove(&qm.Reserved, job.Index)
	}

	// Re-add to the appropriate queue
	job.Timestamp = availableAt
	now := time.Now().Unix()
	
	if availableAt <= now {
		qm.Ready = append(qm.Ready, job)
	} else {
		heap.Push(&qm.Delayed, job)
	}
	
	return true
}


// Peek returns the first available Ready job without removing it from the Ready heap
func (qm *QueueManager) Peek() *Job {
	now := time.Now().Unix()
	qm.Migrate(now)

	qm.mu.Lock()
	defer qm.mu.Unlock()

	if len(qm.Ready) == 0 {
		return nil
	}

	return qm.Ready[0]
}

// PopJobID explicitly pops a specific job by its ID (useful after acquiring a lock)
func (qm *QueueManager) PopJobID(jobID string, timeout int64) *Job {
	qm.mu.Lock()
	defer qm.mu.Unlock()

	job, exists := qm.Lookup[jobID]
	if !exists {
		return nil
	}

	// Find and remove from Ready
	found := false
	for i, j := range qm.Ready {
		if j.ID == jobID {
			qm.Ready = append(qm.Ready[:i], qm.Ready[i+1:]...)
			found = true
			break
		}
	}
	if !found {
		return nil
	}

	job.Timestamp = time.Now().Unix() + timeout
	heap.Push(&qm.Reserved, job)

	return job
}
