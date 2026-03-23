package main

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/yvasiyarov/php_session_decoder/php_serialize"
	_ "modernc.org/sqlite"
)

func setupTestDB(t *testing.T) (*sql.DB, func()) {
	// Use an in-memory SQLite database for testing
	tDB, err := sql.Open("sqlite", ":memory:")
	if err != nil {
		t.Fatalf("Failed to open test database: %v", err)
	}

	// Make sure the schema exists
	_, err = tDB.Exec(`
		CREATE TABLE IF NOT EXISTS cache(
			key TEXT PRIMARY KEY,
			value BLOB NOT NULL,
			expiration INTEGER
		);
		CREATE TABLE IF NOT EXISTS cache_locks(
			key TEXT PRIMARY KEY,
			owner TEXT NOT NULL,
			expiration INTEGER
		);
	`)
	if err != nil {
		t.Fatalf("Failed to initialize test schema: %v", err)
	}

	// Override the global db variable in main.go
	db = tDB
	cachePrefix = "test_prefix:"
	cache = newShardedCache()

	cleanup := func() {
		tDB.Close()
	}

	return tDB, cleanup
}

func TestHandleSqliteAddPutGet(t *testing.T) {
	_, cleanup := setupTestDB(t)
	defer cleanup()

	// 1. Test ADD
	ttl := 60
	payload := Payload{
		Value: "test_value_123",
		TTL:   &ttl,
	}
	body, _ := json.Marshal(payload)
	req1, _ := http.NewRequest("POST", "/api/hypercacheio/add/mykey", bytes.NewBuffer(body))
	rr1 := httptest.NewRecorder()

	handleAdd(rr1, req1)
	if status := rr1.Code; status != http.StatusOK {
		t.Errorf("handleAdd returned wrong status code: got %v want %v", status, http.StatusOK)
	}

	var addResp map[string]bool
	json.Unmarshal(rr1.Body.Bytes(), &addResp)
	if !addResp["added"] {
		t.Errorf("handleAdd failed to add new key")
	}

	// 2. Test GET
	req2, _ := http.NewRequest("GET", "/api/hypercacheio/cache/mykey", nil)
	rr2 := httptest.NewRecorder()

	handleCache(rr2, req2)
	if status := rr2.Code; status != http.StatusOK {
		t.Errorf("handleCache GET returned wrong status code: got %v want %v", status, http.StatusOK)
	}

	var getResp map[string]interface{}
	json.Unmarshal(rr2.Body.Bytes(), &getResp)
	if getResp["data"] != "test_value_123" {
		t.Errorf("handleCache GET returned wrong data: got %v want %v", getResp["data"], "test_value_123")
	}

	// 3. Test PUT (Update)
	payload.Value = "updated_value_456"
	body, _ = json.Marshal(payload)
	req3, _ := http.NewRequest("POST", "/api/hypercacheio/cache/mykey", bytes.NewBuffer(body))
	rr3 := httptest.NewRecorder()

	handleCache(rr3, req3)
	if status := rr3.Code; status != http.StatusOK {
		t.Errorf("handleCache PUT returned wrong status code: got %v want %v", status, http.StatusOK)
	}

	// 4. Verify PUT via another GET
	req4, _ := http.NewRequest("GET", "/api/hypercacheio/cache/mykey", nil)
	rr4 := httptest.NewRecorder()
	handleCache(rr4, req4)

	var getUpdateResp map[string]interface{}
	json.Unmarshal(rr4.Body.Bytes(), &getUpdateResp)
	if getUpdateResp["data"] != "updated_value_456" {
		t.Errorf("handleCache GET after PUT returned wrong data: got %v want %v", getUpdateResp["data"], "updated_value_456")
	}
}

func TestHandleSqliteDelete(t *testing.T) {
	_, cleanup := setupTestDB(t)
	defer cleanup()

	// Insert data directly
	key := cachePrefix + "delete_me"
	encoded, _ := php_serialize.Serialize("some_data")
	db.Exec("INSERT INTO cache(key, value) VALUES(?, ?)", key, encoded)

	// Test DELETE
	req, _ := http.NewRequest("DELETE", "/api/hypercacheio/cache/delete_me", nil)
	rr := httptest.NewRecorder()

	handleCache(rr, req)
	if status := rr.Code; status != http.StatusOK {
		t.Errorf("handleCache DELETE returned wrong status code: got %v want %v", status, http.StatusOK)
	}

	// Verify emptiness
	req2, _ := http.NewRequest("GET", "/api/hypercacheio/cache/delete_me", nil)
	rr2 := httptest.NewRecorder()
	handleCache(rr2, req2)

	var getResp map[string]interface{}
	json.Unmarshal(rr2.Body.Bytes(), &getResp)
	if getResp["data"] != nil {
		t.Errorf("handleCache GET returned data after deletion: got %v", getResp["data"])
	}
}

