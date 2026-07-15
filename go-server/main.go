package main

import (
	"bufio"
	"database/sql"
	"encoding/binary"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"runtime"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/iperamuna/hypercacheio-server/internal/api"
	"github.com/iperamuna/hypercacheio-server/internal/queue"
	"github.com/yvasiyarov/php_session_decoder/php_serialize"
	_ "modernc.org/sqlite"
)

// Protocol OpCodes
const (
	OpSet      byte = 1
	OpDel      byte = 2
	OpSyncReq  byte = 3
	OpSyncItem byte = 4
	OpSyncEnd  byte = 5
	OpFlush    byte = 6
	OpQPush    byte = 7
	OpQDel     byte = 8
	OpQClear   byte = 9
)

var (
	port         int
	host         string
	apiToken     string
	sslEnabled   bool
	sslCert      string
	sslKey       string
	artisanPath  string
	sqlitePath   string
	cachePrefix  string
	directSqlite bool
	haMode       bool
	peerAddrs    string
	replPort     int
	unixSocket   string

	db *sql.DB

	// Sharded In-memory cache
	cache        = newShardedCache()
	
	// DB Persistence Channel
	dbChan       = make(chan dbJob, 5000)

	// Peer connections
	peers      = make(map[string]net.Conn)
	peersMutex sync.Mutex

	// Stats
	stats      Stats
	statsMutex sync.Mutex
)

const NumShards = 32

type ShardedCache struct {
	shards [NumShards]*Shard
}

type Shard struct {
	items map[string]CacheItem
	mutex sync.RWMutex
}

func newShardedCache() *ShardedCache {
	sc := &ShardedCache{}
	for i := 0; i < NumShards; i++ {
		sc.shards[i] = &Shard{items: make(map[string]CacheItem)}
	}
	return sc
}

func (sc *ShardedCache) getShard(key string) *Shard {
	var sum uint32
	for _, b := range key {
		sum = (sum * 31) + uint32(b)
	}
	return sc.shards[sum%NumShards]
}

var dbWorkerWg sync.WaitGroup

type dbJob struct {
	op  string
	key string
	val []byte
	exp int64
}

type Stats struct {
	TotalBroadcasts uint64 `json:"total_broadcasts"`
	TotalReceived   uint64 `json:"total_received"`
	SyncRequests    uint64 `json:"sync_requests_received"`
}

type CacheItem struct {
	Value      []byte
	Expiration int64 // Unix timestamp, 0 for forever
}

type Payload struct {
	Value interface{} `json:"value"`
	TTL   *int        `json:"ttl"`
	Owner string      `json:"owner"`
}

