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
| C | `onnx-community/Qwen3-4B-Instruct-2507-ONNX` (q4) | The **reasoner**: what a person would *do* about it | 0.60 |

Slots A and B are NLI heads: they are reliable when the criterion is visible in the words (spam, legal threat, explicit deadline) and unreliable when the answer needs inference (“should this page someone”, “is this a billing case even though it mentions a replacement”). Slot C is a small instruct LLM read through its **next-token logits over the option letters**: one forward pass per condition, no text generation, thinking disabled. Each condition is scored twice with the options reversed and averaged, which removes the letter-position bias small models have.

Readouts are pooled by weight (normalised over the slots that are running), then sharpened when the slots agree and flattened when they disagree. On the Jev benchmark in `storage/app/george-jev-bench.php` the reasoner takes noul/choice decisions from 8 flips out of 23 comparisons down to 3, with the NLI pair keeping the confident lexical cases sharp.

Set `GEORGE_ENSEMBLE=false` to drop slot B, `GEORGE_REASONER=false` to drop slot C. With one slot left George runs single-process.

Slot C has two backends. The default runs the model in the PHP process through ONNX Runtime, which needs no extra service and stops at 4B. `GEORGE_REASONER_BACKEND=llama` points it at a llama-server instead, which takes 8B and 14B weights. Same prompt, same readout, same weight in the pool — see [Choosing a reasoner](#choosing-a-reasoner).

## Requirements

- PHP 8.3+ with **FFI** (`ffi.enable=true`) and ~2 GB memory per NLI process, ~5 GB for the reasoner process (three processes with the default stack)
- Composer, Node 22+, SQLite
- A CPU that can run ~15–25 DeBERTa forward passes plus two 4B passes per condition. The reasoner is what costs: measure it with `george:bench` before you size the box
- ~6 GB of disk for the three models

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

`george:download` fetches the two NLI models (a few hundred MB each) and the reasoner (4 GB, `onnx/model_q4.onnx` plus its `.onnx_data` siblings) from Hugging Face, once. Files land in `storage/app/george-models/` (gitignored). Pass `--skip-reasoner` to fetch only the NLI pair. On the llama backend there is nothing to fetch here: llama-server downloads its own GGUF.

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

To put slot C on llama.cpp instead, bring up the `reasoner` service and point the workers at it:

```bash
GEORGE_REASONER_BACKEND=llama docker compose --profile llama up --build
```

`GEORGE_LLAMA_REPO` and `GEORGE_LLAMA_QUANT` choose the weights; the server downloads them into the `llama-models` volume on first boot.

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
| `REASONER` | `auto` | Slot C. `auto` enables it above 8 GB of RAM |
| `REASONER_BACKEND` | `auto` | `onnx` or `llama`. `auto` picks llama above 12 GB of RAM and 6 cores |
| `LLAMA_REPO` | sized from the box | GGUF repo for slot C, e.g. `Qwen/Qwen3-14B-GGUF` |
| `LLAMA_QUANT` | `Q4_K_M` | Quant llama-server asks Hugging Face for |
| `LLAMA_THREADS` | cores − 1 | Threads llama-server runs with |
| `ENSEMBLE` | `auto` | Slot B. `auto` enables it above 3.5 GB of RAM |
| `MODELS` | `auto` | `off` skips the background download |
| `BRANCH` | `main` | Branch to deploy |
| `APP_DIR` | `/var/www/george` | Install directory |
| `NGINX_FORCE` | `off` | `on` regenerates the virtual host even when it already carries the certificate |

```bash
curl -fsSL <url> | sudo DOMAIN=george.example.com EMAIL=me@example.com bash
```

Sizing. The installer reads `/proc/meminfo` and `nproc` and picks the stack from them:

| RAM | Cores | Slot C |
| --- | --- | --- |
| ≥ 24 GB | ≥ 16 | llama.cpp, `Qwen/Qwen3-14B-GGUF` |
| ≥ 12 GB | ≥ 6 | llama.cpp, `Qwen/Qwen3-8B-GGUF` |
| ≥ 8 GB | any | ONNX in-process, `Qwen3-4B-Instruct-2507` |
| < 8 GB | any | off — George stays lexical |

4 GB runs the two NLI heads, and below 3.5 GB George falls back to a single head. Roughly 12 GB of disk either way. Override any of it with `REASONER_BACKEND=llama LLAMA_REPO=… curl … | sudo bash`.

The memory is the easy part; the cores are what decide. Every Run is ten reasoner forward passes, so a 14B on four cores is a slower answer, not a better one. Run `php artisan george:bench` on the box before committing to a tier.

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
| Reasoner logs (llama backend) | `journalctl -fu george-llama` |
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
| `GEORGE_REASONER_BACKEND` | `onnx` | `onnx` (in-process) or `llama` (llama-server over HTTP) |
| `GEORGE_REASONER_DEBIAS` | `true` | Score twice with reversed options and average |
| `GEORGE_REASONER_MODEL` | `onnx-community/Qwen3-4B-Instruct-2507-ONNX` | **onnx backend.** Any causal LM TransformersPHP can load (Qwen2.5, Qwen3, Llama, Phi-3) |
| `GEORGE_REASONER_FILE` | `model_q4` | **onnx backend.** ONNX variant inside the repo's `onnx/` folder |
| `GEORGE_REASONER_FORMAT` | `chatml_plain` | **onnx backend.** Chat turn layout: `chatml`, `chatml_plain`, `phi3`, `llama3` |
| `GEORGE_LLAMA_URL` | `http://127.0.0.1:8080` | **llama backend.** Where llama-server answers |
| `GEORGE_LLAMA_REPO` | `Qwen/Qwen3-8B-GGUF` | **llama backend.** What the unit serves, for the status line |
| `GEORGE_LLAMA_QUANT` | `Q4_K_M` | **llama backend.** Same |
| `GEORGE_LLAMA_FORMAT` | `chatml` | **llama backend.** Chat turn layout |
| `GEORGE_LLAMA_N_PROBS` | `40` | **llama backend.** Candidates asked of the sampler; the letters have to be among them |
| `GEORGE_LLAMA_CACHE_PROMPT` | `true` | **llama backend.** Reuse the KV of the shared prompt prefix |
| `GEORGE_WEIGHT_A` / `_B` / `_C` | `0.25` / `0.15` / `0.60` | Pooling weights, normalised over running slots |
| `GEORGE_QUANTIZED` | `true` | Smaller ONNX weights for the NLI slots |
| `GEORGE_TEMPERATURE` | `1.0` | Softmax temperature on the NLI slots. T>1 flattens peaks. **Not** real calibration. |

## What this does not give you

- **Calibration.** The slot weights and the agreement temperature are hand-set on a 24-case benchmark, not fitted. Apply temperature scaling or isotonic regression on a labelled domain set, and measure ECE, before you put thresholds in a policy.
- **Deep reasoning.** The reasoner answers in one token. It gets the obvious operational calls (page, route, interview) and misses subtle ones: sarcasm, borderline candidates. A bigger model helps, and the two backends below are how you get one — but measure it, because more parameters also means more seconds per Run.
- **Long context.** ~512 tokens for the NLI slots. 2000 characters is the UI cap.

Each NLI label is one forward pass; the reasoner adds two passes per condition. Five conditions with 3–5 labels each is 15–25 NLI passes plus 10 reasoner passes on CPU.

## Choosing a reasoner

Slot C is one abstraction — a prompt in, a distribution over the option letters
out — with two backends under it. Both read the model the same way: one forward
pass, no generation, the next-token probabilities restricted to `A`, `B`, `C`…
and renormalised. They differ only in what runs the weights.

| | `onnx` | `llama` |
| --- | --- | --- |
| Where | TransformersPHP, in the slot C worker | `llama-server`, its own process |
| Ceiling | ~4B | whatever the RAM holds |
| Extra service | none | one |
| Prompt cache between conditions | no | yes |
| Weights | `george:download` | llama-server fetches its own |

### The onnx backend

The default. No service to run, and the model loads into the same PHP process
as the queue worker. The ONNX build has to use a **float32 KV cache**:
`model_q4`, `model_int8` or `model_quantized`. A `q4f16` build fails at the
first forward pass, because the PHP ONNX binding has no mapping for float16
tensors.

| Model | `GEORGE_REASONER_FILE` | `GEORGE_REASONER_FORMAT` | Disk |
| --- | --- | --- | --- |
| `onnx-community/Qwen3-4B-Instruct-2507-ONNX` | `model_q4` | `chatml_plain` | 4.0 GB |
| `onnx-community/Llama-3.2-3B-Instruct-ONNX` | `model_q4` | `llama3` | 3.4 GB |
| `Xenova/Phi-3-mini-4k-instruct` | `model_q4` | `phi3` | 2.7 GB |
| `onnx-community/Qwen3-1.7B-ONNX` | `model_q4` | `chatml` | 1.8 GB |
| `onnx-community/Qwen2.5-1.5B-Instruct` | `model_q4` | `chatml_plain` | 1.8 GB |

The 4B at the top of that table is the ceiling, not a preference. Above it the
repos stop being loadable rather than getting slower: `Qwen3-8B-ONNX` and
`Qwen3-14B-ONNX` ship an ONNX Runtime GenAI layout (`genai_config.json`, a
`cpu-int4-*` subfolder) that TransformersPHP does not read, and the Qwen3.5
repos split the graph into `embed_tokens` + `decoder_model_merged` +
`vision_encoder`, which `AutoModelForCausalLM` will not assemble. Past 4B, the
answer is the other backend.

Use `chatml` only for a Qwen3 model with a thinking mode, where the prompt has
to close an empty think block so the answer arrives in one forward pass. The
2507 Instruct release has no thinking mode, so it takes `chatml_plain`.

Models over roughly 2 GB keep their weights in `model_q4.onnx_data` files next
to the graph. `george:download` fetches those too; a hand-rolled download that
grabs only the `.onnx` file will load an empty graph.

### The llama backend

```env
GEORGE_REASONER_BACKEND=llama
GEORGE_LLAMA_URL=http://127.0.0.1:8080
GEORGE_LLAMA_REPO=Qwen/Qwen3-8B-GGUF
GEORGE_LLAMA_QUANT=Q4_K_M
GEORGE_LLAMA_FORMAT=chatml
```

```bash
llama-server --host 127.0.0.1 --port 8080 \
    -hf Qwen/Qwen3-8B-GGUF:Q4_K_M \
    --ctx-size 4096 --threads $(nproc) --parallel 1 --cache-reuse 256
```

George posts to `/completion` with `n_predict: 1` and `n_probs`, and reads the
letters out of the returned distribution. It never asks for generated text, so
the model's verbosity, its thinking mode and its stop tokens do not matter.

`--parallel 1` and `cache_prompt` are the reason this is fast. The prompt is
built prefix-stable — rulebook and worked examples first, then the situation,
then the question and the options — so within one Run llama-server re-reads
only the last ~80 tokens of each of the ten passes instead of all ~900.

| Repo | RAM | Cores it wants |
| --- | --- | --- |
| `Qwen/Qwen3-4B-GGUF` | ~3 GB | 4 |
| `Qwen/Qwen3-8B-GGUF` | ~5 GB | 6 |
| `Qwen/Qwen3-14B-GGUF` | ~9 GB | 16 |

Those are the tiers `deploy/install.sh` picks between. Anything llama.cpp loads
works; set `GEORGE_LLAMA_FORMAT` to match the family, and keep
`GEORGE_LLAMA_N_PROBS` well above your longest option list.

If llama-server is not answering, slot C reports itself as not ready and George
falls back the same way it does with missing ONNX weights: the banner says the
reasoner is off, and the NLI pair carries the Run.

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

| Reasoner | Backend | Agreement | Decided by option order | s / condition |
| --- | --- | --- | --- | --- |
| Qwen3-1.7B | onnx | 10 / 20 | 9 / 20 | 3.9 |

Run it again for whatever you configure. The numbers that matter are yours: the
same model is several times slower on two shared vCPUs than on a laptop, and a
tier that answers well but takes a minute per Run is not the tier to ship.

## Benchmark against Jev

`storage/app/george-jev-bench.php` runs 24 situations through George and TypeSafe's Try Jev API and writes `storage/app/george-jev-bench.json`. Pass `--reuse-jev` to keep the Jev answers of the previous run (no API calls, no 429s). Score positions are compared on Jev's 0-based scale.

## Tests

```bash
php artisan test
```

Tests use the fake engine. They do not download the ONNX model.

## Privacy

Situations are stored in local SQLite for at most 24 hours (`model:prune`). No third-party inference API.
