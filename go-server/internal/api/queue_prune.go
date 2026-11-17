package api

import (
	"encoding/json"
	"io"
	"net/http"
)

type QueuePrunePayload struct {
	Queue            string `json:"queue,omitempty"`
	OlderThanSeconds int64  `json:"older_than_seconds,omitempty"`
}

func HandleQueuePrune(w http.ResponseWriter, r *http.Request) {
	if r.Method != "POST" {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, _ := io.ReadAll(r.Body)
	var payload QueuePrunePayload
	if len(body) > 0 {
		json.Unmarshal(body, &payload)
	}

	if payload.OlderThanSeconds <= 0 {
		payload.OlderThanSeconds = 86400 * 7 // default: 7 days
	}

	pruned := PruneQueueJobs(payload.Queue, payload.OlderThanSeconds)

	writeJSON(w, map[string]interface{}{
		"success": true,
		"pruned":  pruned,
	})
}