func main() {
	// 1. Define flags
	flag.IntVar(&port, "port", 8080, "Port for HTTP API (Laravel)")
	flag.StringVar(&host, "host", "127.0.0.1", "Host for HTTP API")
	flag.StringVar(&apiToken, "token", "", "API Token for authentication")
	flag.BoolVar(&sslEnabled, "ssl", false, "Enable SSL for HTTP API")
	flag.StringVar(&sslCert, "cert", "", "SSL Certificate path")
	flag.StringVar(&sslKey, "key", "", "SSL Key path")
	flag.StringVar(&artisanPath, "artisan", "php artisan", "Path to artisan command")
	flag.StringVar(&sqlitePath, "sqlite-path", "", "Path to SQLite database (optional persistence)")
	flag.StringVar(&cachePrefix, "prefix", "", "Cache prefix")
	flag.BoolVar(&directSqlite, "direct-sqlite", true, "Use internal caching logic")
	flag.BoolVar(&haMode, "ha-mode", true, "Enable HA mode")
	flag.StringVar(&peerAddrs, "peers", "", "Comma-separated list of peer addresses (host:port) for TCP replication")
	flag.IntVar(&replPort, "repl-port", 7400, "Port to listen for incoming replication")
	flag.StringVar(&unixSocket, "unix-socket", "", "Unix socket path for local HTTP API")
	flag.Parse()

	// 2. Fallback to environment variables if flags are not set
	if apiToken == "" {
		apiToken = os.Getenv("HYPERCACHEIO_API_TOKEN")
	}
	if sqlitePath == "" {
		sqlitePath = os.Getenv("HYPERCACHEIO_SQLITE_PATH")
	}
	if cachePrefix == "" {
		cachePrefix = os.Getenv("HYPERCACHEIO_CACHE_PREFIX")
	}
	if peerAddrs == "" {
		peerAddrs = os.Getenv("HYPERCACHEIO_PEER_ADDRS")
	}
	if os.Getenv("HYPERCACHEIO_HA_ENABLED") != "" {
		haMode = os.Getenv("HYPERCACHEIO_HA_ENABLED") == "true"
	}
	if port == 8080 && os.Getenv("HYPERCACHEIO_GO_PORT") != "" {
		fmt.Sscanf(os.Getenv("HYPERCACHEIO_GO_PORT"), "%d", &port)
	}
	if host == "127.0.0.1" && os.Getenv("HYPERCACHEIO_GO_HOST") != "" {
		host = os.Getenv("HYPERCACHEIO_GO_HOST")
	}

	if apiToken == "" {
		log.Fatal("API Token is required (via --token flag or HYPERCACHEIO_API_TOKEN environment variable)")
	}

	// Initialize SQLite if provided (optional persistence)
	if sqlitePath != "" && directSqlite {
		var err error
		// Enable WAL mode and synchronous=NORMAL for much faster writes
		connStr := sqlitePath
		if !strings.Contains(connStr, "?") {
			connStr += "?_pragma=journal_mode=WAL&_pragma=synchronous=NORMAL&_pragma=cache_size=-2000"
		}
		db, err = sql.Open("sqlite", connStr)
		if err != nil {
			log.Fatalf("Failed to open SQLite database: %s", err)
		}
		db.SetMaxOpenConns(25) // Increase for better parallel persistence
		if err := initSqlite(); err != nil {
			log.Fatalf("Failed to initialize SQLite schema: %s", err)
		}
		log.Printf("SQLite persistence enabled (WAL mode): %s", sqlitePath)
		
		dbWorkerWg.Add(2)
		
		// Start Async DB Worker
		go startDbWorker()
		
		api.SetDatabase(db)
		go api.StartQueueDbWorker(&dbWorkerWg)
		
		loadFromSqlite()
	}

	// Start replication listener and connect to peers if HA mode is enabled
	if haMode {
		go startReplicationListener()

		if peerAddrs != "" {
			for _, addr := range strings.Split(peerAddrs, ",") {
				addr = strings.TrimSpace(addr)
				if addr != "" {
					go maintainPeerConnection(addr)
				}
			}
		}
	}
	
	// Start background cleanup for expired items
	startCleanupTimer()
	
	// Start background queue maintenance
	queue.StartMaintenanceTicker(1 * time.Second)

	// Start HTTP API for Laravel
	mux := http.NewServeMux()
	mux.HandleFunc("/api/hypercacheio/cache/", handleCache)
	mux.HandleFunc("/api/hypercacheio/add/", handleAdd)
	mux.HandleFunc("/api/hypercacheio/touch/", handleTouch)
	mux.HandleFunc("/api/hypercacheio/lock/", handleLock)
	mux.HandleFunc("/api/hypercacheio/ping", handlePing)
	mux.HandleFunc("/api/hypercacheio/items", handleItems)
	
	// Queue Endpoints
	mux.HandleFunc("/api/hypercacheio/queue/push", api.HandleQueuePush)
	mux.HandleFunc("/api/hypercacheio/queue/pop", api.HandleQueuePop)
	mux.HandleFunc("/api/hypercacheio/queue/delete", api.HandleQueueDelete)
	mux.HandleFunc("/api/hypercacheio/queue/release", api.HandleQueueRelease)
	mux.HandleFunc("/api/hypercacheio/queue/clear", api.HandleQueueClear)
	mux.HandleFunc("/api/hypercacheio/queue/size/", api.HandleQueueSize)

	handler := authMiddleware(mux)

	if unixSocket != "" {
		os.Remove(unixSocket) // Clean up if exists
		unixListener, err := net.Listen("unix", unixSocket)
		if err != nil {
			log.Fatalf("Failed to listen on unix socket %s: %v", unixSocket, err)
		}
		os.Chmod(unixSocket, 0666) // allow anyone to connect to it locally
		go func() {
			log.Printf("Starting Hypercacheio HTTP API on Unix Socket %s", unixSocket)
			if err := http.Serve(unixListener, handler); err != nil && err != http.ErrServerClosed {
				log.Fatalf("Unix socket server failed: %s", err)
			}
		}()
	}

	serverAddr := fmt.Sprintf("%s:%d", host, port)
	log.Printf("Starting Hypercacheio HTTP API on %s", serverAddr)

	go func() {
		if sslEnabled {
			if sslCert == "" || sslKey == "" {
				log.Fatal("SSL Certificate and Key are required when SSL is enabled")
			}
			if err := http.ListenAndServeTLS(serverAddr, sslCert, sslKey, handler); err != nil && err != http.ErrServerClosed {
				log.Fatalf("Server failed: %s", err)
			}
		} else {
			if err := http.ListenAndServe(serverAddr, handler); err != nil && err != http.ErrServerClosed {
				log.Fatalf("Server failed: %s", err)
			}
		}
	}()

	// Wait for interrupt signal to gracefully shutdown the server
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, os.Interrupt, syscall.SIGTERM)
	<-quit
	log.Println("Shutting down server gracefully...")

	// Close database channels and wait for workers to finish
	if sqlitePath != "" && directSqlite {
		log.Println("Flushing async channels to SQLite...")
		close(dbChan)
		api.ShutdownQueueDbWorker()
		dbWorkerWg.Wait()
		db.Close()
		log.Println("SQLite channels flushed and database closed.")
	}
	
	log.Println("Server stopped")
}

// -------------------------------------------------------------
// Async Persistence Worker
// -------------------------------------------------------------

