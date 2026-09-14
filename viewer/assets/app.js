const defaultConfig = {
    mode: 'demo',
    demoDataUrl: './demo-data.json',
    apiBaseUrl: '',
    nonce: '',
    perPage: 8,
    priceLabels: {
        retail: 'Λιανική',
        wholesale: 'Χονδρική',
    },
};

const runtimeConfig = window.INVENTORY_VIEWER_CONFIG || {};
const CONFIG = Object.freeze({
    ...defaultConfig,
    ...runtimeConfig,
    priceLabels: Object.freeze({
        ...defaultConfig.priceLabels,
        ...(runtimeConfig.priceLabels || {}),
    }),
});

const elements = {
    categoryList: document.querySelector('#category-list'),
    clearCategory: document.querySelector('#clear-category'),
    searchInput: document.querySelector('#search-input'),
    resultStatus: document.querySelector('#result-status'),
    productGrid: document.querySelector('#product-grid'),
    emptyState: document.querySelector('#empty-state'),
    loadMore: document.querySelector('#load-more'),
    dialog: document.querySelector('#product-dialog'),
    dialogContent: document.querySelector('#dialog-content'),
    closeDialog: document.querySelector('#close-dialog'),
    productTemplate: document.querySelector('#product-card-template'),
};

const state = {
    search: '',
    category: 0,
    page: 1,
    totalPages: 1,
    requestId: 0,
    controller: null,
    dialogController: null,
    dialogRequestId: 0,
};

class DemoDataSource {
    #data = null;

    async #load() {
        if (this.#data) {
            return this.#data;
        }

        const response = await fetch(CONFIG.demoDataUrl);
        if (!response.ok) {
            throw new Error('Could not load demo data.');
        }

        this.#data = await response.json();
        return this.#data;
    }

    async getCategories() {
        return (await this.#load()).categories;
    }

    async getProducts({ search, category, page, perPage }) {
        const data = await this.#load();
        const term = search.toLocaleLowerCase('el');
        const allowedCategories = this.#getCategoryFamily(data.categories, category);

        const matches = data.products.filter((product) => {
            const matchesTerm = !term
                || product.name.toLocaleLowerCase('el').includes(term)
                || product.sku.toLocaleLowerCase('el').includes(term);
            const matchesCategory = category === 0
                || product.categoryIds.some((id) => allowedCategories.has(id));

            return matchesTerm && matchesCategory;
        });

        const start = (page - 1) * perPage;

        return {
            products: matches.slice(start, start + perPage),
            total: matches.length,
            total_pages: Math.max(1, Math.ceil(matches.length / perPage)),
            page,
            per_page: perPage,
        };
    }

    async getProduct(id) {
        const product = (await this.#load()).products.find((item) => item.id === id);
        if (!product) {
            throw new Error('Product not found.');
        }

        return product;
    }

    #getCategoryFamily(categories, categoryId) {
        const family = new Set(categoryId ? [categoryId] : []);
        let changed = true;

        while (changed) {
            changed = false;
            categories.forEach((category) => {
                if (family.has(category.parent) && !family.has(category.id)) {
                    family.add(category.id);
                    changed = true;
                }
            });
        }

        return family;
    }
}

class ApiDataSource {
    async getCategories({ signal } = {}) {
        return this.#request('/categories', { signal });
    }

    async getProducts({ search, category, page, perPage, signal }) {
        const parameters = new URLSearchParams({
            search,
            category: String(category),
            page: String(page),
            per_page: String(perPage),
        });

        return this.#request(`/products?${parameters}`, { signal });
    }

    async getProduct(id, { signal } = {}) {
        return this.#request(`/products/${encodeURIComponent(id)}`, { signal });
    }

    async #request(path, { signal } = {}) {
        if (!CONFIG.apiBaseUrl) {
            throw new Error('The API base URL is not configured.');
        }

        const headers = CONFIG.nonce ? { 'X-WP-Nonce': CONFIG.nonce } : {};
        const response = await fetch(`${CONFIG.apiBaseUrl}${path}`, {
            headers,
            credentials: 'same-origin',
            signal,
        });

