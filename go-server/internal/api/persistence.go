package api

import (
	"database/sql"
	"log"
	"sync"
	"time"
	
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
		case "CLEAR":
			_, err := db.Exec("DELETE FROM hypercacheio_queues WHERE queue_name = ?", job.queueName)
			if err != nil {
				log.Printf("Queue DB CLEAR error: %v", err)
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
		// Fallback to async execution if persistence channel is saturated so deletes are never dropped
		go func(id string) {
			_, err := db.Exec("DELETE FROM hypercacheio_queues WHERE id = ?", id)
			if err != nil {
				log.Printf("Queue DB fallback DEL error: %v", err)
			}
		}(jobID)
	}
}

func ClearQueueJobs(queueName string) {
	if db == nil {
		return
	}
	select {
	case queueDbChan <- queueDbJob{op: "CLEAR", queueName: queueName}:
	default:
		go func(q string) {
			_, err := db.Exec("DELETE FROM hypercacheio_queues WHERE queue_name = ?", q)
			if err != nil {
				log.Printf("Queue DB fallback CLEAR error: %v", err)
			}
		}(queueName)
	}
}

// LoadQueueJobsFromSqlite prunes ancient orphaned jobs and loads active pending/delayed jobs into memory on startup.
func LoadQueueJobsFromSqlite(maxAgeSeconds int64) (int, int) {
	if db == nil {
		return 0, 0
	}

	now := time.Now().Unix()
	pruned := 0
	loaded := 0

	// 1. Prune ancient orphaned jobs older than maxAgeSeconds (e.g. 7 days)
	if maxAgeSeconds > 0 {
		cutoff := now - maxAgeSeconds
		res, err := db.Exec("DELETE FROM hypercacheio_queues WHERE available_at < ?", cutoff)
		if err == nil {
			if count, err := res.RowsAffected(); err == nil {
				pruned = int(count)
			}
		} else {
			log.Printf("Failed to prune ancient jobs from SQLite: %v", err)
		}
	}

	// 2. Load unexpired jobs into memory queues
	rows, err := db.Query("SELECT id, queue_name, payload, available_at FROM hypercacheio_queues ORDER BY available_at ASC")
	if err != nil {
		log.Printf("Failed to load queues from SQLite: %v", err)
		return 0, pruned
	}
	defer rows.Close()

	for rows.Next() {
		var id, qName, payload string
		var avail int64
		if err := rows.Scan(&id, &qName, &payload, &avail); err == nil {
			qm := queue.GetQueue(qName)
			job := &queue.Job{
				ID:      id,
				Payload: payload,
			}
			delay := avail - now
			if delay < 0 {
				delay = 0
			}
			qm.Push(job, delay)
			loaded++
		}
	}

	// 3. Clear any stale reservation timestamps so jobs can be picked up by workers
	_, _ = db.Exec("UPDATE hypercacheio_queues SET reserved_at = NULL WHERE reserved_at IS NOT NULL")

	log.Printf("Queue persistence: loaded %d active jobs, pruned %d stale jobs from SQLite", loaded, pruned)
	return loaded, pruned
}

// PruneQueueJobs deletes jobs older than olderThanSeconds from SQLite and in-memory queue.
func PruneQueueJobs(queueName string, olderThanSeconds int64) int {
	if db == nil {
		return 0
	}

	now := time.Now().Unix()
	cutoff := now - olderThanSeconds

	var query string
	var args []interface{}

	if queueName != "" {
		query = "DELETE FROM hypercacheio_queues WHERE queue_name = ? AND available_at < ?"
		args = []interface{}{queueName, cutoff}
	} else {
		query = "DELETE FROM hypercacheio_queues WHERE available_at < ?"
		args = []interface{}{cutoff}
	}

	res, err := db.Exec(query, args...)
	if err != nil {
		log.Printf("Queue DB PRUNE error: %v", err)
		return 0
	}

	count, _ := res.RowsAffected()
	log.Printf("Queue DB PRUNE: removed %d jobs older than %d seconds (queue: %s)", count, olderThanSeconds, queueName)
	return int(count)
}