func startDbWorker() {
	defer dbWorkerWg.Done()
	log.Printf("Starting background persistence worker...")
	for job := range dbChan {
		switch job.op {
		case "SET":
			var exp interface{}
			if job.exp > 0 {
				exp = job.exp
			}
			_, err := db.Exec("REPLACE INTO cache(key, value, expiration) VALUES(?, ?, ?)", job.key, job.val, exp)
			if err != nil {
				log.Printf("DB SET error: %v", err)
			}
		case "DEL":
			_, err := db.Exec("DELETE FROM cache WHERE key = ?", job.key)
			if err != nil {
				log.Printf("DB DEL error: %v", err)
			}
		case "FLUSH":
			_, err := db.Exec("DELETE FROM cache")
			if err != nil {
				log.Printf("DB FLUSH error: %v", err)
			}
		case "CLEANUP":
			_, err := db.Exec("DELETE FROM cache WHERE expiration > 0 AND expiration < ?", job.exp)
			if err != nil {
				log.Printf("DB CLEANUP error: %v", err)
			}
		}
	}
}

// -------------------------------------------------------------
// Replication Logic (TCP)
// -------------------------------------------------------------

func startReplicationListener() {
	addr := fmt.Sprintf("0.0.0.0:%d", replPort)
	l, err := net.Listen("tcp", addr)
	if err != nil {
		log.Fatalf("Failed to start replication listener: %v", err)
	}
	defer l.Close()
	log.Printf("Replication listener started on %s", addr)

	for {
		conn, err := l.Accept()
		if err != nil {
			log.Printf("Accept error: %v", err)
			continue
		}
		go handleReplicationConn(conn)
	}
}

func handleReplicationConn(conn net.Conn) {
	defer conn.Close()
	reader := bufio.NewReader(conn)

	for {
		op, err := reader.ReadByte()
		if err != nil {
			if err != io.EOF {
				log.Printf("Replication read error from %s: %v", conn.RemoteAddr(), err)
			}
			return
		}

		statsMutex.Lock()
		stats.TotalReceived++
		statsMutex.Unlock()

		switch op {
		case OpSet:
			key, val, exp, err := readSetFrame(reader)
			if err != nil {
				log.Printf("Failed to read SET frame: %v", err)
				return
			}
			setLocal(key, val, exp, false)
		case OpDel:
			key, err := readDelFrame(reader)
			if err != nil {
				log.Printf("Failed to read DEL frame: %v", err)
				return
			}
			delLocal(key, false)
		case OpQPush:
			jobID, qName, payload, avail, err := readQPushFrame(reader)
			if err == nil {
				qm := queue.GetQueue(qName)
				job := &queue.Job{ID: jobID, Payload: payload}
				qm.Push(job, 0)
                // We actually need to set the internal job.AvailableAt directly because qm.Push takes delay, but we already have absolute timestamp.
                // Wait, Push takes `delay`. If we pass `delay`, it calculates `AvailableAt`.
                // If we want exact replica, we should inject the job directly, but let's just re-calculate delay.
                // Delay = avail - now.
                now := time.Now().Unix()
                delay := avail - now
                if delay < 0 { delay = 0 }
                qm.Push(job, delay)
			}
		case OpQDel:
			jobID, qName, err := readQDelFrame(reader)
			if err == nil {
				qm := queue.GetQueue(qName)
				qm.Delete(jobID)
			}
		case OpQClear:
			qName, err := readQClearFrame(reader)
			if err == nil {
				qm := queue.GetQueue(qName)
				qm.Clear()
			}
		case OpFlush:
			log.Printf("Received FLUSH from peer")
			flushLocal(false)
		case OpSyncReq:
			log.Printf("Received SYNC request from peer %s", conn.RemoteAddr())
			statsMutex.Lock()
			stats.SyncRequests++
			statsMutex.Unlock()
			sendFullDump(conn)
		}
	}
}

func maintainPeerConnection(addr string) {
	for {
		conn, err := net.DialTimeout("tcp", addr, 5*time.Second)
		if err != nil {
			log.Printf("Failed to connect to peer %s: %v. Retrying in 5s...", addr, err)
			time.Sleep(5 * time.Second)
			continue
		}

		log.Printf("Connected to peer %s. Initiating sync...", addr)

		peersMutex.Lock()
		peers[addr] = conn
		peersMutex.Unlock()

		// Request sync
		sendSyncRequest(conn)

		// Handle incoming messages from peer
		handlePeerResponses(conn)

		peersMutex.Lock()
		delete(peers, addr)
		peersMutex.Unlock()

		log.Printf("Connection to peer %s lost. Retrying in 5s...", addr)
		time.Sleep(5 * time.Second)
	}
}

