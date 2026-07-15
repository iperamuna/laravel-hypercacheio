package queue

import (
	"testing"
	"time"
)

func TestQueueManagerPushAndPop(t *testing.T) {
	qm := NewQueueManager("default")

	now := time.Now().Unix()

	// Push a ready job
	job1 := &Job{ID: "job1", Payload: "test-payload-1"}
	qm.Push(job1, now-10) // available in the past

	if len(qm.Ready) != 1 {
		t.Errorf("Expected 1 job in Ready queue, got %d", len(qm.Ready))
	}

	// Pop the job
	popped := qm.Pop(90) // 90s timeout
	if popped == nil || popped.ID != "job1" {
		t.Errorf("Expected to pop job1, got %v", popped)
	}

	// Ensure it was moved to reserved
	if qm.Reserved.Len() != 1 {
		t.Errorf("Expected 1 job in Reserved queue, got %d", qm.Reserved.Len())
	}
	if len(qm.Ready) != 0 {
		t.Errorf("Expected Ready queue to be empty")
	}
}

func TestQueueManagerDelayedJobs(t *testing.T) {
	qm := NewQueueManager("default")

	now := time.Now().Unix()

	// Push a delayed job (10 seconds in future)
	job1 := &Job{ID: "job1", Payload: "delayed"}
	qm.Push(job1, now+10)

	if qm.Delayed.Len() != 1 {
		t.Errorf("Expected 1 job in Delayed queue, got %d", qm.Delayed.Len())
	}
	if len(qm.Ready) != 0 {
		t.Errorf("Expected Ready queue to be empty")
	}

	// Try to pop immediately (should get nothing because it's delayed)
	popped := qm.Pop(90)
	if popped != nil {
		t.Errorf("Expected nil when popping early, got %v", popped.ID)
	}

	// Fast forward time by migrating with future timestamp
	qm.Migrate(now + 15)

	if len(qm.Ready) != 1 {
		t.Errorf("Expected 1 job in Ready queue after migrate, got %d", len(qm.Ready))
	}

	popped = qm.Pop(90)
	if popped == nil || popped.ID != "job1" {
		t.Errorf("Expected to pop job1 after delay, got %v", popped)
	}
}

func TestQueueManagerDelete(t *testing.T) {
	qm := NewQueueManager("default")

	job1 := &Job{ID: "job1", Payload: "delete-me"}
	qm.Push(job1, time.Now().Unix()-10)

	popped := qm.Pop(90)
	if popped == nil {
		t.Fatalf("Failed to pop job")
	}

	// Job should be in Reserved
	if qm.Reserved.Len() != 1 {
		t.Errorf("Expected job in Reserved")
	}

	// Delete the job
	success := qm.Delete("job1")
	if !success {
		t.Errorf("Expected Delete to return true")
	}

	// Ensure removed from Reserved and Lookup
	if qm.Reserved.Len() != 0 {
		t.Errorf("Expected Reserved to be empty after delete")
	}
	if _, exists := qm.Lookup["job1"]; exists {
		t.Errorf("Expected job to be removed from lookup")
	}
}
