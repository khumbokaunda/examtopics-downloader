// Command fixtureserver serves the fixture ExamTopics site for end-to-end tests of the
// web application. It prints its base URL on stdout once listening.
//
//	go build -o bin/fixtureserver ./tests/fixtureserver
//	bin/fixtureserver -addr 127.0.0.1:0 -fail /discussions/huawei/2/=500
package main

import (
	"flag"
	"fmt"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"

	"examtopics-downloader/tests/fixturesite"
)

func main() {
	addr := flag.String("addr", "127.0.0.1:0", "listen address")
	fail := flag.String("fail", "", "comma-separated path=status pairs that always fail")
	flaky := flag.String("flaky", "", "comma-separated paths that return 429 once")
	flag.Parse()

	opts := fixturesite.Options{FailPaths: map[string]int{}, FlakyPaths: map[string]bool{}}
	for _, kv := range strings.Split(*fail, ",") {
		if p, code, ok := strings.Cut(kv, "="); ok {
			n, err := strconv.Atoi(code)
			if err != nil {
				fmt.Fprintln(os.Stderr, "bad -fail entry:", kv)
				os.Exit(64)
			}
			opts.FailPaths[p] = n
		}
	}
	for _, p := range strings.Split(*flaky, ",") {
		if p != "" {
			opts.FlakyPaths[p] = true
		}
	}

	ln, err := net.Listen("tcp", *addr)
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	fmt.Printf("http://%s\n", ln.Addr().String())
	os.Stdout.Sync()
	if err := http.Serve(ln, fixturesite.Default(opts)); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