func handlePeerResponses(conn net.Conn) {
	reader := bufio.NewReader(conn)
	for {
		op, err := reader.ReadByte()
		if err != nil {
			return
		}

		statsMutex.Lock()
		stats.TotalReceived++
		statsMutex.Unlock()

		switch op {
		case OpSyncItem:
			key, val, exp, err := readSetFrame(reader)
			if err == nil {
				setLocal(key, val, exp, false)
			}
		case OpSyncEnd:
			log.Printf("Bootstrap sync completed from %s", conn.RemoteAddr())
		case OpSet:
			key, val, exp, err := readSetFrame(reader)
			if err == nil {
				setLocal(key, val, exp, false)
			}
		case OpDel:
			key, err := readDelFrame(reader)
			if err == nil {
				delLocal(key, false)
			}
		case OpQPush:
			jobID, qName, payload, avail, err := readQPushFrame(reader)
			if err == nil {
				qm := queue.GetQueue(qName)
				job := &queue.Job{ID: jobID, Payload: payload}
				now := time.Now().Unix()
				delay := avail - now
				if delay < 0 { delay = 0 }
				qm.Push(job, delay)
			}
		case OpQDel:
			jobID, qName, err := readQDelFrame(reader)
			if err == nil {
				qm := queue.GetQueue(qName)
				qm.Delete(jobID)
			}
		case OpQClear:
			qName, err := readQClearFrame(reader)
			if err == nil {
				qm := queue.GetQueue(qName)
				qm.Clear()
			}
		case OpFlush:
			flushLocal(false)
		}
	}
}

func sendSyncRequest(conn net.Conn) {
	conn.Write([]byte{OpSyncReq})
}

func sendFullDump(conn net.Conn) {
	log.Printf("Sending full dump from all shards to %s", conn.RemoteAddr())
	count := 0
	for i := 0; i < NumShards; i++ {
		shard := cache.shards[i]
		shard.mutex.RLock()
		for k, v := range shard.items {
			if v.Expiration > 0 && v.Expiration < time.Now().Unix() {
				continue
			}
			writeSetFrame(conn, OpSyncItem, k, v.Value, v.Expiration)
			count++
		}
		shard.mutex.RUnlock()
	}
	conn.Write([]byte{OpSyncEnd})
	log.Printf("Sent full dump (%d items) to %s", count, conn.RemoteAddr())
}

func broadcastSet(key string, val []byte, expiration int64) {
	peersMutex.Lock()
	defer peersMutex.Unlock()
	
	for addr, conn := range peers {
		// Spawn a goroutine per peer to fan-out concurrently!
		go func(peerAddr string, c net.Conn) {
			err := writeSetFrame(c, OpSet, key, val, expiration)
			if err != nil {
				log.Printf("Failed to broadcast SET to %s: %v", peerAddr, err)
			} else {
				statsMutex.Lock()
				stats.TotalBroadcasts++
				statsMutex.Unlock()
			}
		}(addr, conn)
	}
}

func broadcastDel(key string) {
	peersMutex.Lock()
	defer peersMutex.Unlock()
	
	for addr, conn := range peers {
		go func(peerAddr string, c net.Conn) {
			err := writeDelFrame(c, key)
			if err != nil {
				log.Printf("Failed to broadcast DEL to %s: %v", peerAddr, err)
			} else {
				statsMutex.Lock()
				stats.TotalBroadcasts++
				statsMutex.Unlock()
			}
		}(addr, conn)
	}
}

func broadcastFlush() {
	peersMutex.Lock()
	defer peersMutex.Unlock()
	
	for addr, conn := range peers {
		go func(peerAddr string, c net.Conn) {
			_, err := c.Write([]byte{OpFlush})
			if err != nil {
				log.Printf("Failed to broadcast FLUSH to %s: %v", peerAddr, err)
			} else {
				statsMutex.Lock()
				stats.TotalBroadcasts++
				statsMutex.Unlock()
			}
		}(addr, conn)
	}
}

// -------------------------------------------------------------
// Frame Encoding/Decoding
// -------------------------------------------------------------

func writeSetFrame(w io.Writer, op byte, key string, val []byte, exp int64) error {
	keyBytes := []byte(key)
	
	// Create a single buffer to prevent interleaving on concurrent writes
	totalLen := 11 + len(keyBytes) + len(val)
	buffer := make([]byte, totalLen)
	
	buffer[0] = op
	binary.BigEndian.PutUint16(buffer[1:3], uint16(len(keyBytes)))
	binary.BigEndian.PutUint32(buffer[3:7], uint32(len(val)))
	binary.BigEndian.PutUint32(buffer[7:11], uint32(exp))
	
	copy(buffer[11:], keyBytes)
	copy(buffer[11+len(keyBytes):], val)

	_, err := w.Write(buffer)
	return err
}

func readSetFrame(r *bufio.Reader) (string, []byte, int64, error) {
	header := make([]byte, 10) // We already read the Op byte
	if _, err := io.ReadFull(r, header); err != nil {
		return "", nil, 0, err
	}
	keyLen := binary.BigEndian.Uint16(header[0:2])
	valLen := binary.BigEndian.Uint32(header[2:6])
	exp := int64(binary.BigEndian.Uint32(header[6:10]))

	keyBytes := make([]byte, keyLen)
	if _, err := io.ReadFull(r, keyBytes); err != nil {
		return "", nil, 0, err
	}
	val := make([]byte, valLen)
	if _, err := io.ReadFull(r, val); err != nil {
		return "", nil, 0, err
	}
	return string(keyBytes), val, exp, nil
}

func writeDelFrame(w io.Writer, key string) error {
	keyBytes := []byte(key)
	
	// Create a single buffer to prevent interleaving
	buffer := make([]byte, 3+len(keyBytes))
	buffer[0] = OpDel
	binary.BigEndian.PutUint16(buffer[1:3], uint16(len(keyBytes)))
	copy(buffer[3:], keyBytes)

	_, err := w.Write(buffer)
	return err
}

