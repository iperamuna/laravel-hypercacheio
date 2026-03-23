package main

import (
	"fmt"
	"sync"
	"testing"
)

func BenchmarkShardedSet(b *testing.B) {
	sc := newShardedCache()
	val := []byte("some value")
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		key := fmt.Sprintf("key-%d", i)
		shard := sc.getShard(key)
		shard.mutex.Lock()
		shard.items[key] = CacheItem{Value: val}
		shard.mutex.Unlock()
	}
}

func BenchmarkShardedGet(b *testing.B) {
	sc := newShardedCache()
	val := []byte("some value")
	for i := 0; i < 1000; i++ {
		key := fmt.Sprintf("key-%d", i)
		shard := sc.getShard(key)
		shard.items[key] = CacheItem{Value: val}
	}
	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		key := fmt.Sprintf("key-%d", i%1000)
		shard := sc.getShard(key)
		shard.mutex.RLock()
		_ = shard.items[key]
		shard.mutex.RUnlock()
	}
}

func BenchmarkParallelSet(b *testing.B) {
	sc := newShardedCache()
	val := []byte("some value")
	b.RunParallel(func(pb *testing.PB) {
		i := 0
		for pb.Next() {
			key := fmt.Sprintf("pkey-%d", i)
			shard := sc.getShard(key)
			shard.mutex.Lock()
			shard.items[key] = CacheItem{Value: val}
			shard.mutex.Unlock()
			i++
		}
	})
}

func BenchmarkParallelGet(b *testing.B) {
	sc := newShardedCache()
	val := []byte("some value")
	for i := 0; i < 1000; i++ {
		key := fmt.Sprintf("pkey-%d", i)
		shard := sc.getShard(key)
		shard.items[key] = CacheItem{Value: val}
	}
	b.ResetTimer()
	b.RunParallel(func(pb *testing.PB) {
		i := 0
		for pb.Next() {
			key := fmt.Sprintf("pkey-%d", i%1000)
			shard := sc.getShard(key)
			shard.mutex.RLock()
			_ = shard.items[key]
			shard.mutex.RUnlock()
			i++
		}
	})
}

// Global Lock simulation for comparison
func BenchmarkGlobalLockSet(b *testing.B) {
	var mu sync.Mutex
	m := make(map[string]CacheItem)
	val := []byte("some value")
	b.ResetTimer()
	b.RunParallel(func(pb *testing.PB) {
		i := 0
		for pb.Next() {
			key := fmt.Sprintf("gkey-%d", i)
			mu.Lock()
			m[key] = CacheItem{Value: val}
			mu.Unlock()
			i++
		}
	})
}
