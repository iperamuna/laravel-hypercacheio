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
	"strings"
	"sync"
	"time"

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

	db *sql.DB

	// In-memory cache
	cache      = make(map[string]CacheItem)
	cacheMutex sync.RWMutex

	// Peer connections
	peers      = make(map[string]net.Conn)
	peersMutex sync.Mutex
)

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
		db, err = sql.Open("sqlite", sqlitePath)
		if err != nil {
			log.Fatalf("Failed to open SQLite database: %s", err)
		}
		db.SetMaxOpenConns(10)
		if err := initSqlite(); err != nil {
			log.Fatalf("Failed to initialize SQLite schema: %s", err)
		}
		log.Printf("SQLite persistence enabled: %s", sqlitePath)
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

	// Start HTTP API for Laravel
	mux := http.NewServeMux()
	mux.HandleFunc("/api/hypercacheio/cache/", handleCache)
	mux.HandleFunc("/api/hypercacheio/add/", handleAdd)
	mux.HandleFunc("/api/hypercacheio/lock/", handleLock)
	mux.HandleFunc("/api/hypercacheio/ping", handlePing)

	serverAddr := fmt.Sprintf("%s:%d", host, port)
	log.Printf("Starting Hypercacheio HTTP API on %s", serverAddr)

	var err error
	if sslEnabled {
		if sslCert == "" || sslKey == "" {
			log.Fatal("SSL Certificate and Key are required when SSL is enabled")
		}
		err = http.ListenAndServeTLS(serverAddr, sslCert, sslKey, authMiddleware(mux))
	} else {
		err = http.ListenAndServe(serverAddr, authMiddleware(mux))
	}

	if err != nil {
		log.Fatalf("Server failed: %s", err)
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
		case OpFlush:
			log.Printf("Received FLUSH from peer")
			flushLocal(false)
		case OpSyncReq:
			log.Printf("Received SYNC request from peer %s", conn.RemoteAddr())
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
		case OpFlush:
			flushLocal(false)
		}
	}
}

func sendSyncRequest(conn net.Conn) {
	conn.Write([]byte{OpSyncReq})
}

func sendFullDump(conn net.Conn) {
	cacheMutex.RLock()
	defer cacheMutex.RUnlock()

	log.Printf("Sending full dump (%d items) to %s", len(cache), conn.RemoteAddr())
	for k, v := range cache {
		if v.Expiration > 0 && v.Expiration < time.Now().Unix() {
			continue
		}
		writeSetFrame(conn, OpSyncItem, k, v.Value, v.Expiration)
	}
	conn.Write([]byte{OpSyncEnd})
}

func broadcastSet(key string, val []byte, expiration int64) {
	peersMutex.Lock()
	defer peersMutex.Unlock()
	for addr, conn := range peers {
		err := writeSetFrame(conn, OpSet, key, val, expiration)
		if err != nil {
			log.Printf("Failed to broadcast SET to %s: %v", addr, err)
		}
	}
}

func broadcastDel(key string) {
	peersMutex.Lock()
	defer peersMutex.Unlock()
	for addr, conn := range peers {
		err := writeDelFrame(conn, key)
		if err != nil {
			log.Printf("Failed to broadcast DEL to %s: %v", addr, err)
		}
	}
}

func broadcastFlush() {
	peersMutex.Lock()
	defer peersMutex.Unlock()
	for addr, conn := range peers {
		_, err := conn.Write([]byte{OpFlush})
		if err != nil {
			log.Printf("Failed to broadcast FLUSH to %s: %v", addr, err)
		}
	}
}

// -------------------------------------------------------------
// Frame Encoding/Decoding
// -------------------------------------------------------------

func writeSetFrame(w io.Writer, op byte, key string, val []byte, exp int64) error {
	keyBytes := []byte(key)
	header := make([]byte, 11)
	header[0] = op
	binary.BigEndian.PutUint16(header[1:3], uint16(len(keyBytes)))
	binary.BigEndian.PutUint32(header[3:7], uint32(len(val)))
	binary.BigEndian.PutUint32(header[7:11], uint32(exp))

	if _, err := w.Write(header); err != nil {
		return err
	}
	if _, err := w.Write(keyBytes); err != nil {
		return err
	}
	if _, err := w.Write(val); err != nil {
		return err
	}
	return nil
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
	header := make([]byte, 3)
	header[0] = OpDel
	binary.BigEndian.PutUint16(header[1:3], uint16(len(keyBytes)))
	if _, err := w.Write(header); err != nil {
		return err
	}
	if _, err := w.Write(keyBytes); err != nil {
		return err
	}
	return nil
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
	cacheMutex.Lock()
	cache[key] = CacheItem{Value: val, Expiration: expiration}
	cacheMutex.Unlock()

	if db != nil {
		var exp interface{}
		if expiration > 0 {
			exp = expiration
		}
		db.Exec("REPLACE INTO cache(key, value, expiration) VALUES(?, ?, ?)", key, val, exp)
	}

	if broadcast {
		broadcastSet(key, val, expiration)
	}
}