func readDelFrame(r *bufio.Reader) (string, error) {
	header := make([]byte, 2)
	if _, err := io.ReadFull(r, header); err != nil {
		return "", err
	}
	keyLen := binary.BigEndian.Uint16(header[0:2])
	keyBytes := make([]byte, keyLen)
	if _, err := io.ReadFull(r, keyBytes); err != nil {
		return "", err
	}
	return string(keyBytes), nil
}

// -------------------------------------------------------------
// Core Cache Operations
// -------------------------------------------------------------

func setLocal(key string, val []byte, expiration int64, broadcast bool) {
	shard := cache.getShard(key)
	shard.mutex.Lock()
	shard.items[key] = CacheItem{Value: val, Expiration: expiration}
	shard.mutex.Unlock()

	if db != nil {
		select {
		case dbChan <- dbJob{op: "SET", key: key, val: val, exp: expiration}:
		default:
			log.Printf("DB channel full, dropping persistence for key: %s", key)
		}
	}

	if broadcast {
		go broadcastSet(key, val, expiration)
	}
}

func getLocal(key string) ([]byte, bool) {
	shard := cache.getShard(key)
	shard.mutex.RLock()
	item, ok := shard.items[key]
	shard.mutex.RUnlock()

	if !ok {
		return nil, false
	}
	if item.Expiration > 0 && item.Expiration < time.Now().Unix() {
		delLocal(key, true)
		return nil, false
	}
	return item.Value, true
}

func delLocal(key string, broadcast bool) {
	shard := cache.getShard(key)
	shard.mutex.Lock()
	delete(shard.items, key)
	shard.mutex.Unlock()

	if db != nil {
		select {
		case dbChan <- dbJob{op: "DEL", key: key}:
		default:
			log.Printf("DB channel full, dropping delete for key: %s", key)
		}
	}

	if broadcast {
		go broadcastDel(key)
	}
}

func flushLocal(broadcast bool) {
	for i := 0; i < NumShards; i++ {
		shard := cache.shards[i]
		shard.mutex.Lock()
		shard.items = make(map[string]CacheItem)
		shard.mutex.Unlock()
	}

	if db != nil {
		dbChan <- dbJob{op: "FLUSH"}
	}

	if broadcast {
		go broadcastFlush()
	}
}

func loadFromSqlite() {
	if db == nil {
		return
	}
	rows, err := db.Query("SELECT key, value, expiration FROM cache")
	if err != nil {
		log.Printf("Failed to load from SQLite: %v", err)
		return
	}
	defer rows.Close()

	count := 0
	now := time.Now().Unix()
	for rows.Next() {
		var k string
		var v []byte
		var exp sql.NullInt64
		if err := rows.Scan(&k, &v, &exp); err == nil {
			expiration := int64(0)
			if exp.Valid {
				expiration = exp.Int64
			}
			if expiration == 0 || expiration > now {
				shard := cache.getShard(k)
				shard.mutex.Lock()
				shard.items[k] = CacheItem{Value: v, Expiration: expiration}
				shard.mutex.Unlock()
				count++
			}
		}
	}
	log.Printf("Loaded %d items from SQLite persistence into shards", count)
}

func startCleanupTimer() {
	ticker := time.NewTicker(30 * time.Second)
	go func() {
		for range ticker.C {
			cleanupExpired()
		}
	}()
}

func cleanupExpired() {
	now := time.Now().Unix()
	totalCount := 0
	
	for i := 0; i < NumShards; i++ {
		shard := cache.shards[i]
		shard.mutex.Lock()
		for k, v := range shard.items {
			if v.Expiration > 0 && v.Expiration < now {
				delete(shard.items, k)
				totalCount++
			}
		}
		shard.mutex.Unlock()
	}

	if totalCount > 0 {
		log.Printf("Background cleanup: removed %d expired items across all shards", totalCount)
		if db != nil {
			select {
			case dbChan <- dbJob{op: "CLEANUP", exp: now}:
			default:
			}
		}
	}
}


// -------------------------------------------------------------
// HTTP Handlers (for Laravel)
// -------------------------------------------------------------

func authMiddleware(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token := r.Header.Get("X-Hypercacheio-Token")
		if token != apiToken {
			http.Error(w, `{"error": "Unauthorized"}`, http.StatusUnauthorized)
			return
		}
		next.ServeHTTP(w, r)
	})
}

