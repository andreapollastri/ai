import { messages } from './i18n';
import { examples } from './examples';

const root = document.getElementById('app');
const boot = window.__GEORGE__ ?? {};
const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const uid = () =>
    typeof crypto !== 'undefined' && crypto.randomUUID
        ? crypto.randomUUID()
        : `c-${Math.random().toString(36).slice(2)}`;

const withIds = (conditions) =>
    conditions.map((condition) => ({
        id: uid(),
        ...structuredClone(condition),
        options: (condition.options ?? []).map((option) => ({ ...option })),
        levels: [...(condition.levels ?? [])],
    }));

const emptyNoul = (t) => ({
    id: uid(),
    type: 'noul',
    name: '',
    prompt: '',
    yes: '',
    no: '',
});

const emptyChoice = () => ({
    id: uid(),
    type: 'choice',
    name: '',
    prompt: '',
    options: [
        { label: '', applies: '' },
        { label: '', applies: '' },
    ],
});

const emptyScore = () => ({
    id: uid(),
    type: 'score',
    name: '',
    prompt: '',
    levels: ['', '', '', ''],
});

function t(key) {
    return messages[key] ?? key;
}

const storedTheme = localStorage.getItem('george.theme') ?? 'system';

const state = {
    theme: storedTheme,
    guideOpen: false,
    exampleKey: '',
    situation: '',
    conditions: [],
    readout: null,
    running: false,
    error: null,
};

function looksItalian(text) {
    const italian = (text.match(/\b(il|lo|la|gli|le|del|della|che|non|per|una|un|sono|questa|questo|essere|con|come|più|anche|degli|nelle|sulla|agli|delle|dei|vorrei|rimborso|urgente|ordine|cliente)\b/gi) || []).length;
    const english = (text.match(/\b(the|and|of|to|in|is|that|for|with|this|are|from|have|not|was|were|you|your)\b/gi) || []).length;

    return italian >= 2 && italian > english;
}

function applyTheme() {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const dark = state.theme === 'dark' || (state.theme === 'system' && prefersDark);
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
}

function themeLabel() {
    if (state.theme === 'light') {
        return t('themeLight');
    }

    if (state.theme === 'dark') {
        return t('themeDark');
    }

    return t('themeSystem');
}

function cycleTheme() {
    state.theme = state.theme === 'system' ? 'light' : state.theme === 'light' ? 'dark' : 'system';
    localStorage.setItem('george.theme', state.theme);
    applyTheme();
    render();
}

function loadExample(key) {
    const pack = examples[key];

    if (!pack) {
        return;
    }

    state.exampleKey = key;
    state.situation = pack.situation;
    state.conditions = withIds(pack.conditions);
    state.readout = null;
    state.error = null;
    render();
}

function exampleOptions() {
    return Object.entries(examples)
        .map(
            ([key, pack]) =>
                `<option value="${escapeAttr(key)}"${key === state.exampleKey ? ' selected' : ''}>${escapeHtml(pack.label)}</option>`,
        )
        .join('');
}

function resetAll() {
    state.exampleKey = '';
    state.situation = '';
    state.conditions = [];
    state.readout = null;
    state.error = null;
    render();
}

function canAdd() {
    return state.conditions.length < (boot.maxConditions ?? 5);
}

function payloadConditions() {
    return state.conditions.map((condition) => {
        if (condition.type === 'noul') {
            return {
                type: 'noul',
                name: condition.name.trim(),
                prompt: condition.prompt.trim(),
                yes: condition.yes.trim(),
                no: condition.no.trim(),
            };
        }

        if (condition.type === 'choice') {
            return {
                type: 'choice',
                name: condition.name.trim(),
                prompt: condition.prompt.trim(),
                options: condition.options
                    .map((option) => ({
                        label: option.label.trim(),
                        applies: option.applies.trim(),
                    }))
                    .filter((option) => option.label !== ''),
            };
        }

        return {
            type: 'score',
            name: condition.name.trim(),
            prompt: condition.prompt.trim(),
            levels: condition.levels.map((level) => level.trim()).filter(Boolean),
        };
    });
}