func TestDirectSqliteFlag(t *testing.T) {
	// Reset global state
	db = nil
	directSqlite = false
	sqlitePath = ":memory:"

	// When directSqlite is false, db should remain nil despite having a path
	if sqlitePath != "" && directSqlite {
		var err error
		db, err = sql.Open("sqlite", sqlitePath)
		if err != nil {
			t.Fatalf("Failed to open test database: %v", err)
		}
	}

	if db != nil {
		t.Errorf("DB was initialized even when directSqlite was false")
	}

	// When directSqlite is true
	directSqlite = true
	if sqlitePath != "" && directSqlite {
		var err error
		db, err = sql.Open("sqlite", sqlitePath)
		if err != nil {
			t.Fatalf("Failed to open test database: %v", err)
		}
	}

	if db == nil {
		t.Errorf("DB was NOT initialized when directSqlite was true")
	}
	db.Close()
}
func TestCleanupExpired(t *testing.T) {
	_, cleanup := setupTestDB(t)
	defer cleanup()

	now := time.Now().Unix()

	// Item 1: Not expired
	sh1 := cache.getShard("valid")
	sh1.mutex.Lock()
	sh1.items["valid"] = CacheItem{Value: []byte("value"), Expiration: now + 60}
	sh1.mutex.Unlock()

	// Item 2: Expired
	sh2 := cache.getShard("expired")
	sh2.mutex.Lock()
	sh2.items["expired"] = CacheItem{Value: []byte("value"), Expiration: now - 60}
	sh2.mutex.Unlock()

	// Item 3: No expiration
	sh3 := cache.getShard("permanent")
	sh3.mutex.Lock()
	sh3.items["permanent"] = CacheItem{Value: []byte("value"), Expiration: 0}
	sh3.mutex.Unlock()

	// Initial lookup to verify items exist
	count := 0
	for _, shard := range cache.shards {
		shard.mutex.RLock()
		count += len(shard.items)
		shard.mutex.RUnlock()
	}
	if count != 3 {
		t.Errorf("Expected 3 items, got %d", count)
	}

	cleanupExpired()

	count = 0
	for _, shard := range cache.shards {
		shard.mutex.RLock()
		count += len(shard.items)
		shard.mutex.RUnlock()
	}
	if count != 2 {
		t.Errorf("Expected 2 items after cleanup, got %d", count)
	}

	// Verify specific item
	shard := cache.getShard("expired")
	shard.mutex.RLock()
	if _, exists := shard.items["expired"]; exists {
		t.Errorf("Expired item still exists in cache")
	}
	shard.mutex.RUnlock()
}

func TestHandleItemsFiltering(t *testing.T) {
	_, cleanup := setupTestDB(t)
	defer cleanup()

	now := time.Now().Unix()

	sh1 := cache.getShard("valid")
	sh1.mutex.Lock()
	sh1.items["valid"] = CacheItem{Value: []byte("s:5:\"value\";"), Expiration: now + 60}
	sh1.mutex.Unlock()

	sh2 := cache.getShard("expired")
	sh2.mutex.Lock()
	sh2.items["expired"] = CacheItem{Value: []byte("s:5:\"value\";"), Expiration: now - 60}
	sh2.mutex.Unlock()

	req, _ := http.NewRequest("GET", "/api/hypercacheio/items", nil)
	rr := httptest.NewRecorder()

	handleItems(rr, req)

	if status := rr.Code; status != http.StatusOK {
		t.Errorf("handleItems returned wrong status code: got %v want %v", status, http.StatusOK)
	}

	var items []map[string]interface{}
	json.Unmarshal(rr.Body.Bytes(), &items)

	if len(items) != 1 {
		t.Errorf("Expected 1 item in response, got %d", len(items))
	}

	if items[0]["key"] != "valid" {
		t.Errorf("Expected key 'valid', got %v", items[0]["key"])
	}
}