func handleCache(w http.ResponseWriter, r *http.Request) {
	key := strings.TrimPrefix(r.URL.Path, "/api/hypercacheio/cache/")

	switch r.Method {
	case "GET":
		if key == "" {
			http.Error(w, "Key required", http.StatusBadRequest)
			return
		}
		val, ok := getLocal(key)
		if !ok {
			writeJSON(w, map[string]interface{}{"data": nil})
			return
		}

		decoder := php_serialize.NewUnSerializer(string(val))
		parsed, err := decoder.Decode()
		if err != nil {
			writeJSON(w, map[string]interface{}{"data": nil})
			return
		}
		writeJSON(w, map[string]interface{}{"data": parsed})

	case "POST":
		body, _ := io.ReadAll(r.Body)
		var payload Payload
		if err := json.Unmarshal(body, &payload); err != nil {
			http.Error(w, "Invalid payload", http.StatusBadRequest)
			return
		}

		var expiration int64
		if payload.TTL != nil && *payload.TTL > 0 {
			expiration = time.Now().Unix() + int64(*payload.TTL)
		}

		encoded, err := php_serialize.Serialize(payload.Value)
		if err != nil {
			http.Error(w, "Serialization failed", http.StatusInternalServerError)
			return
		}

		setLocal(key, []byte(encoded), expiration, true)
		writeJSON(w, map[string]bool{"success": true})

	case "DELETE":
		if key == "" {
			flushLocal(true)
			writeJSON(w, map[string]bool{"success": true})
		} else {
			delLocal(key, true)
			writeJSON(w, map[string]bool{"success": true})
		}
	}
}

func handleAdd(w http.ResponseWriter, r *http.Request) {
	key := strings.TrimPrefix(r.URL.Path, "/api/hypercacheio/add/")
	body, _ := io.ReadAll(r.Body)
	var payload Payload
	json.Unmarshal(body, &payload)

	shard := cache.getShard(key)
	shard.mutex.Lock()
	item, ok := shard.items[key]
	exists := ok && (item.Expiration == 0 || item.Expiration > time.Now().Unix())

	if exists {
		shard.mutex.Unlock()
		writeJSON(w, map[string]bool{"added": false})
		return
	}

	var expiration int64
	if payload.TTL != nil && *payload.TTL > 0 {
		expiration = time.Now().Unix() + int64(*payload.TTL)
	}

	encoded, _ := php_serialize.Serialize(payload.Value)
	shard.items[key] = CacheItem{Value: []byte(encoded), Expiration: expiration}
	shard.mutex.Unlock()

	// Persistence and Broadcast
	if db != nil {
		select {
		case dbChan <- dbJob{op: "SET", key: key, val: []byte(encoded), exp: expiration}:
		default:
		}
	}
	broadcastSet(key, []byte(encoded), expiration)

	writeJSON(w, map[string]bool{"added": true})
}

func handleTouch(w http.ResponseWriter, r *http.Request) {
	key := strings.TrimPrefix(r.URL.Path, "/api/hypercacheio/touch/")
	body, _ := io.ReadAll(r.Body)
	var payload Payload
	json.Unmarshal(body, &payload)

	if payload.TTL == nil {
		http.Error(w, "TTL required", http.StatusBadRequest)
		return
	}

	shard := cache.getShard(key)
	shard.mutex.Lock()
	item, ok := shard.items[key]
	if !ok || (item.Expiration > 0 && item.Expiration < time.Now().Unix()) {
		shard.mutex.Unlock()
		writeJSON(w, map[string]bool{"touched": false})
		return
	}

	var expiration int64
	if *payload.TTL > 0 {
		expiration = time.Now().Unix() + int64(*payload.TTL)
	}
	item.Expiration = expiration
	shard.items[key] = item
	shard.mutex.Unlock()

	// Persistence and Broadcast
	if db != nil {
		select {
		case dbChan <- dbJob{op: "SET", key: key, val: item.Value, exp: expiration}:
		default:
		}
	}
	broadcastSet(key, item.Value, expiration)

	writeJSON(w, map[string]bool{"touched": true})
}

func handleLock(w http.ResponseWriter, r *http.Request) {
	key := "lock:" + strings.TrimPrefix(r.URL.Path, "/api/hypercacheio/lock/")

	switch r.Method {
	case "POST":
		body, _ := io.ReadAll(r.Body)
		var payload Payload
		json.Unmarshal(body, &payload)

		shard := cache.getShard(key)
		shard.mutex.Lock()
		item, exists := shard.items[key]
		if exists && (item.Expiration == 0 || item.Expiration > time.Now().Unix()) {
			if string(item.Value) == payload.Owner {
				shard.mutex.Unlock()
				writeJSON(w, map[string]bool{"acquired": true})
				return
			}
			shard.mutex.Unlock()
			writeJSON(w, map[string]bool{"acquired": false})
			return
		}

		var expiration int64
		if payload.TTL != nil && *payload.TTL > 0 {
			expiration = time.Now().Unix() + int64(*payload.TTL)
		}
		shard.items[key] = CacheItem{Value: []byte(payload.Owner), Expiration: expiration}
		shard.mutex.Unlock()

		go broadcastSet(key, []byte(payload.Owner), expiration)
		writeJSON(w, map[string]bool{"acquired": true})

	case "DELETE":
		body, _ := io.ReadAll(r.Body)
		var payload Payload
		json.Unmarshal(body, &payload)

		shard := cache.getShard(key)
		shard.mutex.Lock()
		item, exists := shard.items[key]
		if exists && string(item.Value) == payload.Owner {
			delete(shard.items, key)
			shard.mutex.Unlock()
			go broadcastDel(key)
			writeJSON(w, map[string]bool{"released": true})
			return
		}
		shard.mutex.Unlock()
		writeJSON(w, map[string]bool{"released": false})
	}
}

