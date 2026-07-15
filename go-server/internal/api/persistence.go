package api

import (
	"database/sql"
	"log"
	"sync"
	
	"github.com/iperamuna/hypercacheio-server/internal/queue"
)

var db *sql.DB

func SetDatabase(database *sql.DB) {
	db = database
	InitQueueSchema()
}

func InitQueueSchema() {
	if db == nil {
		return
	}
	
	_, err := db.Exec(`
		CREATE TABLE IF NOT EXISTS hypercacheio_queues(
			id TEXT PRIMARY KEY,
			queue_name TEXT NOT NULL,
			payload BLOB NOT NULL,
			available_at INTEGER NOT NULL,
			reserved_at INTEGER
		);
		CREATE INDEX IF NOT EXISTS idx_hypercacheio_queue_name ON hypercacheio_queues(queue_name);
		CREATE INDEX IF NOT EXISTS idx_hypercacheio_available_at ON hypercacheio_queues(available_at);
	`)
	
	if err != nil {
		log.Printf("Failed to initialize queue SQLite schema: %v", err)
	}
}

// Queue Persistence Worker
// -------------------------------------------------------------

type queueDbJob struct {
	op          string
	jobID       string
	queueName   string
	payload     string
	availableAt int64
}

var queueDbChan = make(chan queueDbJob, 5000)

func StartQueueDbWorker(wg *sync.WaitGroup) {
	if wg != nil {
		defer wg.Done()
	}
	log.Printf("Starting async queue persistence worker...")
	for job := range queueDbChan {
		switch job.op {
		case "SET":
			_, err := db.Exec(
				"REPLACE INTO hypercacheio_queues(id, queue_name, payload, available_at) VALUES(?, ?, ?, ?)",
				job.jobID, job.queueName, job.payload, job.availableAt,
			)
			if err != nil {
				log.Printf("Queue DB SET error: %v", err)
			}
		case "DEL":
			_, err := db.Exec("DELETE FROM hypercacheio_queues WHERE id = ?", job.jobID)
			if err != nil {
				log.Printf("Queue DB DEL error: %v", err)
			}
		}
	}
}

func ShutdownQueueDbWorker() {
	close(queueDbChan)
}

func PersistJob(job *queue.Job, queueName string, availableAt int64) {
	if db == nil {
		return
	}
	select {
	case queueDbChan <- queueDbJob{op: "SET", jobID: job.ID, queueName: queueName, payload: job.Payload, availableAt: availableAt}:
	default:
		log.Printf("Queue DB channel full, dropping persistence for job: %s", job.ID)
	}
}

func DeleteJob(jobID string) {
	if db == nil {
		return
	}
	select {
	case queueDbChan <- queueDbJob{op: "DEL", jobID: jobID}:
	default:
	}
}
