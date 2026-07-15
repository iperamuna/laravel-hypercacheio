package api

import (
	"encoding/json"
	"io"
	"net/http"
	"github.com/google/uuid"
	"github.com/iperamuna/hypercacheio-server/internal/queue"
)

type QueuePayload struct {
	Queue   string `json:"queue"`
	Payload string `json:"payload,omitempty"`
	Delay   int64  `json:"delay,omitempty"`
	Timeout int64  `json:"timeout,omitempty"`
	ID      string `json:"id,omitempty"`
}

func writeJSON(w http.ResponseWriter, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(data)
}

func HandleQueuePush(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, _ := io.ReadAll(r.Body)
	var payload QueuePayload
	if err := json.Unmarshal(body, &payload); err != nil || payload.Queue == "" {
		http.Error(w, "Invalid payload", http.StatusBadRequest)
		return
	}

	qm := queue.GetQueue(payload.Queue)
	
	jobID := uuid.New().String()
	job := &queue.Job{
		ID:      jobID,
		Payload: payload.Payload,
	}

	qm.Push(job, payload.Delay)

	// Persist the job using the async worker
	PersistJob(job, payload.Queue, payload.Delay)

	// Fire off replication to peers in the background
	BroadcastQueuePush(job.ID, payload.Queue, payload.Payload, payload.Delay)

	writeJSON(w, map[string]interface{}{
		"success": true,
		"id":      jobID,
	})
}

func HandleQueuePop(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, _ := io.ReadAll(r.Body)
	var payload QueuePayload
	json.Unmarshal(body, &payload)
	
	if payload.Queue == "" {
		payload.Queue = "default"
	}
	if payload.Timeout <= 0 {
		payload.Timeout = 90
	}

	qm := queue.GetQueue(payload.Queue)
	
	// HA Distributed Locking Pop logic
	var job *queue.Job
	for {
		peekedJob := qm.Peek()
		if peekedJob == nil {
			break
		}

		// Try to acquire distributed lock for this specific job
		lockKey := "queue_lock:" + peekedJob.ID
		locked := AcquireDistributedLock(lockKey, 120) // 120s TTL for lock, enough to cover worker processing time
		if locked {
			// We successfully locked it cluster-wide! 
			// We are the authoritative node to pop this job.
			job = qm.PopJobID(peekedJob.ID, payload.Timeout)
			break
		} else {
			// Someone else across the cluster locked it!
			// We should skip it. But wait, if we skip it, it stays in OUR ready queue 
			// until we receive the QDel from the node that actually processed it.
			// To avoid an infinite loop peeking the same job, we temporarily hide it or just
			// sleep a tiny bit and retry, or ideally `PopJobID` the lock-winner so we don't see it again.
			// Actually, if we failed the lock, the other node has it. We should assume it's being processed.
			// Let's proactively move it to Reserved locally so we don't peek it again in the next loop iteration.
			qm.PopJobID(peekedJob.ID, payload.Timeout)
			continue
		}
	}

	if job == nil {
		writeJSON(w, map[string]interface{}{"job": nil})
		return
	}

	writeJSON(w, map[string]interface{}{
		"job": map[string]interface{}{
			"id":      job.ID,
			"payload": job.Payload,
		},
	})
}

func HandleQueueDelete(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, _ := io.ReadAll(r.Body)
	var payload QueuePayload
	json.Unmarshal(body, &payload)

	qm := queue.GetQueue(payload.Queue)
	deleted := qm.Delete(payload.ID)

	if deleted {
		DeleteJob(payload.ID)
		BroadcastQueueDel(payload.ID, payload.Queue) // Send Tombstone to peers
	}

	writeJSON(w, map[string]interface{}{
		"success": deleted,
	})
}

func HandleQueueRelease(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, _ := io.ReadAll(r.Body)
	var payload QueuePayload
	json.Unmarshal(body, &payload)

	qm := queue.GetQueue(payload.Queue)
	released := qm.Release(payload.ID, payload.Delay)

	if released {
		// When released, the job is put back on the queue. Re-persist its new state
		job := qm.Lookup[payload.ID]
		if job != nil {
			PersistJob(job, payload.Queue, payload.Delay)
		}
	}

	writeJSON(w, map[string]interface{}{
		"success": released,
	})
}
