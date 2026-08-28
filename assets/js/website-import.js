/**
 * Website catalog import — preview + chunked import from store URL.
 */
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('website-import-form');
    if (!form) return;

    const previewBtn = form.querySelector('[data-website-preview]');
    const importBtn = form.querySelector('[data-website-import]');
    const clearBtn = form.querySelector('[data-website-clear]');
    const statusEl = document.getElementById('website-import-status');
    const previewEl = document.getElementById('website-import-preview');
    const urlInput = form.querySelector('[name="website_url"]');
    const csrf = form.querySelector('[name="csrf_token"]')?.value || '';
    const botId = form.querySelector('[name="bot_id"]')?.value || '';

    let activeController = null;
    let elapsedTimer = null;
    let busyStartedAt = 0;
    const buttonSnapshots = new Map();

    const snapshotButton = (btn) => {
        if (!btn || buttonSnapshots.has(btn)) return;
        buttonSnapshots.set(btn, {
            html: btn.innerHTML,
            label: btn.querySelector('[data-btn-label]')?.textContent || btn.textContent.trim(),
        });
    };

    [previewBtn, importBtn, clearBtn].forEach(snapshotButton);

    const setStatus = (text, isError = false) => {
        if (!statusEl) return;
        statusEl.textContent = text;
        statusEl.classList.toggle('text-error', isError);
        statusEl.classList.toggle('text-primary', !isError && text !== '');
    };

    const formatElapsed = (ms) => {
        const sec = Math.max(0, Math.floor(ms / 1000));
        if (sec < 60) return `${sec}s`;
        return `${Math.floor(sec / 60)}m ${sec % 60}s`;
    };

    const setButtonBusy = (btn, busy, busyLabel) => {
        if (!btn) return;
        const snap = buttonSnapshots.get(btn);
        if (!snap) return;

        if (busy) {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.classList.add('opacity-80', 'pointer-events-none');
            btn.innerHTML = `<svg class="iqp-spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg><span data-btn-label>${busyLabel}</span>`;
            return;
        }

        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        btn.classList.remove('opacity-80', 'pointer-events-none');
        btn.innerHTML = snap.html;
    };

    const setBusy = (busy, activeBtn = null, busyLabel = 'Working…') => {
        if (busy) {
            busyStartedAt = Date.now();
            if (elapsedTimer) window.clearInterval(elapsedTimer);
            elapsedTimer = window.setInterval(() => {
                const statusText = statusEl?.textContent || '';
                if (!statusText || statusEl?.classList.contains('text-error')) return;
                const base = statusText.replace(/\s\(\d+[ms]\)$/, '');
                setStatus(`${base} (${formatElapsed(Date.now() - busyStartedAt)})`);
            }, 1000);
        } else if (elapsedTimer) {
            window.clearInterval(elapsedTimer);
            elapsedTimer = null;
            busyStartedAt = 0;
        }

        [previewBtn, importBtn, clearBtn].forEach((btn) => {
            if (!btn) return;
            if (busy) {
                btn.disabled = true;
                if (btn === activeBtn) {
                    setButtonBusy(btn, true, busyLabel);
                } else {
                    btn.classList.add('opacity-50', 'pointer-events-none');
                }
            } else {
                setButtonBusy(btn, false);
                btn.classList.remove('opacity-50', 'pointer-events-none');
            }
        });
    };

    const parseResponse = async (res) => {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (_) {
            if (text.trimStart().startsWith('<!DOCTYPE') || text.trimStart().startsWith('<html')) {
                throw new Error('Server error (HTTP ' + res.status + '). Upload api/website-import.php and includes/website-import.php.');
            }
            throw new Error('Invalid server response (HTTP ' + res.status + ').');
        }
    };

    const networkErrorMessage = (err, action) => {
        if (err?.name === 'AbortError') {
            return action === 'preview'
                ? 'Preview timed out. Try again — large stores can take up to 2 minutes.'
                : 'Import timed out. The server may have hit its time limit — try Preview first, then Import again.';
        }
        const msg = String(err?.message || '');
        if (/failed to fetch|networkerror|load failed/i.test(msg)) {
            return action === 'preview'
                ? 'Could not reach the server during preview. Check your connection and try again.'
                : 'Server timed out while importing. We now import in batches — upload the latest website-import.js and try again.';
        }
        return msg || 'Request failed. Check your connection and try again.';
    };

    const renderPreview = (sample = []) => {
        if (!previewEl) return;
        if (!sample.length) {
            previewEl.innerHTML = '';
            previewEl.classList.add('hidden');
            return;
        }

        previewEl.classList.remove('hidden');
        previewEl.innerHTML = sample.map((p) => {
            const price = p.currency === 'PKR'
                ? 'PKR ' + Number(p.price || 0).toLocaleString('en-PK', { maximumFractionDigits: 0 })
                : (p.currency || 'USD') + ' ' + Number(p.price || 0).toFixed(2);
            const img = p.image_url
                ? `<img src="${p.image_url.replace(/"/g, '&quot;')}" alt="" class="w-12 h-12 rounded-lg object-cover shrink-0 aspect-square"/>`
                : '<div class="w-12 h-12 rounded-lg bg-surface-container shrink-0 aspect-square"></div>';
            const desc = p.description ? `<p class="text-label-sm text-outline truncate">${p.description}</p>` : '';
            return `<div class="flex items-center gap-sm p-sm rounded-xl bg-surface-container border border-outline-variant/50">
                ${img}
                <div class="min-w-0">
                    <p class="font-medium text-body-md truncate">${p.name || 'Product'}</p>
                    <p class="text-label-sm text-primary">${price}${p.category ? ' · ' + p.category : ''}</p>
                    ${desc}
                </div>
            </div>`;
        }).join('');
    };

    const postJson = async (payload, action, timeoutMs = 120000) => {
        if (activeController) activeController.abort();
        activeController = new AbortController();
        const timer = window.setTimeout(() => activeController.abort(), timeoutMs);

        try {
            const res = await fetch('/api/website-import.php', {
                method: 'POST',
                credentials: 'same-origin',
                signal: activeController.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': csrf,
                },
                body: JSON.stringify({ ...payload, csrf_token: csrf, bot_id: botId }),
            });
            return await parseResponse(res);
        } catch (err) {
            throw new Error(networkErrorMessage(err, action));
        } finally {
            window.clearTimeout(timer);
            activeController = null;
        }
    };

    const INDOLJ_MENU_BASES = [
        'https://console.indolj.io/mobileapp/WebApiV2/StructuredMenu',
        'https://menu.indolj.io/mobileapp/WebApiV2/StructuredMenu',
    ];

    const fetchIndoljMenuForBranch = async (ctx, branchId, baseUrl) => {
        const params = new URLSearchParams({
            domain: ctx.domain,
            json: '1',
            api_version: ctx.api_version || '0.0.31',
        });
        if (branchId) {
            params.set('branch_id', String(branchId));
        }

        const res = await fetch(`${baseUrl}?${params}`, {
            method: 'GET',
            headers: {
                Authorization: `Bearer ${ctx.token}`,
                Accept: 'application/json',
            },
            mode: 'cors',
            credentials: 'omit',
        });

        if (!res.ok) {
            return null;
        }

        const data = await res.json();
        return data && Number(data.code) === 1 ? data : null;
    };

    /** Fetch full Indolj StructuredMenu from the user's browser (bypasses server IP blocks). */
    const fetchIndoljMenusInBrowser = async (ctx) => {
        const branches = Array.isArray(ctx.branches) && ctx.branches.length
            ? ctx.branches
            : [''];
        const menus = [];
        const seen = new Set();

        for (const branchId of branches) {
            let menu = null;
            for (const baseUrl of INDOLJ_MENU_BASES) {
                try {
                    menu = await fetchIndoljMenuForBranch(ctx, branchId, baseUrl);
                } catch (_) {
                    menu = null;
                }
                if (menu) {
                    break;
                }
            }
            if (!menu) {
                continue;
            }

            const key = String(branchId || menu.details?.branch_id || menus.length);
            if (seen.has(key)) {
                continue;
            }
            seen.add(key);
            menus.push(menu);
        }

        return menus;
    };

    const runIndoljBrowserImport = async (url, ctx) => {
        const ttl = ctx.token_ttl;
        if (typeof ttl === 'number' && ttl < 60) {
            setStatus('Store session token is expiring — click Preview first, then Import right away.', true);
            return null;
        }

        setStatus('Fetching full menu from Indolj (your browser)…');

        let menus;
        try {
            menus = await fetchIndoljMenusInBrowser(ctx);
        } catch (err) {
            const msg = String(err?.message || '');
            if (/failed to fetch|networkerror|load failed|cors/i.test(msg)) {
                setStatus(
                    'Could not load Indolj menu from this page (browser blocked cross-origin request). '
                    + 'Open your store menu in another tab, then try Import again, or contact support for CSV import.',
                    true
                );
            } else {
                setStatus(msg || 'Indolj menu fetch failed.', true);
            }
            return null;
        }

        if (!menus.length) {
            setStatus(
                'Indolj returned no menu items (token expired or API blocked). Click Preview, then Import within a minute.',
                true
            );
            return null;
        }

        setStatus(`Uploading ${menus.length} menu branch(es)…`);
        const result = await postJson({ action: 'import_indolj_browser', url, menus }, 'import', 180000);
        if (!result?.success) {
            setStatus(result?.error || result?.message || 'Import failed.', true);
            return null;
        }

        return result;
    };

    const callPreview = async () => {
        const url = urlInput?.value?.trim();
        if (!url) {
            setStatus('Enter your store website URL first.', true);
            return null;
        }

        setStatus('Scanning website sample (15–30 sec)…');
        setBusy(true, previewBtn, 'Previewing…');

        try {
            return await postJson({ action: 'preview', url }, 'preview', 120000);
        } catch (err) {
            setStatus(err.message, true);
            return null;
        } finally {
            setBusy(false);
        }
    };

    const runChunkedImport = async () => {
        const url = urlInput?.value?.trim();
        if (!url) {
            setStatus('Enter your store website URL first.', true);
            return null;
        }

        setStatus('Fetching product list from website…');
        setBusy(true, importBtn, 'Importing…');

        try {
            const start = await postJson({ action: 'import_start', url }, 'import', 180000);
            if (!start?.success) {
                setStatus(start?.error || start?.message || 'Import failed.', true);
                return null;
            }

            if (start.needs_browser && start.indolj_browser) {
                const indolj = await runIndoljBrowserImport(url, start.indolj_browser);
                if (!indolj?.success) {
                    return null;
                }
                const saved = Number(indolj.imported ?? indolj.total ?? 0);
                return {
                    success: true,
                    message: indolj.message || 'Import complete.',
                    saved_count: saved,
                    total: Number(indolj.total ?? saved),
                };
            }

            const jobId = start.job_id;
            const total = Number(start.total || 0);
            if (!jobId || total <= 0) {
                setStatus(start.message || 'No products found to import.', true);
                return null;
            }

            setStatus(start.message || `Importing 0 / ${total} products…`);

            let done = false;
            let lastMessage = start.message || '';
            let savedCount = 0;

            while (!done) {
                setButtonBusy(importBtn, true, 'Importing…');
                const batch = await postJson({ action: 'import_batch', job_id: jobId, url }, 'import', 120000);
                if (!batch?.success) {
                    setStatus(batch?.error || 'Import batch failed.', true);
                    return null;
                }

                done = !!batch.done;
                lastMessage = batch.message || lastMessage;
                savedCount = Number(batch.saved_count ?? savedCount);
                setStatus(lastMessage);

                if (done && savedCount === 0 && total > 0) {
                    const errHint = Array.isArray(batch.errors) && batch.errors[0]
                        ? batch.errors[0]
                        : 'Products could not be saved.';
                    setStatus(lastMessage || errHint, true);
                    return null;
                }
            }

            return { success: true, message: lastMessage, saved_count: savedCount, total };
        } catch (err) {
            setStatus(err.message, true);
            return null;
        } finally {
            setBusy(false);
        }
    };

    previewBtn?.addEventListener('click', async (e) => {
        e.preventDefault();
        const data = await callPreview();
        if (!data) return;

        if (!data.success) {
            setStatus(data.message || data.error || 'Could not fetch products.', true);
            renderPreview([]);
            return;
        }

        setStatus(data.message || `Found ${data.total} products.`);
        if (data.needs_browser && data.indolj_browser) {
            setStatus(
                (data.message || `Found ${data.total} products.`)
                + ' Full import will load the complete menu from your browser.'
            );
        }
        renderPreview(data.sample || []);
    });

    importBtn?.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!confirm('This will replace your entire product catalog with items from this website. All existing products will be removed. Continue?')) {
            return;
        }

        const data = await runChunkedImport();
        if (!data?.success) return;

        setStatus(data.message || 'Import complete.');
        renderPreview([]);
        if (typeof BotSync !== 'undefined') {
            BotSync.notify('catalog:changed', parseInt(botId, 10) || 0);
        }
        const saved = Number(data.saved_count || 0);
        const reloadUrl = new URL(window.location.href);
        reloadUrl.searchParams.set('bot_id', botId || reloadUrl.searchParams.get('bot_id') || '');
        if (saved > 0) {
            reloadUrl.searchParams.set('imported', String(saved));
        }
        reloadUrl.hash = '';
        setTimeout(() => {
            window.location.href = reloadUrl.toString();
        }, 800);
    });

    clearBtn?.addEventListener('click', async (e) => {
        e.preventDefault();
        if (!confirm('Remove the linked website from Shop?')) {
            return;
        }
        const deleteProducts = confirm(
            'Also delete all products imported from this website?\n\nOK = delete imported products\nCancel = keep products, only remove the link'
        );

        setStatus('Removing website link…');
        setBusy(true, clearBtn, 'Removing…');

        try {
            const data = await postJson({ action: 'clear', delete_products: deleteProducts }, 'clear', 60000);
            if (!data || !data.success) {
                setStatus(data?.error || 'Could not remove website.', true);
                return;
            }

            setStatus(data.message || 'Website removed.');
            if (urlInput) urlInput.value = '';
            renderPreview([]);
            if (typeof BotSync !== 'undefined') {
                BotSync.notify('catalog:changed', parseInt(botId, 10) || 0);
            }
            setTimeout(() => window.location.reload(), 900);
        } catch (err) {
            setStatus(err.message || 'Could not remove website.', true);
        } finally {
            setBusy(false);
        }
    });
});
