package queue

import (
	"log"
	"time"
)

// StartMaintenanceTicker starts a background goroutine that periodically scans all queues
// to promote delayed jobs and release expired reserved jobs back to the ready heap.
func StartMaintenanceTicker(interval time.Duration) {
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		
		log.Printf("Queue maintenance ticker started (interval: %s)", interval)
		
		for range ticker.C {
			qList := GetQueueManagers()
			
			for _, qm := range qList {
				qm.Migrate(time.Now().Unix())
			}
		}
	}()
}
