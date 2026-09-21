# TryGeorge

Laravel app that replicates the **Try Jev** mechanic locally: you describe a situation **in English**, add up to five typed conditions, and George answers with numbers. Inference runs on your machine with [TransformersPHP](https://github.com/CodeWithKyrian/transformers-php). Nothing is sent to TypeSafe in the United States.

George is **not** Jev. The UI and the three condition types (`noul` / `choice` / `score`) are the same idea. The calibration of TypeSafe's model is not. George's numbers are pooled model readouts, not probabilities you can treat as “right 9 times out of 10”.

**Write in English, not Italian.** The situation, the questions, and every yes/no criterion, option, and rating label must be English. This model’s NLI head is strongest on English; Italian input makes the scores noisier and harder to threshold. Translate first, then run.

## What you get

| Condition | What you write | What you get back |
| --- | --- | --- |
| **noul** (yes / no) | A yes criterion and a no criterion | P(yes) from independent entailment of each, then renormalized |
| **choice** | Two or more options, with “when it applies” | Full softmax over entailment logits |
| **score** | Ordered scale, lowest first | Weighted position Σ(pᵢ · i) |

The default stack is an **ensemble of three slots**, each in its own PHP process:

| Slot | Model | What it contributes | Weight |
| --- | --- | --- | --- |
| A | `onnx-community/deberta-v3-large-zeroshot-v2.0-ONNX` | Zero-shot entailment: does the text *say* it | 0.25 |
| B | `Xenova/nli-deberta-v3-large` | 3-class MNLI, same question, different training | 0.15 |
| C | `onnx-community/Qwen3-1.7B-ONNX` (q4) | The **reasoner**: what a person would *do* about it | 0.60 |

Slots A and B are NLI heads: they are reliable when the criterion is visible in the words (spam, legal threat, explicit deadline) and unreliable when the answer needs inference (“should this page someone”, “is this a billing case even though it mentions a replacement”). Slot C is a small instruct LLM read through its **next-token logits over the option letters**: one forward pass per condition, no text generation, thinking disabled. Each condition is scored twice with the options reversed and averaged, which removes the letter-position bias small models have.

Readouts are pooled by weight (normalised over the slots that are running), then sharpened when the slots agree and flattened when they disagree. On the Jev benchmark in `storage/app/george-jev-bench.php` the reasoner takes noul/choice decisions from 8 flips out of 23 comparisons down to 3, with the NLI pair keeping the confident lexical cases sharp.

Set `GEORGE_ENSEMBLE=false` to drop slot B, `GEORGE_REASONER=false` to drop slot C. With one slot left George runs single-process.

## Requirements

- PHP 8.3+ with **FFI** (`ffi.enable=true`) and ~2 GB memory per NLI process, ~4 GB for the reasoner process (three processes with the default stack)
- Composer, Node 22+, SQLite
- A CPU that can run ~15–25 DeBERTa forward passes plus two Qwen3-1.7B passes per condition (about 1.5 s each on an Apple M-series)
- ~4 GB of disk for the three models

Herd on macOS already ships FFI. Check with `php -m | grep FFI`.

PHP’s default `memory_limit=128M` is not enough. Set `memory_limit=4096M` in `php.ini`, or prefix commands with `php -d memory_limit=4096M`.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan george:download
composer run dev
```

Open [http://localhost:8000](http://localhost:8000). Until the models are downloaded, George falls back to a keyword heuristic so the page still runs. The banner tells you which engine is live, and `/status` lists the slots that are ready.

`george:download` fetches the two NLI models (a few hundred MB each) and the reasoner (2.1 GB, `onnx/model_q4.onnx`) from Hugging Face, once. Files land in `storage/app/george-models/` (gitignored). Pass `--skip-reasoner` to fetch only the NLI pair.

## How to run inference

**Local, one process per slot** (default `GEORGE_DRIVER=sync`): each Run forks a PHP process per ready slot, then merges. Fine for a demo. Every call reloads the graphs, so expect ~15 s with the full stack.

**Dedicated workers** (recommended; keeps every model warm, ~2–4 s per Run):

```env
GEORGE_DRIVER=queue
```

```bash
php artisan george:work --slot=all
```

That starts one OS process per ready slot: A on `george-a`, B on `george-b`, C on `george-c`. `composer run dev` already does this instead of `queue:listen`.

Do **not** run TransformersPHP inside PHP-FPM in production. ONNX Runtime is also platform-specific: run `composer require` / `composer install` **inside** the Docker image, not on the host and then copy `vendor`.

## Docker

```bash
docker compose up --build
```

The `worker-a`, `worker-b` and `worker-c` services keep the three models warm. First boot still needs `php artisan george:download` inside the app container if the volume is empty.

## Deploy on an Ubuntu server

One command on a fresh Ubuntu box, as root:

```bash
curl -fsSL https://raw.githubusercontent.com/andreapollastri/ai/main/deploy/install.sh | sudo bash
```

It installs PHP 8.5 with FFI, nginx, SQLite, Node, the app and one systemd worker per slot, writes the virtual host, and asks Let's Encrypt for a certificate. Nothing is interactive.

The site answers within a couple of minutes. The models are several GB, so they download in the background from a `george-models` unit; until they land the page runs on the keyword heuristic and says so in the banner.

Point the A record of your domain at the server **before** running it, otherwise the certificate step is skipped and the site stays on HTTP. You can issue it later with `certbot --nginx -d <domain>`.

Everything is overridable from the environment:

| Variable | Default | Meaning |
| --- | --- | --- |
| `DOMAIN` | `george.web.ap.it` | Virtual host and certificate name |
| `EMAIL` | empty | Let's Encrypt contact address |
| `TLS` | `auto` | `auto` checks DNS first, `on` forces, `off` stays on HTTP |
| `REASONER` | `auto` | Slot C. `auto` enables it above 6 GB of RAM |
| `ENSEMBLE` | `auto` | Slot B. `auto` enables it above 3.5 GB of RAM |
| `MODELS` | `auto` | `off` skips the background download |
| `BRANCH` | `main` | Branch to deploy |
| `APP_DIR` | `/var/www/george` | Install directory |
| `NGINX_FORCE` | `off` | `on` regenerates the virtual host even when it already carries the certificate |

```bash
curl -fsSL <url> | sudo DOMAIN=george.example.com EMAIL=me@example.com bash
```

Sizing: 2 vCPU and 8 GB of RAM run all three slots, 4 GB runs the two NLI heads, and below that George falls back to a single head. Roughly 12 GB of disk.

### Updating

The same command updates an existing install:

```bash
curl -fsSL https://raw.githubusercontent.com/andreapollastri/ai/main/deploy/install.sh | sudo bash
```

It pulls the branch, reinstalls dependencies, rebuilds the assets, runs migrations, refreshes the caches and restarts the workers. It keeps the database, the `APP_KEY`, the downloaded models and the HTTPS virtual host that certbot wrote. Expect a few seconds of downtime while the workers restart.

| Task | Command |
| --- | --- |
| Model download progress | `journalctl -fu george-models` |
| Worker logs | `journalctl -fu 'george@*'` |
| Restart the workers | `systemctl restart george@a george@b george@c` |
| Engine status | `curl -s https://<domain>/status` |

## Configuration

| Variable | Default | Meaning |
| --- | --- | --- |
| `GEORGE_ENGINE` | `auto` | `auto`, `transformers`, or `fake` |
| `GEORGE_DRIVER` | `sync` | `sync` or `queue` |
| `GEORGE_MODEL` | DeBERTa-v3-large zeroshot-v2 ONNX | Hugging Face id (slot A) |
| `GEORGE_MODEL_B` | Xenova nli-deberta-v3-large | Slot B, 3-class MNLI |
| `GEORGE_ENSEMBLE` | `true` | Run slot B and merge |
| `GEORGE_REASONER` | `true` | Run slot C, the instruct LLM readout |
| `GEORGE_REASONER_MODEL` | `onnx-community/Qwen3-1.7B-ONNX` | Any causal LM TransformersPHP can load (Qwen2.5, Qwen3, Llama, Gemma, Phi-3) |
| `GEORGE_REASONER_FILE` | `model_q4` | ONNX variant inside the repo's `onnx/` folder |
| `GEORGE_REASONER_FORMAT` | `chatml` | Chat turn layout: `chatml`, `chatml_plain`, `phi3`, `llama3` |
| `GEORGE_REASONER_DEBIAS` | `true` | Score twice with reversed options and average |
| `GEORGE_WEIGHT_A` / `_B` / `_C` | `0.25` / `0.15` / `0.60` | Pooling weights, normalised over running slots |
| `GEORGE_QUANTIZED` | `true` | Smaller ONNX weights for the NLI slots |
| `GEORGE_TEMPERATURE` | `1.0` | Softmax temperature on the NLI slots. T>1 flattens peaks. **Not** real calibration. |

## What this does not give you

- **Calibration.** The slot weights and the agreement temperature are hand-set on a 24-case benchmark, not fitted. Apply temperature scaling or isotonic regression on a labelled domain set, and measure ECE, before you put thresholds in a policy.
- **Deep reasoning.** The reasoner is a 1.7B model answering in one token. It gets the obvious operational calls (page, route, interview) and misses subtle ones: sarcasm, borderline candidates. A bigger ChatML model in `GEORGE_REASONER_MODEL` helps if you have the RAM.
- **Long context.** ~512 tokens for the NLI slots. 2000 characters is the UI cap.

Each NLI label is one forward pass; the reasoner adds two passes per condition. Five conditions with 3–5 labels each is 15–25 NLI passes plus 10 reasoner passes on CPU.

## Choosing a reasoner

Slot C accepts any causal LM TransformersPHP can load, but the ONNX build has to
use a **float32 KV cache**: `model_q4`, `model_int8` or `model_quantized`. A
`q4f16` build fails at the first forward pass, because the PHP ONNX binding has
no mapping for float16 tensors. That rules out most of the larger Qwen3 repos,
which ship fp16 only.

| Model | `GEORGE_REASONER_FILE` | `GEORGE_REASONER_FORMAT` | Disk |
| --- | --- | --- | --- |
| `onnx-community/Qwen3-1.7B-ONNX` | `model_q4` | `chatml` | 1.8 GB |
| `onnx-community/Qwen3-4B-Instruct-2507-ONNX` | `model_q4` | `chatml_plain` | 4.0 GB |
| `onnx-community/Llama-3.2-3B-Instruct-ONNX` | `model_q4` | `llama3` | 3.4 GB |
| `Xenova/Phi-3-mini-4k-instruct` | `model_q4` | `phi3` | 2.7 GB |
| `onnx-community/Qwen2.5-1.5B-Instruct` | `model_q4` | `chatml_plain` | 1.8 GB |

Use `chatml` only for a Qwen3 model with a thinking mode, where the prompt has
to close an empty think block so the answer arrives in one forward pass. The
2507 Instruct release has no thinking mode, so it takes `chatml_plain`.

Models over roughly 2 GB keep their weights in `model_q4.onnx_data` files next
to the graph. `george:download` fetches those too; a hand-rolled download that
grabs only the `.onnx` file will load an empty graph.

### Measuring one

`bench/blind-set.php` holds ten situations with a written reference answer for
each condition, recorded before any model ran. Score the configured reasoner
against them on the machine that will run it:

```bash
php artisan george:bench
```

It reports three things. **Agreement** is how often the reasoner matched the
reference. **Readouts decided by option order** counts the conditions where
reversing the options reversed the answer: those are worth nothing, because
debiasing averages the two into a tie that only looks like calibrated doubt.
**Seconds per condition** is what the queue will cost per question.

For reference, on an Apple M-series laptop:

| Reasoner | Agreement | Decided by option order | s / condition |
| --- | --- | --- | --- |
| Qwen3-1.7B | 10 / 20 | 9 / 20 | 3.9 |

## Benchmark against Jev

`storage/app/george-jev-bench.php` runs 24 situations through George and TypeSafe's Try Jev API and writes `storage/app/george-jev-bench.json`. Pass `--reuse-jev` to keep the Jev answers of the previous run (no API calls, no 429s). Score positions are compared on Jev's 0-based scale.

## Tests

```bash
php artisan test
```

Tests use the fake engine. They do not download the ONNX model.

## Privacy

Situations are stored in local SQLite for at most 24 hours (`model:prune`). No third-party inference API.
