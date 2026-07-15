BINARY_NAME=hypercacheio-server
SOURCE_FILE=main.go
OUT_DIR ?= ../build

.PHONY: all clean build-mac-arm64 build-mac-amd64 build-linux-arm64 build-linux-amd64 checksums

all: build-mac-arm64 build-mac-amd64 build-linux-arm64 build-linux-amd64 checksums

clean:
	rm -rf $(OUT_DIR)

build-mac-arm64:
	mkdir -p $(OUT_DIR)
	GOOS=darwin GOARCH=arm64 go build -o $(OUT_DIR)/$(BINARY_NAME)-darwin-arm64 $(SOURCE_FILE)

build-mac-amd64:
	mkdir -p $(OUT_DIR)
	GOOS=darwin GOARCH=amd64 go build -o $(OUT_DIR)/$(BINARY_NAME)-darwin-amd64 $(SOURCE_FILE)

build-linux-arm64:
	mkdir -p $(OUT_DIR)
	GOOS=linux GOARCH=arm64 go build -o $(OUT_DIR)/$(BINARY_NAME)-linux-arm64 $(SOURCE_FILE)

build-linux-amd64:
	mkdir -p $(OUT_DIR)
	GOOS=linux GOARCH=amd64 go build -o $(OUT_DIR)/$(BINARY_NAME)-linux-amd64 $(SOURCE_FILE)

checksums:
	cd $(OUT_DIR) && if command -v sha256sum >/dev/null 2>&1; then sha256sum $(BINARY_NAME)-* > sha256sums.txt; else shasum -a 256 $(BINARY_NAME)-* > sha256sums.txt; fi