        if (!response.ok) {
            throw new Error(`API request failed with status ${response.status}.`);
        }

        return response.json();
    }
}

const dataSource = CONFIG.mode === 'api' ? new ApiDataSource() : new DemoDataSource();

function debounce(callback, delay) {
    let timer;

    return (...args) => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => callback(...args), delay);
    };
}

function safeImageUrl(value) {
    try {
        const url = new URL(value || './assets/placeholder.svg', document.baseURI);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : './assets/placeholder.svg';
    } catch {
        return './assets/placeholder.svg';
    }
}

function formatPrice(amount, currency = 'EUR') {
    const numericAmount = Number(amount);
    if (!Number.isFinite(numericAmount)) {
        return '—';
    }

    return new Intl.NumberFormat('el-GR', {
        style: 'currency',
        currency,
    }).format(numericAmount);
}

function stockLabel(product) {
    if (product.stock_status === 'outofstock') {
        return 'Εξαντλημένο';
    }

    if (
        product.stock_quantity !== null
        && product.stock_quantity !== ''
        && Number.isFinite(Number(product.stock_quantity))
    ) {
        return `${product.stock_quantity} διαθέσιμα`;
    }

    return 'Διαθέσιμο';
}

function appendPrices(container, product) {
    Object.entries(CONFIG.priceLabels).forEach(([key, label]) => {
        if (!(key in product.prices)) {
            return;
        }

        const price = document.createElement('span');
        const priceLabel = document.createElement('span');
        const priceValue = document.createElement('span');

        priceLabel.textContent = `${label}: `;
        priceValue.className = 'price-value';
        priceValue.textContent = formatPrice(product.prices[key], product.currency);
        price.append(priceLabel, priceValue);
        container.append(price);
    });
}

function createProductCard(product) {
    const fragment = elements.productTemplate.content.cloneNode(true);
    const button = fragment.querySelector('.product-open');
    const image = fragment.querySelector('.product-image');
    const meta = fragment.querySelector('.product-meta');
    const name = fragment.querySelector('.product-name');
    const stock = fragment.querySelector('.product-stock');
    const prices = fragment.querySelector('.product-prices');

    image.src = safeImageUrl(product.image);
    image.alt = product.name;
    meta.textContent = product.sku || 'Χωρίς SKU';
    name.textContent = product.name;
    stock.textContent = stockLabel(product);
    stock.dataset.status = product.stock_status;
    appendPrices(prices, product);
    button.setAttribute('aria-label', `Προβολή ${product.name}`);
    button.addEventListener('click', () => openProduct(product.id));

    return fragment;
}

function renderProducts(products, append = false) {
    if (!append) {
        elements.productGrid.replaceChildren();
    }

    const fragment = document.createDocumentFragment();
    products.forEach((product) => fragment.append(createProductCard(product)));
    elements.productGrid.append(fragment);
}

function buildCategoryTree(categories, parentId = 0) {
    const container = document.createElement('div');
    const children = categories.filter((category) => category.parent === parentId);

    children.forEach((category) => {
        const item = document.createElement('div');
        const button = document.createElement('button');
        const label = document.createElement('span');
        const count = document.createElement('span');

        button.type = 'button';
        button.className = 'category-button';
        button.dataset.categoryId = String(category.id);
        button.setAttribute('aria-current', String(state.category === category.id));
        label.textContent = category.name;
        count.className = 'category-count';
        count.textContent = String(category.count);
        button.append(label, count);
        button.addEventListener('click', () => selectCategory(category.id));
        item.append(button);

        const nested = buildCategoryTree(categories, category.id);
        if (nested.childElementCount > 0) {
            nested.className = 'category-children';
            item.append(nested);
        }

        container.append(item);
    });

    return container;
}

async function loadCategories() {
    const categories = await dataSource.getCategories();
    elements.categoryList.replaceChildren(buildCategoryTree(categories));
}

function updateSelectedCategory() {
    elements.categoryList.querySelectorAll('.category-button').forEach((button) => {
        const isCurrent = Number(button.dataset.categoryId) === state.category;
        button.setAttribute('aria-current', String(isCurrent));
    });
    elements.clearCategory.hidden = state.category === 0;
}

