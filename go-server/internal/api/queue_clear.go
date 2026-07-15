package api

import (
	"encoding/json"
	"io"
	"net/http"

	"github.com/iperamuna/hypercacheio-server/internal/queue"
)

func HandleQueueClear(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, _ := io.ReadAll(r.Body)
	var payload QueuePayload
	json.Unmarshal(body, &payload)

	qm := queue.GetQueue(payload.Queue)
	deletedIds := qm.Clear()
	
	// Delete all these IDs from SQLite to ensure sync
	for _, id := range deletedIds {
		DeleteJob(id)
	}

	BroadcastQueueClear(payload.Queue)

	writeJSON(w, map[string]interface{}{
		"success": true,
		"cleared": len(deletedIds),
	})
}
