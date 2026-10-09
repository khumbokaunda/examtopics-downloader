package constants

import "time"

// Request behaviour
const HttpTimeout = 20 * time.Second
const MaxConcurrentRequests = 15
const RequestsPerSecond = 2.0
const MaxRetries = 3

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
