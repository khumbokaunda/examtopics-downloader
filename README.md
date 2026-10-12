# Exam Topics Downloader

This repo aims to make it possible for you to obtain all the exam questions from the examtopics website (which is paywalled).

## Setting it Up

### Using docker

1. Make sure [docker](https://docs.docker.com/engine/install/) is installed on your system.
2. Pull the docker image:

```bash
docker pull ghcr.io/thatonecodes/examtopics-downloader:latest
```

3\. Run the container:

```bash
docker run -it \
  --name examtopics-downloader \
  ghcr.io/thatonecodes/examtopics-downloader:latest \
  -p google -s devops \
  -save-links -o output.md
docker cp examtopics-downloader:/app/output.md .
docker cp examtopics-downloader:/app/saved-links.txt .
docker rm examtopics-downloader
```

> [!NOTE]  
> If seeing `exec: format exec error` or warnings about unsuportted platforms, if you are on `linux/arm64`, modify the docker cmd to:

```bash
docker run -it \
  --name examtopics-downloader \
  --platform linux/arm64 \
  ghcr.io/thatonecodes/examtopics-downloader:latest \
  -p google -s devops \
  -save-links -o output.md
docker cp examtopics-downloader:/app/output.md .
docker cp examtopics-downloader:/app/saved-links.txt .
docker rm examtopics-downloader
```

### Using Dockerfile

1. `git clone https://github.com/thatonecodes/examtopics-downloader` and make sure docker is installed on your system.
2. Run `docker build -t examtopics-dl . && docker run --rm examtopics-dl -p google -s devops -save-links -o output.md`
3. After setup, it will give you a list of exams with the `cisco` provider.

### Building from Source

1. First, you must install [Golang >= 1.24](https://go.dev/doc/install) from the offical website.
2. Then, run `git clone https://github.com/thatonecodes/examtopics-downloader` in your terminal to clone the repo.
3. `cd` into the directory: `cd examtopics-downloader`
4. You can now run: `go run ./cmd/main.go -p cisco -exams`

(there will be compiled binaries in the future)

## Command Line Arguments

```
Each command line argument you can provide when running the program:

  -batch
    	Unattended mode: never prompt, no progress bars, status text on stderr
  -c	Optionally include all the comment/discussion text
  -events string
    	Write JSON Lines progress events to this path ("-" for stdout)
  -exam string
    	Exact exam code to collect, e.g. h12-811 (never matches h12-811-v2-0)
  -exams
    	Optionally show all the possible exams for your selected provider and exit
  -inventory
    	List the provider's exams with discussion counts (JSON) and exit
  -no-cache
    	Optional argument, set to disable looking through cached data on github
  -o string
    	Optional path of the file where the data will be outputted (default "examtopics_output.md")
  -out-json string
    	Write the canonical JSON document to this path ("-" for stdout)
  -p string
    	Name of the exam provider (default -> google) (default "google")
  -providers
    	List providers found on ExamTopics (JSON) and exit
  -rps float
    	Requests per second when scraping; lower this if ExamTopics returns 429 (default 1)
  -s string
    	String to grep for in discussion links (substring match)
  -save-links
    	Optional argument to save unique links to questions
  -t string
    	GitHub PAT to raise the API rate limit; if omitted, read from $EXAMTOPICS_GITHUB_TOKEN
  -type string
    	Optionally include file type (default -> .md) (default "md")
  -version
    	Print the tool version and exit
```

## Possible Arguments List

### Exam Providers, `-p`

| Provider (-p)    | View Exams                                                                     | Notes     |
| ---------------- | ------------------------------------------------------------------------------ | --------- |
| amazon           | [Amazon Exams](https://www.examtopics.com/exams/amazon/)                       | AWS Certs |
| cisco            | [Cisco Exams](https://www.examtopics.com/exams/cisco/)                         |           |
| comptia          | [CompTIA Exams](https://www.examtopics.com/exams/comptia/)                     |           |
| salesforce       | [Salesforce Exams](https://www.examtopics.com/exams/salesforce/)               |           |
| fortinet         | [Fortinet Exams](https://www.examtopics.com/exams/fortinet/)                   |           |
| juniper          | [Juniper Exams](https://www.examtopics.com/exams/juniper/)                     |           |
| isaca            | [ISACA Exams](https://www.examtopics.com/exams/isaca/)                         |           |
| vmware           | [VMware Exams](https://www.examtopics.com/exams/vmware/)                       |           |
| isc2             | [ISC2 Exams](https://www.examtopics.com/exams/isc2/)                           | CISSP etc |
| servicenow       | [ServiceNow Exams](https://www.examtopics.com/exams/servicenow/)               |           |
| google           | [Google Exams](https://www.examtopics.com/exams/google/)                       |           |
| microsoft        | [Microsoft Exams](https://www.examtopics.com/exams/microsoft/)                 |           |
| ec-council       | [EC-Council Exams](https://www.examtopics.com/exams/ec-council/)               | CEH etc   |
| oracle           | [Oracle Exams](https://www.examtopics.com/exams/oracle/)                       |           |
| paloaltonetworks | [Palo Alto Networks Exams](https://www.examtopics.com/exams/paloaltonetworks/) |           |

> [!NOTE]  
> The more the amount of exams/discussion the provider has, the longer it will take to scrape through the exams.

### `-save-links`

This is a bool flag, so the default is that it's set to `false`, deactivated. When set, the unique question links are written to `saved-links.txt`.

### Grep String, `-s`

The `-s` argument can take an exam ID (ex. 200-301) or a word, such as "devops". for example:

```bash
go run ./cmd/main.go -p google -s devops
```

would get all exams from the `google` provider containing the string `devops`.

`-s` is a **substring** match: `-s h12-811` also collects `h12-811-v2-0`. To collect exactly one
exam, use `-exam` instead (`-exam h12-811`). The two cannot be combined.

### Comments and output, `-c` && `-o`

The `-c` argument is another bool flag, so it is defaultly set to false(as it creates a lot of noise in the `.md` file), but you can include it by adding the flag.
`-o` is the output path, based on `os.create(path)`, in the current working directory.

### Exams output, `-exams`

This argument will display output defaulted to such as and exit immediately.

```
Exams for provider 'google'

https://www.examtopics.com/exams/google/adwords-fundamentals/
https://www.examtopics.com/exams/google/associate-android-developer/
https://www.examtopics.com/exams/google/associate-cloud-engineer/
https://www.examtopics.com/exams/google/associate-data-practitioner/
https://www.examtopics.com/exams/google/associate-google-workspace-administrator/
https://www.examtopics.com/exams/google/cloud-digital-leader/
https://www.examtopics.com/exams/google/display-advertising/
https://www.examtopics.com/exams/google/google-analytics/
https://www.examtopics.com/exams/google/gsuite/
https://www.examtopics.com/exams/google/individual-qualification/
https://www.examtopics.com/exams/google/mobile-advertising/
https://www.examtopics.com/exams/google/professional-chromeos-administrator/
https://www.examtopics.com/exams/google/professional-cloud-architect/
https://www.examtopics.com/exams/google/professional-cloud-database-engineer/
https://www.examtopics.com/exams/google/professional-cloud-developer/
https://www.examtopics.com/exams/google/professional-cloud-devops-engineer/
https://www.examtopics.com/exams/google/professional-cloud-network-engineer/
https://www.examtopics.com/exams/google/professional-cloud-security-engineer/
https://www.examtopics.com/exams/google/professional-collaboration-engineer/
https://www.examtopics.com/exams/google/professional-data-engineer/
https://www.examtopics.com/exams/google/professional-google-workspace-administrator/
https://www.examtopics.com/exams/google/professional-machine-learning-engineer/
https://www.examtopics.com/exams/google/search-advertising/
https://www.examtopics.com/exams/google/shopping-advertising/
https://www.examtopics.com/exams/google/video-advertising/
```

### Token Input, `-t`

When you add you `Github` PAT, it allows for more requests to the API, (up to 5000) which is needed when scraping bigger things.
The cached data helps you access big dumps faster.

### No Cache Arg, `-no-cache`

When you add this argument, it tells the program to ignore the cached `Github` repoitories of updated exam info, however the scraper will take longer than the cache.
Useful when wanting to scrape realtime data.

### File Type, `-type`

When you use the `-type` argument, it tells the program to convert the default filetype of `.md` files to the option of your choice.  
Currently we have these types supported:  
- `html` -> generates `examtopics_output.html`
- `pdf` -> generates `examtopics_output.pdf`
- `text` -> generates `examtopics_output.txt`
- `json` -> generates `examtopics_output.json`

> [!NOTE]  
> Files are kept in same/similar format as you would see in the `.md` file, for formatting changes, use other arguments.

> [!NOTE]  
> The `json` type emits structured data (one object per question, with `title`, `header`, `content`, `questions`, `answer`, `timestamp`, `question_link` and `comments`), suitable for programmatic use such as flash-card generators, study apps or further scripting.

## [For outputted file examples, see the examples folder](examples/google_devops.md)

## Demo

So, you have installed `go` on your system, and you're inside of the working directory. Let's say you would like the questions for the cisco exam 200-301.

Open your terminal and run:

```bash
go run ./cmd/main.go -p cisco -s 200-301
```

Note that you can put the id as the string to look for, as the program is compatible this way also.

After waiting a few moments, you would see the output end with:

```bash
Successfully saved output to {OUTPUT_LOCATION}.
```

If so, hooray, you have successfully saved all/most of the questions in a `.md` file!
The format would be such as (older, only scraping format):

```
----------------------------------------

## Exam 200-301 topic 1 question 532 discussion

Actual exam question from

Cisco's
200-301

Question #: 532
Topic #: 1

[All 200-301 Questions]

Refer to the exhibit. An engineer configured NAT translations and has verified that the configuration is correct. Which IP address is the source IP after the NAT has taken place?
Suggested Answer: D 🗳️

A. 10.4.4.4

B. 10.4.4.5

C. 172.23.103.10

D. 172.23.104.4

**Answer: D**

**Timestamp: Jan. 5, 2021, 9:48 p.m.**

[View on ExamTopics](https://www.examtopics.com/discussions/cisco/view/41599-exam-200-301-topic-1-question-532-discussion/)

----------------------------------------
```

## Machine-readable mode

These flags let other programs (such as the [Exam Library web app](web/README.md)) drive the
tool unattended. The formats are specified in [docs/DATA_MODEL.md](docs/DATA_MODEL.md).

```bash
# Every exam a provider has discussions for, with question counts (listing pages only)
go run ./cmd/main.go -inventory -p huawei -out-json huawei-inventory.json

# Collect exactly one exam as canonical JSON, with progress events, never prompting
go run ./cmd/main.go -batch -p huawei -exam h12-811 -out-json h12-811.json -events progress.jsonl
```

- `-out-json` writes a versioned document atomically. A collection includes `status`
  (`complete` or `partial`), per-phase counts, error summaries, and per question: choices, suggested
  and community answers (multi-letter answers intact), explanation, images, and comments.
- `-events` writes one JSON object per line (`phase`, `progress`, `warning`, then exactly one
  `completed` or `failed`).
- Exit codes: `0` complete, `1` failed, `2` partial (output written and labelled partial),
  `3` nothing matched, `64` usage error.
- The "delete Markdown file?" prompt after `-type` conversions only appears when stdin is a
  terminal and `-batch` is not set, so scripts never block.
