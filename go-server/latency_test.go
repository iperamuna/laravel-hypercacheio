package main

import (
	"fmt"
	"sort"
	"testing"
	"time"
)

func TestPercentileLatency(t *testing.T) {
	sc := newShardedCache()
	val := []byte("some value")
	
	const totalOps = 100000
	latencies := make([]time.Duration, totalOps)

	// Measure SET latency
	for i := 0; i < totalOps; i++ {
		key := fmt.Sprintf("lkey-%d", i)
		start := time.Now()
		
		shard := sc.getShard(key)
		shard.mutex.Lock()
		shard.items[key] = CacheItem{Value: val}
		shard.mutex.Unlock()
		
		latencies[i] = time.Since(start)
	}

	sort.Slice(latencies, func(i, j int) bool {
		return latencies[i] < latencies[j]
	})

	p50 := latencies[totalOps*50/100]
	p95 := latencies[totalOps*95/100]
	p99 := latencies[totalOps*99/100]

	fmt.Printf("\n--- SET Latency Percentiles (Internal) ---\n")
	fmt.Printf("P50: %v\n", p50)
	fmt.Printf("P95: %v\n", p95)
	fmt.Printf("P99: %v\n", p99)

	// Measure GET latency
	for i := 0; i < totalOps; i++ {
		key := fmt.Sprintf("lkey-%d", i)
		start := time.Now()
		
		shard := sc.getShard(key)
		shard.mutex.RLock()
		_ = shard.items[key]
		shard.mutex.RUnlock()
		
		latencies[i] = time.Since(start)
	}

	sort.Slice(latencies, func(i, j int) bool {
		return latencies[i] < latencies[j]
	})

	p50 = latencies[totalOps*50/100]
	p95 = latencies[totalOps*95/100]
	p99 = latencies[totalOps*99/100]

	fmt.Printf("\n--- GET Latency Percentiles (Internal) ---\n")
	fmt.Printf("P50: %v\n", p50)
	fmt.Printf("P95: %v\n", p95)
	fmt.Printf("P99: %v\n", p99)
}