async function selectCategory(categoryId) {
    state.category = categoryId;
    state.page = 1;
    updateSelectedCategory();
    await loadProducts({ append: false });
}

async function loadProducts({ append }) {
    state.controller?.abort();
    state.controller = new AbortController();
    const requestId = ++state.requestId;

    elements.resultStatus.textContent = 'Φόρτωση…';
    elements.loadMore.disabled = true;

    try {
        const result = await dataSource.getProducts({
            search: state.search,
            category: state.category,
            page: state.page,
            perPage: CONFIG.perPage,
            signal: state.controller.signal,
        });

        if (requestId !== state.requestId) {
            return;
        }

        state.totalPages = result.total_pages;
        renderProducts(result.products, append);
        elements.emptyState.hidden = result.total > 0;
        elements.resultStatus.textContent = `${result.total} προϊόντα`;
        elements.loadMore.hidden = state.page >= state.totalPages;
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        elements.resultStatus.textContent = 'Αδυναμία φόρτωσης.';
        elements.emptyState.hidden = false;
        console.error(error);
    } finally {
        if (requestId === state.requestId) {
            elements.loadMore.disabled = false;
        }
    }
}

async function openProduct(productId) {
    state.dialogController?.abort();
    state.dialogController = new AbortController();
    const requestId = ++state.dialogRequestId;
    const loading = document.createElement('h2');
    loading.id = 'dialog-title';
    loading.textContent = 'Φόρτωση προϊόντος…';
    elements.dialogContent.replaceChildren(loading);
    elements.dialog.showModal();

    try {
        const product = await dataSource.getProduct(productId, {
            signal: state.dialogController.signal,
        });

        if (requestId !== state.dialogRequestId) {
            return;
        }

        const layout = document.createElement('div');
        const image = document.createElement('img');
        const details = document.createElement('div');
        const title = document.createElement('h2');
        const sku = document.createElement('p');
        const stock = document.createElement('p');
        const prices = document.createElement('div');
        const description = document.createElement('p');

        layout.className = 'dialog-layout';
        image.className = 'dialog-image';
        image.src = safeImageUrl(product.images?.[0] || product.image);
        image.alt = product.name;
        title.id = 'dialog-title';
        title.textContent = product.name;
        sku.textContent = `SKU: ${product.sku || '—'}`;
        stock.textContent = stockLabel(product);
        prices.className = 'product-prices';
        appendPrices(prices, product);
        description.className = 'dialog-description';
        description.textContent = product.description || 'Δεν υπάρχει διαθέσιμη περιγραφή.';

        details.append(title, sku, stock, prices, description);
        layout.append(image, details);
        elements.dialogContent.append(layout);
    } catch (error) {
        if (error.name === 'AbortError') {
            return;
        }

        const message = document.createElement('p');
        message.textContent = 'Δεν ήταν δυνατή η φόρτωση του προϊόντος.';
        elements.dialogContent.append(message);
        console.error(error);
    }
}

elements.searchInput.addEventListener('input', debounce(async (event) => {
    state.search = event.target.value.trim();
    state.page = 1;
    await loadProducts({ append: false });
}, 250));

elements.clearCategory.addEventListener('click', () => selectCategory(0));

elements.loadMore.addEventListener('click', async () => {
    if (state.page < state.totalPages) {
        state.page += 1;
        await loadProducts({ append: true });
    }
});

elements.closeDialog.addEventListener('click', () => {
    state.dialogController?.abort();
    elements.dialog.close();
});
elements.dialog.addEventListener('click', (event) => {
    if (event.target === elements.dialog) {
        state.dialogController?.abort();
        elements.dialog.close();
    }
});
elements.dialog.addEventListener('close', () => state.dialogController?.abort());

async function init() {
    try {
        await Promise.all([loadCategories(), loadProducts({ append: false })]);
    } catch (error) {
        elements.resultStatus.textContent = 'Το demo δεν ήταν δυνατό να φορτωθεί.';
        console.error(error);
    }
}

init();
