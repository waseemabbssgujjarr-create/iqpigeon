(function () {
    const form = document.getElementById('catalog-add-product-form');
    const submitBtn = document.getElementById('catalog-add-product-btn');
    if (!form || !submitBtn) {
        return;
    }

    const statusEl = document.getElementById('catalog-add-product-status');
    const gridEl = document.querySelector('.iqp-product-grid');
    const defaultLabel = submitBtn.dataset.defaultLabel || 'Add product';
    const apiUrl = form.getAttribute('action') || '/api/catalog-product.php';
    const editingId = parseInt(form.querySelector('[name="product_id"]')?.value || '0', 10) || 0;

    function readCsrfToken() {
        return form.querySelector('[name="csrf_token"]')?.value || form.dataset.csrf || '';
    }

    function readBotId() {
        const pageSelect = document.querySelector('form[method="get"] select[name="bot_id"]');
        return pageSelect?.value || form.querySelector('[name="bot_id"]')?.value || '';
    }

    function setStatus(message, type) {
        if (!statusEl) return;
        statusEl.textContent = message;
        statusEl.classList.remove('hidden', 'text-[#1FA855]', 'text-red-500', 'text-slate-500');
        if (!message) {
            statusEl.classList.add('hidden');
            return;
        }
        if (type === 'error') {
            statusEl.classList.add('text-red-500');
        } else if (type === 'success') {
            statusEl.classList.add('text-[#1FA855]');
        } else {
            statusEl.classList.add('text-slate-500');
        }
    }

    function setProcessing(isProcessing) {
        submitBtn.disabled = isProcessing;
        submitBtn.classList.toggle('opacity-70', isProcessing);
        submitBtn.classList.toggle('cursor-wait', isProcessing);
        submitBtn.textContent = isProcessing ? 'Saving…' : defaultLabel;
    }

    async function prepareProductImage(file) {
        if (!file || !String(file.type || '').startsWith('image/') || file.size <= 120 * 1024) {
            return file;
        }
        return new Promise((resolve) => {
            const img = new Image();
            const objectUrl = URL.createObjectURL(file);
            img.onload = () => {
                URL.revokeObjectURL(objectUrl);
                let width = img.naturalWidth || img.width;
                let height = img.naturalHeight || img.height;
                if (!width || !height) {
                    resolve(file);
                    return;
                }
                const maxDim = 1600;
                if (width > maxDim || height > maxDim) {
                    const scale = maxDim / Math.max(width, height);
                    width = Math.max(1, Math.round(width * scale));
                    height = Math.max(1, Math.round(height * scale));
                }
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    resolve(file);
                    return;
                }
                ctx.drawImage(img, 0, 0, width, height);
                canvas.toBlob((blob) => {
                    if (!blob) {
                        resolve(file);
                        return;
                    }
                    resolve(new File([blob], 'product.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                }, 'image/jpeg', 0.86);
            };
            img.onerror = () => {
                URL.revokeObjectURL(objectUrl);
                resolve(file);
            };
            img.src = objectUrl;
        });
    }

    function upsertProductCardImage(mediaEl, imageUrl, name) {
        if (!mediaEl || !imageUrl) return;
        let img = mediaEl.querySelector('img');
        const placeholder = mediaEl.querySelector('.iqp-product-card__placeholder');
        if (!img) {
            img = document.createElement('img');
            img.loading = 'lazy';
            mediaEl.insertBefore(img, mediaEl.firstChild);
        }
        img.src = imageUrl;
        img.alt = name || '';
        placeholder?.remove();
    }

    function updateProductCard(product) {
        const id = Number(product.id) || 0;
        const card = document.querySelector(`.iqp-product-card[data-product-id="${id}"]`);
        if (!card) return false;

        const title = card.querySelector('.iqp-product-card__title');
        const price = card.querySelector('.iqp-product-card__price');
        const desc = card.querySelector('.iqp-product-card__desc');
        const media = card.querySelector('.iqp-product-card__media');

        if (title) title.textContent = product.name || '';
        if (price) price.textContent = product.price_label || '';
        if (product.description) {
            if (desc) {
                desc.textContent = product.description;
            } else if (title?.parentElement) {
                const d = document.createElement('div');
                d.className = 'iqp-product-card__desc';
                d.textContent = product.description;
                title.after(d);
            }
        }
        if (product.image_url && media) {
            upsertProductCardImage(media, product.image_url, product.name);
        }

        card.classList.add('ring-2', 'ring-[#1FA855]', 'ring-offset-1');
        window.setTimeout(() => card.classList.remove('ring-2', 'ring-[#1FA855]', 'ring-offset-1'), 1800);
        return true;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const csrfToken = readCsrfToken();
        const activeBotId = readBotId();
        const productId = parseInt(form.querySelector('[name="product_id"]')?.value || '0', 10) || 0;
        const isEdit = productId > 0;

        if (!csrfToken) {
            setStatus('Session expired — refresh the page and try again.', 'error');
            return;
        }
        if (!activeBotId) {
            setStatus('No bot selected. Refresh the page.', 'error');
            return;
        }

        const nameInput = form.querySelector('[name="name"], [name="product_name"]');
        const nameValue = String(nameInput?.value || '').trim();
        if (!nameValue) {
            setStatus('Product name is required.', 'error');
            nameInput?.focus();
            return;
        }

        const body = new FormData(form);
        body.set('action', isEdit ? 'edit_product' : 'add_product');
        body.set('csrf_token', csrfToken);
        body.set('bot_id', activeBotId);
        body.set('name', nameValue);
        if (isEdit) {
            body.set('product_id', String(productId));
        }

        setStatus(isEdit ? 'Saving changes…' : 'Uploading…', 'pending');
        setProcessing(true);

        try {
            const fileInput = form.querySelector('[name="product_image"]');
            const pickedFile = fileInput?.files?.[0];
            if (pickedFile) {
                if (pickedFile.size > 12 * 1024 * 1024) {
                    throw new Error('Image is too large (max 12MB).');
                }
                const prepared = await prepareProductImage(pickedFile);
                body.set('product_image', prepared, prepared.name || 'product.jpg');
            }

            const response = await fetch(apiUrl, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            let data = {};
            try {
                data = await response.json();
            } catch (e) {
                throw new Error('Unexpected server response. Try again.');
            }

            if (!response.ok || !data.success) {
                throw new Error(data.error || (isEdit ? 'Could not update product.' : 'Could not add product.'));
            }

            if (isEdit) {
                const updated = updateProductCard(data.product);
                if (!updated) {
                    window.location.href = `/client/catalog?bot_id=${encodeURIComponent(activeBotId)}`;
                    return;
                }
                const urlInput = document.getElementById('iqpImageUrl');
                if (urlInput && data.product?.image_url) {
                    urlInput.value = data.product.image_url;
                    const thumb = document.getElementById('iqpImageThumb');
                    if (thumb) thumb.src = data.product.image_url;
                }
                if (window.history.replaceState) {
                    window.history.replaceState({}, '', `/client/catalog?bot_id=${encodeURIComponent(activeBotId)}#add-product`);
                }
            } else {
                form.reset();
                const activeCheckbox = form.querySelector('[name="is_active"]');
                if (activeCheckbox) activeCheckbox.checked = true;
                window.location.href = `/client/catalog?bot_id=${encodeURIComponent(activeBotId)}&added=1`;
                return;
            }

            if (typeof BotSync !== 'undefined') {
                BotSync.publish('catalog:changed', {
                    bot_id: activeBotId,
                    context: data.context || null,
                });
            }

            setStatus(data.message || (isEdit ? 'Product updated.' : 'Product added.'), 'success');
            window.setTimeout(() => setStatus('', ''), 5000);
        } catch (error) {
            setStatus(error.message || 'Save failed.', 'error');
        } finally {
            setProcessing(false);
        }
    });
})();
