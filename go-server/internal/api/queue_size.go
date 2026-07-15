package api

import (
	"log"
	"net/http"
	"strings"

	"github.com/iperamuna/hypercacheio-server/internal/queue"
)

func HandleQueueSize(w http.ResponseWriter, r *http.Request) {
	if r.Method != "GET" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	parts := strings.Split(r.URL.Path, "/")
	queueName := parts[len(parts)-1]

	qm := queue.GetQueue(queueName)
	size := qm.GetSize()
	
	log.Printf("[API] QueueSize called for %s: size=%d", queueName, size)

	writeJSON(w, map[string]interface{}{
		"size": size,
	})
}