func handlePing(w http.ResponseWriter, r *http.Request) {
	hostName, err := os.Hostname()
	if err != nil {
		hostName = "unknown"
	}

	peersMutex.Lock()
	peerMap := make(map[string]bool)
	for addr, conn := range peers {
		peerMap[addr] = conn != nil
	}
	peersMutex.Unlock()

	statsMutex.Lock()
	currentStats := stats
	statsMutex.Unlock()

	totalCacheItems := 0
	totalLocks := 0
	var cacheBytes int64 = 0
	var lockBytes int64 = 0
	
	for i := 0; i < NumShards; i++ {
		shard := cache.shards[i]
		shard.mutex.RLock()
		for k, item := range shard.items {
			size := int64(len(k) + len(item.Value))
			if strings.HasPrefix(k, "lock:") {
				totalLocks++
				lockBytes += size
			} else {
				totalCacheItems++
				cacheBytes += size
			}
		}
		shard.mutex.RUnlock()
	}
	
	queueStats := queue.GetAllQueueStats()

	var queueBytes int64 = 0
	for _, q := range queueStats {
		queueBytes += q.PayloadBytes
	}

	// Memory Stats
	var m runtime.MemStats
	runtime.ReadMemStats(&m)

	// Disk Usage
	var diskUsage int64 = 0
	var cacheDiskUsage int64 = 0
	var queueDiskUsage int64 = 0
	if sqlitePath != "" && directSqlite {
		if stat, err := os.Stat(sqlitePath); err == nil {
			diskUsage = stat.Size()
			
			// Approximate split by payload sizes
			totalPayload := cacheBytes + lockBytes + queueBytes
			if totalPayload > 0 {
				cacheRatio := float64(cacheBytes+lockBytes) / float64(totalPayload)
				queueRatio := float64(queueBytes) / float64(totalPayload)
				cacheDiskUsage = int64(float64(diskUsage) * cacheRatio)
				queueDiskUsage = int64(float64(diskUsage) * queueRatio)
			} else {
				cacheDiskUsage = diskUsage
			}
		}
	}

	writeJSON(w, map[string]interface{}{
		"message":          "pong",
		"role":             "go-server",
		"hostname":         hostName,
		"time":             time.Now().Unix(),
		"peers":            peerMap,
		"items_count":      totalCacheItems,
		"locks_count":      totalLocks,
		"cache_bytes":      cacheBytes,
		"lock_bytes":       lockBytes,
		"queue_bytes":      queueBytes,
		"queues":           queueStats,
		"heap_memory":      m.Alloc,
		"process_memory":   m.Sys,
		"disk_usage_total": diskUsage,
		"disk_usage_cache": cacheDiskUsage,
		"disk_usage_queue": queueDiskUsage,
		"ha_mode":          haMode,
		"replication_port": replPort,
		"stats":            currentStats,
	})
}

func handleItems(w http.ResponseWriter, r *http.Request) {
	type Item struct {
		Key        string      `json:"key"`
		Value      interface{} `json:"value"`
		Expiration int64       `json:"expiration"`
		IsLock     bool        `json:"is_lock"`
	}

	items := make([]Item, 0)
	now := time.Now().Unix()
	
	for i := 0; i < NumShards; i++ {
		shard := cache.shards[i]
		shard.mutex.RLock()
		for k, v := range shard.items {
			if v.Expiration > 0 && v.Expiration < now {
				continue
			}
			
			isLock := strings.HasPrefix(k, "lock:")
			var parsedValue interface{}
			if isLock {
				parsedValue = string(v.Value)
			} else {
				decoder := php_serialize.NewUnSerializer(string(v.Value))
				parsed, err := decoder.Decode()
				if err == nil {
					parsedValue = parsed
				} else {
					parsedValue = "[Binary Data]"
				}
			}

			items = append(items, Item{
				Key:        k,
				Value:      parsedValue,
				Expiration: v.Expiration,
				IsLock:     isLock,
			})
		}
		shard.mutex.RUnlock()
	}

	writeJSON(w, items)
}

func initSqlite() error {
	_, err := db.Exec(`
		CREATE TABLE IF NOT EXISTS cache(
			key TEXT PRIMARY KEY,
			value BLOB NOT NULL,
			expiration INTEGER
		);
	`)
	return err
}

func writeJSON(w http.ResponseWriter, data interface{}) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(data)
}


func writeQPushFrame(w io.Writer, jobID, qName, payload string, avail int64) error {
	jBytes, qBytes, pBytes := []byte(jobID), []byte(qName), []byte(payload)
	totalLen := 17 + len(jBytes) + len(qBytes) + len(pBytes)
	buffer := make([]byte, totalLen)
	
	buffer[0] = OpQPush
	binary.BigEndian.PutUint16(buffer[1:3], uint16(len(jBytes)))
	binary.BigEndian.PutUint16(buffer[3:5], uint16(len(qBytes)))
	binary.BigEndian.PutUint32(buffer[5:9], uint32(len(pBytes)))
	binary.BigEndian.PutUint64(buffer[9:17], uint64(avail))
	
	idx := 17
	copy(buffer[idx:], jBytes); idx += len(jBytes)
	copy(buffer[idx:], qBytes); idx += len(qBytes)
	copy(buffer[idx:], pBytes)
	
	_, err := w.Write(buffer)
	return err
}

