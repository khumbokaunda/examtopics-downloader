package fetch

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"

	"examtopics-downloader/internal/dataset"
	"examtopics-downloader/internal/utils"
)

// MaxImageBytes caps one image. Exam figures are screenshots and diagrams, far smaller.
const MaxImageBytes = 5 << 20

// MaxImages caps the addresses one run will download.
const MaxImages = 5000

// imageTypes are the formats kept, by sniffed content type, with their file extensions.
// SVG is deliberately absent: it can carry script.
var imageTypes = map[string]string{
	"image/png":  "png",
	"image/jpeg": "jpg",
	"image/gif":  "gif",
	"image/webp": "webp",
}

// ImageURLAllowed reports whether an image address may be downloaded: https on
// examtopics.com or one of its subdomains, or the configured base origin (which is only
// overridden for a local fixture server in tests).
func ImageURLAllowed(raw string) bool {
	u, err := url.Parse(raw)
	if err != nil || u.User != nil || u.Host == "" {
		return false
	}
	if base, err := url.Parse(utils.BaseURL); err == nil && u.Scheme == base.Scheme && strings.EqualFold(u.Host, base.Host) {
		return true
	}
	host := strings.ToLower(u.Hostname())
	return u.Scheme == "https" && (u.Port() == "" || u.Port() == "443") &&
		(host == "examtopics.com" || strings.HasSuffix(host, ".examtopics.com"))
}

// ImageURLs lists a dataset's question and answer images in order, without duplicates.
func ImageURLs(questions []dataset.Question) []string {
	var urls []string
	for _, q := range questions {
		urls = appendUnique(urls, q.QuestionImages...)
		urls = appendUnique(urls, q.AnswerImages...)
	}
	return urls
}

// ReadImageList reads image addresses from a file: either a dataset JSON document (its
// question and answer images) or plain text with one address per line, where blank lines
// and lines starting with # are ignored and a path such as /assets/media/x.png is taken
// as an ExamTopics address.
func ReadImageList(path string) ([]string, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	if trimmed := bytes.TrimSpace(data); len(trimmed) > 0 && trimmed[0] == '{' {
		var ds dataset.Dataset
		if err := json.Unmarshal(trimmed, &ds); err != nil {
			return nil, fmt.Errorf("reading %s as a dataset: %w", path, err)
		}
		if ds.Kind != "dataset" {
			return nil, fmt.Errorf("%s is a %q document, not a dataset", path, ds.Kind)
		}
		return ImageURLs(ds.Questions), nil
	}
	var urls []string
	sc := bufio.NewScanner(bytes.NewReader(data))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line != "" && !strings.HasPrefix(line, "#") {
			urls = appendUnique(urls, absoluteURL(line))
		}
	}
	return urls, sc.Err()
}

// newImageFetcher returns a throttled fetcher whose redirects may only lead to allowed
// image addresses, so a redirect cannot send the download somewhere else.
func newImageFetcher(rps float64) *Fetcher {
	client := utils.NewHTTPClient()
	client.CheckRedirect = func(req *http.Request, via []*http.Request) error {
		if len(via) >= 5 {
			return errors.New("too many redirects")
		}
		if !ImageURLAllowed(req.URL.String()) {
			return fmt.Errorf("redirected to %s, which is not an ExamTopics address", req.URL.Host)
		}
		return nil
	}
	return NewFetcher(client, rps)
}

// DownloadImages saves each allowed image into dir, named by the SHA-256 of its content,
// and reports one outcome per address. Requests are paced at rps, retried like page
// requests, and given a second pass after RetryCooldown when they failed transiently.
func DownloadImages(urls []string, dir string, rps float64) (dataset.Images, error) {
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return dataset.Images{}, fmt.Errorf("creating the images folder: %w", err)
	}
	f := newImageFetcher(rps)
	defer f.Stop()

	items := make([]dataset.Image, len(urls))
	failed := map[int]error{}
	var order []int
	download := func(i int) error {
		body, err := f.get(urls[i], MaxImageBytes)
		if err != nil {
			return err
		}
		return saveImage(&items[i], body, dir)
	}

	reporter.Phase("images", fmt.Sprintf("Downloading %d images", len(urls)))
	fmt.Fprintf(out, "Downloading %d images into %s\n", len(urls), dir)
	bar := newBar(len(urls))
	for i, u := range urls {
		items[i] = dataset.Image{URL: u}
		switch {
		case i >= MaxImages:
			items[i].Status, items[i].Error = dataset.ImageSkipped, fmt.Sprintf("over the limit of %d images per run", MaxImages)
		case !ImageURLAllowed(u):
			items[i].Status, items[i].Error = dataset.ImageSkipped, "not an ExamTopics address"
		default:
			if err := download(i); err != nil {
				failed[i] = err
				order = append(order, i)
				log.Printf("image %s: %v", u, err)
			}
		}
		bar.Increment()
		reporter.Progress("images", i+1, len(urls))
	}
	bar.Finish()

	retryFailed("images", "image", order, failed, download)

	for _, i := range order {
		err, still := failed[i]
		if !still {
			continue
		}
		items[i].Status, items[i].Error = dataset.ImageFailed, err.Error()
		var refused *notImageError
		var tooLarge *TooLargeError
		if errors.As(err, &refused) || errors.As(err, &tooLarge) {
			items[i].Status = dataset.ImageSkipped
		}
		reporter.Warning("images", "image "+urls[i]+": "+err.Error())
	}
	return dataset.NewImages(items), nil
}

// notImageError reports content that is not one of the kept image formats.
type notImageError struct{ contentType string }

func (e *notImageError) Error() string {
	return "not a PNG, JPEG, GIF or WebP image (" + e.contentType + ")"
}

// saveImage checks the content is an image and writes it once, atomically, under its hash.
func saveImage(item *dataset.Image, body []byte, dir string) error {
	ct := http.DetectContentType(body)
	ext, ok := imageTypes[ct]
	if !ok {
		return &notImageError{contentType: ct}
	}
	sum := sha256.Sum256(body)
	hash := hex.EncodeToString(sum[:])
	name := hash + "." + ext
	path := filepath.Join(dir, name)
	if _, err := os.Stat(path); err != nil {
		tmp, err := os.CreateTemp(dir, ".img-*")
		if err != nil {
			return err
		}
		_, werr := tmp.Write(body)
		cerr := tmp.Close()
		if werr != nil || cerr != nil {
			os.Remove(tmp.Name())
			return errors.Join(werr, cerr)
		}
		if err := os.Rename(tmp.Name(), path); err != nil {
			os.Remove(tmp.Name())
			return err
		}
	}
	item.Status, item.File, item.SHA256, item.ContentType, item.Bytes, item.Error =
		dataset.ImageSaved, name, hash, ct, len(body), ""
	return nil
}
