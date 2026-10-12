package constants

import "time"

// Request behaviour
const HttpTimeout = 20 * time.Second

// MaxConcurrentRequests caps in-flight scrape requests. The Fetcher's throttle
// sets the actual pace, so a high value here only queues goroutines on the
// ticker.
const MaxConcurrentRequests = 5

// RequestsPerSecond is deliberately conservative: ExamTopics began returning
// 429 after roughly a dozen pages at 2/s. Override with -rps.
const RequestsPerSecond = 1.0

// MaxRetries is higher than it was because 429s need patience; retries are now
// throttled too, so they no longer amplify an overload.
const MaxRetries = 5

// Backoff configuration
const InitalBackoff = time.Second
const BackoffFactor = 2.0

// HTTP Transport Tuning (in http client)
const MaxIdleConns = 100
const MaxIdleConnsPerHost = 100
const MaxConnsPerHost = 100

// Connection Timeouts (also in http client)
const IdleConnTimeout = 90 * time.Second
const TLSHandshakeTimeout = 10 * time.Second
const ResponseHeaderTimeout = 10 * time.Second
const ExpectContinueTimeout = 1 * time.Second

// Cache (GitHub API) request behaviour. Unauthenticated GitHub allows only 60
// requests/hour, so a large exam will exhaust it regardless of pacing; pass a
// token (-t) to raise the ceiling to 5000/hour.
const CacheMaxConcurrentRequests = 10
const CacheRequestsPerSecond = 10.0

// Upper bound on how long we will honour a Retry-After header before giving up.
const MaxRetryAfter = 30 * time.Second