func readQPushFrame(r *bufio.Reader) (string, string, string, int64, error) {
	header := make([]byte, 16)
	if _, err := io.ReadFull(r, header); err != nil {
		return "", "", "", 0, err
	}
	jLen := binary.BigEndian.Uint16(header[0:2])
	qLen := binary.BigEndian.Uint16(header[2:4])
	pLen := binary.BigEndian.Uint32(header[4:8])
	avail := int64(binary.BigEndian.Uint64(header[8:16]))
	
	body := make([]byte, uint32(jLen)+uint32(qLen)+pLen)
	if _, err := io.ReadFull(r, body); err != nil {
		return "", "", "", 0, err
	}
	
	jobID := string(body[:jLen])
	qName := string(body[jLen : jLen+qLen])
	payload := string(body[jLen+qLen:])
	return jobID, qName, payload, avail, nil
}

func writeQDelFrame(w io.Writer, jobID, qName string) error {
	jBytes, qBytes := []byte(jobID), []byte(qName)
	buffer := make([]byte, 5+len(jBytes)+len(qBytes))
	buffer[0] = OpQDel
	binary.BigEndian.PutUint16(buffer[1:3], uint16(len(jBytes)))
	binary.BigEndian.PutUint16(buffer[3:5], uint16(len(qBytes)))
	
	copy(buffer[5:], jBytes)
	copy(buffer[5+len(jBytes):], qBytes)
	_, err := w.Write(buffer)
	return err
}

func readQDelFrame(r *bufio.Reader) (string, string, error) {
	header := make([]byte, 4)
	if _, err := io.ReadFull(r, header); err != nil {
		return "", "", err
	}
	jLen := binary.BigEndian.Uint16(header[0:2])
	qLen := binary.BigEndian.Uint16(header[2:4])
	
	body := make([]byte, jLen+qLen)
	if _, err := io.ReadFull(r, body); err != nil {
		return "", "", err
	}
	return string(body[:jLen]), string(body[jLen:]), nil
}

func writeQClearFrame(w io.Writer, qName string) error {
	qBytes := []byte(qName)
	buffer := make([]byte, 3+len(qBytes))
	buffer[0] = OpQClear
	binary.BigEndian.PutUint16(buffer[1:3], uint16(len(qBytes)))
	copy(buffer[3:], qBytes)
	_, err := w.Write(buffer)
	return err
}

func readQClearFrame(r *bufio.Reader) (string, error) {
	header := make([]byte, 2)
	if _, err := io.ReadFull(r, header); err != nil {
		return "", err
	}
	qLen := binary.BigEndian.Uint16(header[0:2])
	body := make([]byte, qLen)
	if _, err := io.ReadFull(r, body); err != nil {
		return "", err
	}
	return string(body), nil
}

func broadcastQueuePush(jobID, qName, payload string, avail int64) {
	if !haMode { return }
	peersMutex.Lock()
	defer peersMutex.Unlock()
	for addr, conn := range peers {
		go func(peerAddr string, c net.Conn) {
			err := writeQPushFrame(c, jobID, qName, payload, avail)
			if err != nil {
				log.Printf("Failed to broadcast QPUSH to %s: %v", peerAddr, err)
			}
		}(addr, conn)
	}
}

func broadcastQueueDel(jobID, qName string) {
	if !haMode { return }
	peersMutex.Lock()
	defer peersMutex.Unlock()
	for addr, conn := range peers {
		go func(peerAddr string, c net.Conn) {
			err := writeQDelFrame(c, jobID, qName)
			if err != nil {
				log.Printf("Failed to broadcast QDEL to %s: %v", peerAddr, err)
			}
		}(addr, conn)
	}
}

func broadcastQueueClear(qName string) {
	if !haMode { return }
	peersMutex.Lock()
	defer peersMutex.Unlock()
	for addr, conn := range peers {
		go func(peerAddr string, c net.Conn) {
			err := writeQClearFrame(c, qName)
			if err != nil {
				log.Printf("Failed to broadcast QCLEAR to %s: %v", peerAddr, err)
			}
		}(addr, conn)
	}
}


func acquireDistributedLock(key string, ttl int64) bool {
	if !haMode {
		return true // No lock needed if not in HA
	}

	shard := cache.getShard(key)
	shard.mutex.Lock()
	item, ok := shard.items[key]
	exists := ok && (item.Expiration == 0 || item.Expiration > time.Now().Unix())

	if exists {
		shard.mutex.Unlock()
		return false // Lock already held
	}

	expiration := time.Now().Unix() + ttl
	val := []byte("1") // Dummy value for lock
	shard.items[key] = CacheItem{Value: val, Expiration: expiration}
	shard.mutex.Unlock()

	// Persistence and Broadcast
	if db != nil {
		select {
		case dbChan <- dbJob{op: "SET", key: key, val: val, exp: expiration}:
		default:
		}
	}
	broadcastSet(key, val, expiration)

	return true
}