async function run() {
    if (state.running || !state.situation.trim() || state.conditions.length === 0) {
        return;
    }

    state.running = true;
    state.error = null;
    state.readout = { status: 'running' };
    render();

    try {
        const response = await fetch('/evaluations', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                situation: state.situation.trim(),
                locale: 'en',
                conditions: payloadConditions(),
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            const first = data.errors ? Object.values(data.errors).flat()[0] : data.message;
            throw new Error(first || t('error'));
        }

        if (data.status === 'done') {
            state.readout = data;
            return;
        }

        state.readout = await poll(data.id);
    } catch (error) {
        state.error = error.message || t('error');
        state.readout = null;
    } finally {
        state.running = false;
        render();
    }
}

async function poll(id) {
    const deadline = Date.now() + 180_000;

    while (Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, 400));
        const response = await fetch(`/evaluations/${id}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();

        if (data.status === 'done') {
            return data;
        }

        if (data.status === 'failed') {
            throw new Error(data.error || t('error'));
        }
    }

    throw new Error(t('error'));
}

function field(label, value, onInput, placeholder = '') {
    return `
        <label class="field">
            <span>${label}</span>
            <input type="text" data-bind="${onInput}" value="${escapeAttr(value)}" placeholder="${escapeAttr(placeholder)}">
        </label>
    `;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}

function escapeAttr(value) {
    return escapeHtml(value).replaceAll('"', '&quot;');
}

function pct(value) {
    return `${Math.round(value * 1000) / 10}%`;
}

function fmt(value) {
    return Number(value).toFixed(2);
}

function conditionCard(condition, index) {
    const typeLabel =
        condition.type === 'noul' ? `${t('addNoul')} <i>${t('addNoulHint')}</i>` : condition.type === 'choice' ? `${t('addChoice')} <i>${t('addChoiceHint')}</i>` : `${t('addScore')} <i>${t('addScoreHint')}</i>`;

    let body = `
        ${field(t('name'), condition.name, `name:${condition.id}`)}
        ${field(t('prompt'), condition.prompt, `prompt:${condition.id}`, condition.type === 'noul' ? t('promptPlaceholderNoul') : condition.type === 'choice' ? t('promptPlaceholderChoice') : t('promptPlaceholderScore'))}
    `;

    if (condition.type === 'noul') {
        body += `
            ${field(t('countsYes'), condition.yes, `yes:${condition.id}`, t('yesPlaceholder'))}
            ${field(t('countsNo'), condition.no, `no:${condition.id}`, t('noPlaceholder'))}
        `;
    }

    if (condition.type === 'choice') {
        body += `<ul class="stack">`;
        condition.options.forEach((option, optionIndex) => {
            body += `
                <li class="option-row">
                    ${field(t('option'), option.label, `opt-label:${condition.id}:${optionIndex}`)}
                    ${field(t('applies'), option.applies, `opt-applies:${condition.id}:${optionIndex}`)}
                    <button type="button" class="icon-btn" data-action="remove-option" data-id="${condition.id}" data-index="${optionIndex}" aria-label="Remove option">×</button>
                </li>
            `;
        });
        body += `</ul>
            <button type="button" class="text-btn" data-action="add-option" data-id="${condition.id}">${t('addOption')}</button>`;
    }

    if (condition.type === 'score') {
        body += `<p class="hint">${t('lowestFirst')}</p><ul class="stack">`;
        condition.levels.forEach((level, levelIndex) => {
            body += `
                <li class="option-row">
                    ${field(`${t('level')} ${levelIndex + 1}`, level, `level:${condition.id}:${levelIndex}`)}
                    <button type="button" class="icon-btn" data-action="remove-level" data-id="${condition.id}" data-index="${levelIndex}" aria-label="Remove level">×</button>
                </li>
            `;
        });
        body += `</ul>
            <button type="button" class="text-btn" data-action="add-level" data-id="${condition.id}">${t('addLevel')}</button>`;
    }

    return `
        <article class="card condition" data-type="${condition.type}">
            <header>
                <span class="type-label">${typeLabel}</span>
            </header>
            ${body}
            <button type="button" class="text-btn remove" data-action="remove-condition" data-index="${index}">${t('removeCondition')}</button>
        </article>
    `;
}

function bars(items, key = 'score') {
    const max = Math.max(...items.map((item) => item[key]), 0.0001);

    return `
        <ul class="bars">
            ${items
                .map(
                    (item) => `
                <li>
                    <div class="bar-meta">
                        <span>${escapeHtml(item.label)}</span>
                        <strong>${fmt(item[key])}</strong>
                    </div>
                    <div class="bar-track"><i style="width:${(item[key] / max) * 100}%"></i></div>
                </li>
            `,
                )
                .join('')}
        </ul>
    `;
}

function renderAnswer(answer) {
    if (answer.type === 'noul') {
        return `
            <article class="readout-card">
                <header>
                    <span class="type-label">${t('addNoul')} <i>noul</i></span>
                    <h3>${escapeHtml(answer.name)}</h3>
                    <p>${escapeHtml(answer.prompt)}</p>
                </header>
                <div class="hero-number">${fmt(answer.p_yes)}</div>
                <p class="hero-caption">${t('pYes')}</p>
                ${bars([
                    { label: t('yes'), score: answer.p_yes },
                    { label: t('no'), score: answer.p_no },
                ])}
            </article>
        `;
    }

    if (answer.type === 'choice') {
        return `
            <article class="readout-card">
                <header>
                    <span class="type-label">${t('addChoice')} <i>choice</i></span>
                    <h3>${escapeHtml(answer.name)}</h3>
                    <p>${escapeHtml(answer.prompt)}</p>
                </header>
                ${bars(answer.distribution)}
            </article>
        `;
    }

    const ratio = (answer.position - answer.min) / Math.max(answer.max - answer.min, 1);

    return `
        <article class="readout-card">
            <header>
                <span class="type-label">${t('addScore')} <i>score</i></span>
                <h3>${escapeHtml(answer.name)}</h3>
                <p>${escapeHtml(answer.prompt)}</p>
            </header>
            <div class="hero-number">${fmt(answer.position)}</div>
            <p class="hero-caption">${t('position')} · ${answer.min}–${answer.max}</p>
            <div class="scale">
                <i style="left:${ratio * 100}%"></i>
            </div>
            ${bars(answer.distribution)}
        </article>
    `;
}

function readoutHtml() {
    if (state.running) {
        return `<div class="empty running"><span class="pulse"></span>${t('running')}</div>`;
    }

    if (state.error) {
        return `<div class="empty error">${escapeHtml(state.error)}</div>`;
    }

    if (!state.readout?.result) {
        return `
            <div class="empty">
                <p>${t('readoutEmpty')}</p>
                <p>${t('readoutHelp')}</p>
            </div>
        `;
    }

    const meta = state.readout.result.meta ?? {};
    const warning = [
        meta.english_warning ? `<p class="banner warn">${t('italianWarning')}</p>` : '',
        meta.token_warning ? `<p class="banner warn">${t('tokenWarning')}</p>` : '',
    ].join('');

    return `
        ${warning}
        <div class="answers">
            ${(state.readout.result.answers ?? []).map(renderAnswer).join('')}
        </div>
        <dl class="meta">
            <div><dt>${t('aboutModel')}</dt><dd>${escapeHtml(meta.model ?? '')}</dd></div>
            <div><dt>${t('aboutEngine')}</dt><dd>${escapeHtml(meta.engine ?? '')}</dd></div>
            <div><dt>${t('aboutPasses')}</dt><dd>${meta.forward_passes ?? '—'}</dd></div>
            <div><dt>${t('aboutElapsed')}</dt><dd>${state.readout.elapsed_ms ? `${state.readout.elapsed_ms} ms` : '—'}</dd></div>
        </dl>
    `;
}

function render() {
    const max = boot.maxSituation ?? 2000;
    const count = state.situation.length;

    root.innerHTML = `
        <div class="shell">
            <header class="top">
                <a class="logo" href="/">TRY<span>GEORGE</span></a>
                <nav>
                    <a href="#how">${t('article')}</a>
                    <a href="/privacy">${t('privacy')}</a>
                    <button type="button" class="theme" data-action="theme" title="${escapeAttr(themeLabel())}">◐ ${themeLabel()}</button>
                </nav>
            </header>

            ${boot.engine === 'fake' ? `<p class="banner">${t('fakeBanner')}</p>` : ''}

            <section class="hero">
                <h1>${t('headline')} <em>${t('headlineAccent')}</em></h1>
                <p class="lede">${t('intro')}</p>
                <button type="button" class="disclosure" data-action="guide">
                    <span>${state.guideOpen ? '▼' : '▶'}</span> ${t('startHere')}
                </button>
                ${
                    state.guideOpen
                        ? `
                    <div class="guide" id="how">
                        <p><strong>${t('guide1Title')}</strong> ${t('guide1')}</p>
                        <p><strong>${t('guide2Title')}</strong> ${t('guide2')}</p>
                        <p><strong>${t('guide3Title')}</strong> ${t('guide3')}</p>
                        <p><strong>${t('guide4Title')}</strong> ${t('guide4')}</p>
                        <p><strong>${t('guide5Title')}</strong> ${t('guide5')}</p>
                        <p>${t('noulNote')}</p>
                    </div>
                `
                        : ''
                }
            </section>

            <div class="workbench">
                <div class="pane pane-input">
                    <div class="examples">
                        <label for="example-picker">${t('loadExample')}</label>
                        <select id="example-picker" data-select="example">
                            <option value="">${t('examplePlaceholder')}</option>
                            ${exampleOptions()}
                        </select>
                        <span class="examples-hint">${t('exampleHint')}</span>
                    </div>

                    <section class="block">
                        <header class="kicker">
                            <h2>${t('situationKicker')}</h2>
                            <span class="${count > max ? 'over' : ''}">${count} / ${max}</span>
                        </header>
                        <aside class="lang-callout">
                            <strong>${t('situationLangKicker')}</strong>
                            <p>${t('situationLang')}</p>
                        </aside>
                        <label class="sr-only" for="situation">${t('situationLabel')}</label>
                        <textarea id="situation" maxlength="${max}" placeholder="${escapeAttr(t('situationPlaceholder'))}">${escapeHtml(state.situation)}</textarea>
                        ${looksItalian(state.situation) ? `<p class="banner warn italian-warn">${t('italianWarning')}</p>` : '<p class="banner warn italian-warn" hidden></p>'}
                        <p class="hint">${t('situationHint')}</p>
                    </section>

                    <section class="block">
                        <header class="kicker">
                            <h2>${t('conditionsKicker')}</h2>
                            <span>${state.conditions.length} / ${boot.maxConditions ?? 5}</span>
                        </header>
                        ${
                            state.conditions.length === 0
                                ? `<p class="empty-line">${t('noConditions')}</p>`
                                : state.conditions.map(conditionCard).join('')
                        }
                        <div class="adders">
                            <button type="button" data-action="add-noul" ${canAdd() ? '' : 'disabled'}>+ ${t('addNoul')} <i>${t('addNoulHint')}</i></button>
                            <button type="button" data-action="add-choice" ${canAdd() ? '' : 'disabled'}>+ ${t('addChoice')} <i>${t('addChoiceHint')}</i></button>
                            <button type="button" data-action="add-score" ${canAdd() ? '' : 'disabled'}>+ ${t('addScore')} <i>${t('addScoreHint')}</i></button>
                        </div>
                    </section>
                </div>

                <div class="runbar">
                    <button type="button" class="run" data-action="run" ${state.running || !state.situation.trim() || state.conditions.length === 0 ? 'disabled' : ''}>
                        <span>${t('run')}</span>
                    </button>
                    <button type="button" class="ghost" data-action="reset">${t('reset')}</button>
                    <p class="aside">${t('oneRequest')}<br>${t('noUsa')}</p>
                    <p class="run-hint">${t('runHint')}</p>
                </div>

                <div class="pane pane-output">
                    <section class="block readout">
                        <header class="kicker">
                            <h2>${t('readoutKicker')}</h2>
                        </header>
                        ${readoutHtml()}
                    </section>
                </div>
            </div>

            <footer class="foot">Made in one hour by <a href="https://web.ap.it" rel="noopener noreferrer">Andrea Pollastri</a></footer>
        </div>
    `;

    bind();
}

function findCondition(id) {
    return state.conditions.find((condition) => condition.id === id);
}

function bind() {
    const situation = root.querySelector('#situation');
    situation?.addEventListener('input', (event) => {
        state.situation = event.target.value;
        const counter = root.querySelector('.block .kicker span');
        if (counter) {
            counter.textContent = `${state.situation.length} / ${boot.maxSituation ?? 2000}`;
        }
        const run = root.querySelector('[data-action="run"]');
        if (run) {
            run.disabled = state.running || !state.situation.trim() || state.conditions.length === 0;
        }
        const italianWarn = root.querySelector('.italian-warn');
        if (italianWarn) {
            const italian = looksItalian(state.situation);
            italianWarn.hidden = !italian;
            if (italian) {
                italianWarn.textContent = t('italianWarning');
            }
        }
    });

    const picker = root.querySelector('[data-select="example"]');
    if (picker) {
        picker.addEventListener('change', () => loadExample(picker.value));
    }

    root.querySelectorAll('[data-bind]').forEach((el) => {
        el.addEventListener('input', () => {
            const [kind, id, index] = el.dataset.bind.split(':');
            const condition = findCondition(id);
            if (!condition) {
                return;
            }

            const value = el.value;

            if (kind === 'name') condition.name = value;
            if (kind === 'prompt') condition.prompt = value;
            if (kind === 'yes') condition.yes = value;
            if (kind === 'no') condition.no = value;
            if (kind === 'opt-label') condition.options[Number(index)].label = value;
            if (kind === 'opt-applies') condition.options[Number(index)].applies = value;
            if (kind === 'level') condition.levels[Number(index)] = value;
        });
    });

    root.querySelectorAll('[data-action]').forEach((el) => {
        el.addEventListener('click', () => {
            const action = el.dataset.action;

            if (action === 'theme') cycleTheme();
            if (action === 'guide') {
                state.guideOpen = !state.guideOpen;
                render();
            }
            if (action === 'add-noul' && canAdd()) {
                state.conditions.push(emptyNoul());
                render();
            }
            if (action === 'add-choice' && canAdd()) {
                state.conditions.push(emptyChoice());
                render();
            }
            if (action === 'add-score' && canAdd()) {
                state.conditions.push(emptyScore());
                render();
            }
            if (action === 'remove-condition') {
                state.conditions.splice(Number(el.dataset.index), 1);
                render();
            }
            if (action === 'add-option') {
                const condition = findCondition(el.dataset.id);
                if (condition && condition.options.length < (boot.maxOptions ?? 8)) {
                    condition.options.push({ label: '', applies: '' });
                    render();
                }
            }
            if (action === 'remove-option') {
                const condition = findCondition(el.dataset.id);
                if (condition && condition.options.length > 2) {
                    condition.options.splice(Number(el.dataset.index), 1);
                    render();
                }
            }
            if (action === 'add-level') {
                const condition = findCondition(el.dataset.id);
                if (condition && condition.levels.length < (boot.maxLevels ?? 7)) {
                    condition.levels.push('');
                    render();
                }
            }
            if (action === 'remove-level') {
                const condition = findCondition(el.dataset.id);
                if (condition && condition.levels.length > 2) {
                    condition.levels.splice(Number(el.dataset.index), 1);
                    render();
                }
            }
            if (action === 'reset') resetAll();
            if (action === 'run') run();
        });
    });
}

applyTheme();
document.documentElement.lang = 'en';
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (state.theme === 'system') {
        applyTheme();
    }
});
render();
