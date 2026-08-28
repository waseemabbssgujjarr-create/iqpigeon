/**
 * Hero iPhone WhatsApp demo — realistic UI, typing, product photos + prices.
 */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('hero-wa-demo');
    const chat = document.getElementById('hero-wa-chat');
    if (!root || !chat) return;

    const products = [
        {
            name: 'Abis 50ml — Creed Silver Mountain Water',
            price: 'From PKR 748',
            desc: 'Fresh alpine scent inspired by Creed. Ideal for sports & daily wear.',
            image: 'https://cdn.shopify.com/s/files/1/0610/8119/0571/files/spunk-nearest-match-to-lacoste-white-1837949.jpg?v=1771581136',
        },
        {
            name: 'Noor 50ml — Valaya by Parfums de Marly',
            price: 'PKR 1,016',
            desc: 'Elegant floral fragrance for her. Long-lasting & premium feel.',
            image: 'https://cdn.shopify.com/s/files/1/0610/8119/0571/files/noor-for-her-50ml-nearest-match-to-valaya-by-parfums-de-marly-6901808.jpg?v=1771581441',
        },
        {
            name: 'Velvet 50ml — J\'adore Dior Match',
            price: 'PKR 902',
            desc: 'Soft feminine floral. Perfect for everyday elegance.',
            image: 'https://cdn.shopify.com/s/files/1/0610/8119/0571/files/velvet-50ml-nearest-match-to-jadore-dior-9025845.jpg?v=1771581312',
        },
    ];

    const fallbackImages = [
        'https://images.unsplash.com/photo-1541643600914-78b084683601?w=400&h=400&fit=crop',
        'https://images.unsplash.com/photo-1594035910387-8251a086d06d?w=400&h=400&fit=crop',
        'https://images.unsplash.com/photo-1592945403244-b31aa8883f66?w=400&h=400&fit=crop',
    ];

    products.forEach((p, i) => {
        p.imageFallback = fallbackImages[i];
    });

    const script = [
        { type: 'in', text: 'Hi, koi perfume recommend karo with pics' },
        { type: 'typing', ms: 1600 },
        { type: 'out', text: 'Sure! Here are 3 bestsellers with photos & prices 👇' },
        { type: 'product', index: 0 },
        { type: 'pause', ms: 700 },
        { type: 'product', index: 1 },
        { type: 'pause', ms: 700 },
        { type: 'product', index: 2 },
        { type: 'pause', ms: 2400 },
        { type: 'in', text: 'Noor wala 50ml available hai?' },
        { type: 'typing', ms: 1300 },
        { type: 'out', text: 'Haan bilkul! Noor 50ml in stock hai — type add 2 to cart 🛒' },
        { type: 'pause', ms: 3200 },
    ];

    const wait = (ms) => new Promise((r) => setTimeout(r, ms));

    const nowTime = () => {
        const d = new Date();
        let h = d.getHours();
        const m = d.getMinutes().toString().padStart(2, '0');
        const ampm = h >= 12 ? 'pm' : 'am';
        h = h % 12 || 12;
        return `${h}:${m} ${ampm}`;
    };

    const scrollChat = () => {
        requestAnimationFrame(() => {
            chat.scrollTop = chat.scrollHeight;
        });
    };

    const esc = (s) => {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    };

    const addTextMsg = (side, text) => {
        const wrap = document.createElement('div');
        wrap.className = `wa-msg wa-msg--${side}`;
        wrap.innerHTML = `
            <div class="wa-msg__bubble">${esc(text)}</div>
            <span class="wa-msg__time">${nowTime()}${side === 'out' ? ' <span class="wa-msg__ticks">✓✓</span>' : ''}</span>
        `;
        chat.appendChild(wrap);
        scrollChat();
    };

    const addTyping = () => {
        const el = document.createElement('div');
        el.className = 'wa-typing';
        el.innerHTML = '<span></span><span></span><span></span>';
        chat.appendChild(el);
        scrollChat();
        return el;
    };

    const addProductMsg = (product) => {
        const wrap = document.createElement('div');
        wrap.className = 'wa-msg wa-msg--out';
        wrap.style.maxWidth = '88%';

        const img = document.createElement('img');
        img.className = 'wa-msg__product-img';
        img.alt = product.name;
        img.loading = 'lazy';
        img.src = product.image;
        img.onerror = () => {
            if (product.imageFallback) img.src = product.imageFallback;
        };

        wrap.innerHTML = `
            <div class="wa-msg__product">
                <div class="wa-msg__product-img-wrap"></div>
                <div class="wa-msg__product-body">
                    <div class="wa-msg__product-name">${esc(product.name)}</div>
                    <div class="wa-msg__product-price">${esc(product.price)}</div>
                    <div class="wa-msg__product-desc">${esc(product.desc)}</div>
                    <div class="wa-msg__product-cta">Add to cart</div>
                </div>
                <div class="wa-msg__product-footer">${nowTime()} <span class="wa-msg__ticks">✓✓</span></div>
            </div>
        `;

        wrap.querySelector('.wa-msg__product-img-wrap').appendChild(img);
        chat.appendChild(wrap);
        scrollChat();
    };

    const runScript = async () => {
        chat.innerHTML = '';

        for (const step of script) {
            if (step.type === 'in' || step.type === 'out') {
                addTextMsg(step.type, step.text);
                await wait(450);
            } else if (step.type === 'typing') {
                const typing = addTyping();
                await wait(step.ms || 1200);
                typing.remove();
            } else if (step.type === 'product') {
                const p = products[step.index];
                if (p) addProductMsg(p);
                await wait(550);
            } else if (step.type === 'pause') {
                await wait(step.ms || 1000);
            }
        }

        await wait(4000);
        runScript();
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                observer.disconnect();
                runScript();
            }
        });
    }, { threshold: 0.2 });

    observer.observe(root);
});