func getLocal(key string) ([]byte, bool) {
	cacheMutex.RLock()
	item, ok := cache[key]
	cacheMutex.RUnlock()

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
	cacheMutex.Lock()
	delete(cache, key)
	cacheMutex.Unlock()

	if db != nil {
		db.Exec("DELETE FROM cache WHERE key = ?", key)
	}

	if broadcast {
		broadcastDel(key)
	}
}

func flushLocal(broadcast bool) {
	cacheMutex.Lock()
	cache = make(map[string]CacheItem)
	cacheMutex.Unlock()

	if db != nil {
		db.Exec("DELETE FROM cache")
	}

	if broadcast {
		broadcastFlush()
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
	for rows.Next() {
		var k string
		var v []byte
		var exp sql.NullInt64
		if err := rows.Scan(&k, &v, &exp); err == nil {
			expiration := int64(0)
			if exp.Valid {
				expiration = exp.Int64
			}
			if expiration == 0 || expiration > time.Now().Unix() {
				cache[k] = CacheItem{Value: v, Expiration: expiration}
				count++
			}
		}
	}
	log.Printf("Loaded %d items from SQLite persistence", count)
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

	// Atomic Check-and-Set using Mutex
	cacheMutex.Lock()
	item, ok := cache[key]
	exists := ok && (item.Expiration == 0 || item.Expiration > time.Now().Unix())

	if exists {
		cacheMutex.Unlock()
		writeJSON(w, map[string]bool{"added": false})
		return
	}

	var expiration int64
	if payload.TTL != nil && *payload.TTL > 0 {
		expiration = time.Now().Unix() + int64(*payload.TTL)
	}

	encoded, _ := php_serialize.Serialize(payload.Value)
	// We are still holding the lock, so we can set it safely.
	cache[key] = CacheItem{Value: []byte(encoded), Expiration: expiration}
	cacheMutex.Unlock()

	// Persistence and Broadcast (outside the lock for performance)
	if db != nil {
		var exp interface{}
		if expiration > 0 {
			exp = expiration
		}
		db.Exec("REPLACE INTO cache(key, value, expiration) VALUES(?, ?, ?)", key, encoded, exp)
	}
	broadcastSet(key, []byte(encoded), expiration)

	writeJSON(w, map[string]bool{"added": true})
}

func handleLock(w http.ResponseWriter, r *http.Request) {
	key := "lock:" + strings.TrimPrefix(r.URL.Path, "/api/hypercacheio/lock/")

	switch r.Method {
	case "POST":
		body, _ := io.ReadAll(r.Body)
		var payload Payload
		json.Unmarshal(body, &payload)

		// Atomic Lock Acquisition
		cacheMutex.Lock()
		item, exists := cache[key]
		if exists && (item.Expiration == 0 || item.Expiration > time.Now().Unix()) {
			// Check if same owner
			if string(item.Value) == payload.Owner {
				// Extend TTL if needed? Laravel usually doesn't re-acquire to extend within the same request
				cacheMutex.Unlock()
				writeJSON(w, map[string]bool{"acquired": true})
				return
			}
			cacheMutex.Unlock()
			writeJSON(w, map[string]bool{"acquired": false})
			return
		}

		var expiration int64
		if payload.TTL != nil && *payload.TTL > 0 {
			expiration = time.Now().Unix() + int64(*payload.TTL)
		}
		cache[key] = CacheItem{Value: []byte(payload.Owner), Expiration: expiration}
		cacheMutex.Unlock()

		// Broadcast
		broadcastSet(key, []byte(payload.Owner), expiration)
		writeJSON(w, map[string]bool{"acquired": true})

	case "DELETE":
		body, _ := io.ReadAll(r.Body)
		var payload Payload
		json.Unmarshal(body, &payload)

		cacheMutex.Lock()
		item, exists := cache[key]
		if exists && string(item.Value) == payload.Owner {
			delete(cache, key)
			cacheMutex.Unlock()
			broadcastDel(key)
			writeJSON(w, map[string]bool{"released": true})
			return
		}
		cacheMutex.Unlock()
		writeJSON(w, map[string]bool{"released": false})
	}
}

func handlePing(w http.ResponseWriter, r *http.Request) {
	hostName, _ := os.Hostname()

	peersMutex.Lock()
	peerList := make([]string, 0, len(peers))
	for addr := range peers {
		peerList = append(peerList, addr)
	}
	peersMutex.Unlock()

	role := "go-server-standalone"
	if haMode {
		role = "go-server-ha"
	}

	writeJSON(w, map[string]interface{}{
		"message":     "pong",
		"role":        role,
		"hostname":    hostName,
		"time":        time.Now().Unix(),
		"peers":       peerList,
		"items_count": len(cache),
		"ha_mode":     haMode,
	})
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
