package main

import (
	"bufio"
	"net"
	"testing"
	"time"
)

func BenchmarkReplicationThroughput(b *testing.B) {
	// 1. Start a secondary listener
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		b.Fatalf("Failed to listen: %v", err)
	}
	defer ln.Close()

	addr := ln.Addr().String()
	
	// Receiver side
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			go func(c net.Conn) {
				defer c.Close()
				reader := bufio.NewReader(c)
				for {
					_, err := reader.ReadByte()
					if err != nil {
						return
					}
					// Read the frame header (10 bytes)
					header := make([]byte, 10)
					if _, err := reader.Read(header); err != nil {
						return
					}
					// Read key and value based on header (not strictly necessary for throughput, but good to simulate work)
					// For bench, we just swallow bytes
				}
			}(conn)
		}
	}()

	// 2. Primary side
	conn, err := net.Dial("tcp", addr)
	if err != nil {
		b.Fatalf("Failed to dial: %v", err)
	}
	defer conn.Close()

	key := "replication-test-key"
	val := make([]byte, 1024) // 1KB value
	expiration := int64(time.Now().Add(time.Hour).Unix())

	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		err := writeSetFrame(conn, OpSet, key, val, expiration)
		if err != nil {
			b.Fatalf("Write error: %v", err)
		}
	}
}

func BenchmarkLatencyReplication(b *testing.B) {
	// Measures RTT-like latency if we had an ACK, but here it's one-way.
	// We'll measure how many frames we can blast per second.
	BenchmarkReplicationThroughput(b)
}

func BenchmarkSyncDump(b *testing.B) {
	// Simulates sending 100,000 items in a full dump
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		b.Fatalf("Failed to listen: %v", err)
	}
	defer ln.Close()

	addr := ln.Addr().String()
	
	go func() {
		conn, _ := ln.Accept()
		defer conn.Close()
		reader := bufio.NewReader(conn)
		for {
			if _, err := reader.ReadByte(); err != nil { return }
		}
	}()

	conn, _ := net.Dial("tcp", addr)
	defer conn.Close()

	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		// Mock 10,000 items dump
		for j := 0; j < 10000; j++ {
			writeSetFrame(conn, OpSyncItem, "k", []byte("v"), 0)
		}
		conn.Write([]byte{OpSyncEnd})
	}
}
